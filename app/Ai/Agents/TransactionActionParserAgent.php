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
class TransactionActionParserAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        $today = Carbon::now()->format('Y-m-d');
        $dayName = Carbon::now()->format('l');

        return <<<PROMPT
        You detect requests to EDIT or DELETE an already-logged transaction on a
        personal expense tracking app. You never create new transactions and you
        never compute anything — you only extract what the user wants changed and
        which transaction(s) they mean. A separate deterministic system locates the
        actual transaction and performs the change.

        Today's date is: {$today} ({$dayName}).

        Rules:
        - recognized: true only if this message is clearly asking to edit or delete
          an EXISTING transaction (e.g. "actually make that ₹850", "delete the last
          transaction", "delete the ₹500 grocery one from Tuesday", "change that to
          Transport"). If it's a new transaction, a query, a greeting, or unrelated
          text, set recognized to false and leave every other field null.
        - action: "delete" or "edit". Null only if unrecognized.
        - new_amount: the corrected amount, only when action is "edit" and an amount
          is being changed. Otherwise null.
        - new_category: the corrected category (must be one of: Bills, Groceries,
          Food & Dining, Transport, Shopping, Entertainment, Health, Rent, Salary,
          Freelance, Refund, Gift, Other), only when action is "edit" and a category
          is being changed. Otherwise null.
        - target_scope: "last" if the message refers to the transaction implicitly
          ("that", "it", "the last one", no other identifying detail given). "search"
          if the message gives identifying details to find a specific past
          transaction (an amount, category, and/or date that isn't necessarily the
          most recent transaction).
        - search_amount / search_category / search_date: only set when target_scope
          is "search" — whatever identifying details were given, to help locate the
          right transaction. search_date should resolve relative dates ("Tuesday",
          "yesterday") to YYYY-MM-DD using today's date. Leave any of these null if
          that detail wasn't given, even in search mode.
        - confidence: your confidence (0.0-1.0) that you've correctly understood the
          request. Lower this for vague or ambiguous phrasing.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'recognized' => $schema->boolean()->required(),
            'action' => $schema->string()->enum(['delete', 'edit'])->nullable(),
            'new_amount' => $schema->number()->nullable(),
            'new_category' => $schema->string()->nullable(),
            'target_scope' => $schema->string()->enum(['last', 'search'])->nullable(),
            'search_amount' => $schema->number()->nullable(),
            'search_category' => $schema->string()->nullable(),
            'search_date' => $schema->string()->nullable(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
        ];
    }
}