<?php

namespace App\Services;

use App\Ai\Agents\TransactionParserAgent;
use Illuminate\Support\Facades\Log;

class TransactionParser
{
    /**
     * Parse a raw WhatsApp message into structured transaction data.
     *
     * IMPORTANT: this method deliberately does NOT catch-and-return-a-fallback
     * on failure. If the underlying LLM call throws (timeout, API error,
     * malformed response, etc.), that exception is logged here for
     * visibility and then rethrown so ParseTransactionMessage's retry/backoff
     * logic actually gets a chance to run. If we swallowed it here, every
     * transient API failure would look identical to a user sending
     * unparseable text ("type": "unknown") — no retry, wrong user-facing
     * message, and no way to tell the two cases apart in the logs.
     *
     * A message that genuinely doesn't look like a transaction is NOT an
     * error — the agent returns {"type": "unknown", ...} normally in that
     * case, and this method returns that as-is without throwing.
     *
     * @throws \Throwable
     */
    public function parse(string $message): array
    {
        try {
            $response = (new TransactionParserAgent)->prompt($message);
        } catch (\Throwable $e) {
            Log::warning('TransactionParser: LLM call failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return [
            'type' => $response['type'],
            'amount' => $response['amount'] ?? null,
            'category' => $response['category'] ?? null,
            'note' => $response['note'] ?? '',
            'transaction_date' => $response['transaction_date'],
            'confidence' => $response['confidence'],
        ];
    }
}