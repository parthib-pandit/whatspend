<?php

namespace App\Services;

use App\Enums\TransactionCategory;
use App\Models\Transaction;

class WhatsAppMessageFormatter
{
    public function money(float|int|string|null $amount): string
    {
        return '₹' . number_format((float) $amount, 2);
    }

    public function transaction(Transaction $transaction, bool $includeDate = false): string
    {
        $emoji = TransactionCategory::matchLoose($transaction->category)?->emoji() ?? '📌';
        $summary = "{$emoji} {$this->money($transaction->amount)} · {$transaction->category}";

        if ($includeDate) {
            $summary .= ' · ' . $transaction->transaction_date->format('M j');
        }

        return $summary;
    }
}
