<?php

namespace App\Services;

use App\Ai\Agents\CorrectionParserAgent;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class CorrectionParser
{
    public function parse(string $replyMessage, Transaction $pendingTransaction): array
    {
        $context = "Pending transaction: ₹{$pendingTransaction->amount} {$pendingTransaction->type} — {$pendingTransaction->category}.\n"
            . "User's reply: \"{$replyMessage}\"";

        try {
            $response = (new CorrectionParserAgent)->prompt($context);

            return [
                'action' => $response['action'],
                'amount' => $response['amount'] ?? null,
                'category' => $response['category'] ?? null,
                'confidence' => $response['confidence'],
            ];
        } catch (\Throwable $e) {
            Log::error('CorrectionParser: parse failed', ['error' => $e->getMessage()]);

            return [
                'action' => 'reject',
                'amount' => null,
                'category' => null,
                'confidence' => 0.0,
            ];
        }
    }
}