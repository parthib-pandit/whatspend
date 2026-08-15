<?php

namespace App\Services;

use App\Ai\Agents\QueryIntentParserAgent;
use Illuminate\Support\Facades\Log;

class QueryIntentParser
{
    /**
     * @throws \Throwable
     */
    public function parse(string $message): array
    {
        try {
            $response = (new QueryIntentParserAgent)->prompt($message);
        } catch (\Throwable $e) {
            Log::warning('QueryIntentParser: LLM call failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return [
            'recognized' => $response['recognized'],
            'type' => $response['type'] ?? null,
            'category' => $response['category'] ?? null,
            'start_date' => $response['start_date'] ?? null,
            'end_date' => $response['end_date'] ?? null,
            'min_amount' => $response['min_amount'] ?? null,
            'max_amount' => $response['max_amount'] ?? null,
            'is_comparison' => $response['is_comparison'] ?? false,
            'compare_start_date' => $response['compare_start_date'] ?? null,
            'compare_end_date' => $response['compare_end_date'] ?? null,
            'confidence' => $response['confidence'],
        ];
    }
}