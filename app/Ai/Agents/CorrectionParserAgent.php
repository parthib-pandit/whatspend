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
class CorrectionParserAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<PROMPT
        You are interpreting a WhatsApp reply to a pending transaction confirmation.

        The user was previously asked to confirm a parsed transaction. Their reply is one of:
        - A confirmation ("yes", "yep", "correct", "y")
        - A rejection ("no", "nah", "cancel", "wrong")
        - A correction to the amount and/or category, which may implicitly confirm the rest
          (e.g. "no it's groceries" = correct the category, action is "correct")
          (e.g. "actually 420" = correct the amount, action is "correct")

        Rules:
        - action must be one of: "confirm", "reject", "correct"
        - If action is "correct", set whichever of amount/category the user is changing;
          leave the other null if they didn't mention it.
        - category must be one of these fixed lists (pick the closest match, else "Other"):
          Debit categories: Bills, Groceries, Food & Dining, Transport, Shopping, Entertainment, Health, Rent, Other
          Credit categories: Salary, Freelance, Refund, Gift, Other
        - confidence: your confidence (0.0-1.0) that you've correctly interpreted the reply.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['confirm', 'reject', 'correct'])->required(),
            'amount' => $schema->number()->nullable(),
            'category' => $schema->string()->nullable(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
        ];
    }
}