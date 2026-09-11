<?php

namespace App\Services;

use App\Ai\Agents\RecurringPaymentParserAgent;
use Illuminate\Support\Facades\Log;

class RecurringPaymentParser
{
    /**
     * @throws \Throwable
     */
    public function parse(string $message): array
    {
        try {
            $response = (new RecurringPaymentParserAgent)->prompt($message);
        } catch (\Throwable $e) {
            Log::warning('RecurringPaymentParser: LLM call failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return [
            'recognized' => $response['recognized'],
            'name' => $response['name'] ?? null,
            'amount' => $response['amount'] ?? null,
            'category' => $response['category'] ?? null,
            'interval_unit' => $response['interval_unit'] ?? 'month',
            'interval_count' => $response['interval_count'] ?? 1,
            'day_of_month' => $response['day_of_month'] ?? null,
            'day_of_week' => $response['day_of_week'] ?? null,
            'confidence' => $response['confidence'],
        ];
    }
}