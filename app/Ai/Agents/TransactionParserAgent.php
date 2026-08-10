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
class TransactionParserAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        $today = Carbon::now()->format('Y-m-d');

        return <<<PROMPT
        You are a financial transaction parser for a personal expense tracking app.

        Today's date is: {$today}

        Parse the user's WhatsApp message into a transaction.

        Rules:
        - Amount is a plain number. Strip currency symbols, "rs", "inr", commas. Convert "k" suffix to x1000 (e.g. "2.5k" -> 2500).
        - If it's unclear whether money is coming in or going out, set type to "unknown" and amount to null.
        - category must be one of these fixed lists (pick the closest match, else "Other"):
          - Debit categories: Bills, Groceries, Food & Dining, Transport, Shopping, Entertainment, Health, Rent, Other
          - Credit categories: Salary, Freelance, Refund, Gift, Other
        - note: a short, cleaned-up description of what the transaction was for.
        - transaction_date: default to today's date unless the message explicitly references a different date (e.g. "yesterday", "on the 3rd"). Always format as YYYY-MM-DD.
        - confidence: your own confidence (0.0-1.0) that you've correctly extracted type, amount, and category. Ambiguous phrasing, missing amounts, or unclear intent should lower this score.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(['credit', 'debit', 'unknown'])->required(),
            'amount' => $schema->number()->nullable(),
            'category' => $schema->string()->nullable(),
            'note' => $schema->string()->required(),
            'transaction_date' => $schema->string()->required(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
        ];
    }
}