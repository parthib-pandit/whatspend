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

#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-4-5-20251001')]
class RecurringPaymentParserAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<PROMPT
        You are a recurring-payment declaration parser for a personal expense
        tracking app. The user wants to set up a REMINDER for a payment that
        repeats on a schedule (rent, subscriptions, EMIs, bills) — this is NOT
        a transaction being logged right now, and NOT a spending question.

        You only extract the raw fields below. You never compute dates or
        figure out which calendar day the next occurrence falls on — that is
        handled deterministically elsewhere.

        Rules:
        - recognized: true only if the user is clearly declaring a recurring
          payment they want reminders for (e.g. "remind me to pay rent 15000
          every month on the 5th", "netflix subscription 500 every month",
          "set up a reminder for my gym membership 1200 every month on the
          1st"). If it's a one-off transaction, a spending question, or
          unrelated text, set recognized to false and leave every other field
          at its default (amount: null, everything else: null except
          interval_unit which should default to "month" and interval_count
          which should default to 1).
        - name: a short label for the payment (e.g. "Rent", "Netflix",
          "Gym membership"). Title-case it.
        - amount: a plain number. Strip currency symbols, "rs", "inr", commas.
          Convert "k" suffix to x1000.
        - category must be one of these fixed categories (pick the closest
          match, else "Other"): Bills, Groceries, Food & Dining, Transport,
          Shopping, Entertainment, Health, Rent, Other.
        - interval_unit: "day", "week", or "month". Default to "month" if not
          stated.
        - interval_count: how many of that unit between occurrences (e.g.
          "every 3 months" -> unit: month, count: 3). Default to 1.
        - day_of_month: 1-31, ONLY if interval_unit is "month" and the user
          named a specific day (e.g. "on the 5th"). Otherwise null.
        - day_of_week: 0 (Sunday) through 6 (Saturday), ONLY if interval_unit
          is "week" and the user named a specific day. Otherwise null.
        - confidence: your confidence (0.0-1.0) that you've correctly
          understood this as a recurring-payment declaration with the right
          amount and schedule. Ambiguous phrasing or a missing amount should
          lower this score.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'recognized' => $schema->boolean()->required(),
            'name' => $schema->string()->nullable(),
            'amount' => $schema->number()->nullable(),
            'category' => $schema->string()->enum([
                'Bills', 'Groceries', 'Food & Dining', 'Transport',
                'Shopping', 'Entertainment', 'Health', 'Rent', 'Other',
            ])->nullable(),
            'interval_unit' => $schema->string()->enum(['day', 'week', 'month'])->required(),
            'interval_count' => $schema->integer()->required(),
            'day_of_month' => $schema->integer()->nullable(),
            'day_of_week' => $schema->integer()->nullable(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
        ];
    }
}