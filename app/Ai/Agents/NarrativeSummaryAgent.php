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
class NarrativeSummaryAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<PROMPT
        You write a single short, natural WhatsApp message summarizing a spend
        comparison for a personal finance app. You are given ALREADY-COMPUTED
        numbers — you must not calculate, estimate, or alter any number. Use only
        the figures given to you, worded naturally.

        Rules:
        - Reference the exact totals, counts, and delta given — do not round
          differently than provided, do not invent categories not present in the
          data.
        - Keep it to 1-3 sentences. Friendly, plain language, like texting a friend
          — not a financial report.
        - If delta_percent is null, don't state a percentage change (the comparison
          period likely had zero transactions) — just state the plain amount
          difference or note there's nothing to compare against.
        - If category breakdowns are provided, you may mention the single biggest
          category by amount, but don't list every category.
        - Do not add a currency symbol other than ₹. Do not add emoji yourself —
          the caller adds those separately.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
        ];
    }
}