<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;
use Carbon\Carbon;

#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-4-5-20251001')]
class QueryIntentParserAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        $today = Carbon::now()->format('Y-m-d');
        $dayName = Carbon::now()->format('l');

        return <<<PROMPT
        You are a query-intent parser for a personal expense tracking app. The user is
        asking a QUESTION about their past transactions (not logging a new one). Turn
        their message into a structured filter — you never compute totals or numbers
        yourself, you only extract what to filter on. A separate deterministic system
        runs the actual query.

        Today's date is: {$today} ({$dayName}).

        Rules:
        - recognized: true only if this message is genuinely asking about existing
          spend/income data (e.g. "how much on food this month", "show me transport
          expenses", "what did I spend last week", "list everything above 1000",
          "compare this month with last month", "what was my last transaction",
          "what are my recurring expenses"). If it's ambiguous, a new transaction, a
          greeting, or unrelated text, set recognized to false and leave every other
          field null (except query_type, which should still be "aggregate").
        - query_type: "last_transaction" if the user is asking about ONE specific
          transaction — their single most recent one (e.g. "what was my last
          transaction", "what did I just buy", "show me my most recent purchase",
          "what's the latest thing I logged"). "recurring" if the user is asking
          about recurring/repeating/subscription-like expenses (e.g. "what are my
          recurring expenses", "do I have any subscriptions", "what am I paying for
          every month", "show me repeating charges"). "aggregate" for anything asking
          about totals, sums, or multiple transactions over a period (e.g. "how much
          on food this month", "list everything above 1000"). Default to "aggregate"
          when in doubt. Required even when recognized is false (use "aggregate").
        - type: "debit" if asking about spending/expenses, "credit" if asking about
          income, "both" if asking about both or unspecified. Null only if unrecognized
          or query_type is "recurring".
        - category: one of the fixed list below (closest match), or null if no category
          was mentioned or filtered on.
          Debit categories: Bills, Groceries, Food & Dining, Transport, Shopping,
          Entertainment, Health, Rent, Other
          Credit categories: Salary, Freelance, Refund, Gift, Other
        - start_date / end_date: resolve relative phrases ("this month", "last week",
          "today", "yesterday", "this year") into concrete YYYY-MM-DD bounds using
          today's date. "This month" = 1st of current month to today. "Last week" =
          the 7 days before today. If no time period is mentioned, leave both null
          (meaning: all time). When is_comparison is true, this is the PRIMARY
          (more recent / first-named) period. Leave both null when query_type is
          "last_transaction" or "recurring".
        - min_amount / max_amount: only set when the user gives an explicit threshold
          (e.g. "above 1000" -> min_amount 1000, "under 500" -> max_amount 500,
          "between 200 and 800" -> both). Otherwise null.
        - is_comparison: true if the user is explicitly asking to compare two time
          periods against each other (e.g. "compare this month with last month",
          "how does this week stack up against last week", "did I spend more in July
          than June"). False for a plain single-period question, and always false
          when query_type is "last_transaction" or "recurring".
        - compare_start_date / compare_end_date: only set when is_comparison is true —
          the SECOND (earlier / second-named) period being compared against, resolved
          the same way as start_date/end_date. Null when is_comparison is false.
        - confidence: your confidence (0.0-1.0) that you've correctly understood what
          they're asking for. Lower this for vague or oddly phrased queries.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'recognized' => $schema->boolean()->required(),
            'query_type' => $schema->string()->enum(['aggregate', 'last_transaction', 'recurring'])->required(),
            'type' => $schema->string()->enum(['credit', 'debit', 'both'])->nullable(),
            'category' => $schema->string()->nullable(),
            'start_date' => $schema->string()->nullable(),
            'end_date' => $schema->string()->nullable(),
            'min_amount' => $schema->number()->nullable(),
            'max_amount' => $schema->number()->nullable(),
            'is_comparison' => $schema->boolean()->required(),
            'compare_start_date' => $schema->string()->nullable(),
            'compare_end_date' => $schema->string()->nullable(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
        ];
    }
}