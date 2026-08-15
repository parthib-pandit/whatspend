<?php

namespace App\Services;

use App\Ai\Agents\TransactionActionParserAgent;
use Illuminate\Support\Facades\Log;

class TransactionActionParser
{
    /**
     * Same rethrow-not-swallow contract as the other parsers — a transient
     * LLM failure must not look identical to "this wasn't an edit/delete
     * request."
     *
     * @throws \Throwable
     */
    public function parse(string $message): array
    {
        try {
            $response = (new TransactionActionParserAgent)->prompt($message);
        } catch (\Throwable $e) {
            Log::warning('TransactionActionParser: LLM call failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return [
            'recognized' => $response['recognized'],
            'action' => $response['action'] ?? null,
            'new_amount' => $response['new_amount'] ?? null,
            'new_category' => $response['new_category'] ?? null,
            'target_scope' => $response['target_scope'] ?? null,
            'search_amount' => $response['search_amount'] ?? null,
            'search_category' => $response['search_category'] ?? null,
            'search_date' => $response['search_date'] ?? null,
            'confidence' => $response['confidence'],
        ];
    }
}