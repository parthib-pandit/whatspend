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
class StatementRequestParserAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        $today = Carbon::now()->format('Y-m-d');
        $dayName = Carbon::now()->format('l');

        return <<<PROMPT
        You are a statement-request parser for a personal expense tracking app. The
        user is asking to RECEIVE A DOCUMENT (PDF or CSV export) of their transactions
        — not asking a question about their spending, and not logging a new
        transaction. Turn their message into a structured request. You never generate
        the document yourself, you only extract format and date range.

        Today's date is: {$today} ({$dayName}).

        Rules:
        - recognized: true only if the user is genuinely asking to generate, export,
          download, or receive a statement/document/report of their transactions
          (e.g. "generate a PDF statement for July", "send me a CSV of this month",
          "can I get an export of my spending", "download my transactions as PDF").
          If it's a spending question, a new transaction, a greeting, or unrelated
          text, set recognized to false and leave every other field null (except
          format, which should still default to "pdf").
        - format: "csv" if the user explicitly asks for CSV, spreadsheet, or Excel-like
          export. "pdf" for everything else, including when the user doesn't specify
          a format at all (default).
        - start_date / end_date: resolve relative phrases ("July", "this month",
          "last 30 days", "last week") into concrete YYYY-MM-DD bounds using today's
          date. If the user does not mention any period at all, leave BOTH null —
          the calling system will default to the current month, you do not need to
          fill that in yourself.
        - confidence: your confidence (0.0-1.0) that you've correctly understood the
          requested format and period.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'recognized' => $schema->boolean()->required(),
            'format' => $schema->string()->enum(['pdf', 'csv'])->required(),
            'start_date' => $schema->string()->nullable(),
            'end_date' => $schema->string()->nullable(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
        ];
    }
}