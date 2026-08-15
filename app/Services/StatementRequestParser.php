<?php

namespace App\Services;

use App\Ai\Agents\StatementRequestParserAgent;
use Illuminate\Support\Facades\Log;

class StatementRequestParser
{
    /**
     * @throws \Throwable
     */
    public function parse(string $message): array
    {
        try {
            $response = (new StatementRequestParserAgent)->prompt($message);
        } catch (\Throwable $e) {
            Log::warning('StatementRequestParser: LLM call failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return [
            'recognized' => $response['recognized'],
            'format' => $response['format'] ?? 'pdf',
            'start_date' => $response['start_date'] ?? null,
            'end_date' => $response['end_date'] ?? null,
            'confidence' => $response['confidence'],
        ];
    }
}