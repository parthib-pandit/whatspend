<?php

namespace App\Console\Commands;

use App\Enums\TransactionCategory;
use App\Models\User;
use App\Services\WhatsAppClient;
use App\Services\WhatsAppMessageFormatter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendDailySummary extends Command
{
    protected $signature = 'summary:daily';
    protected $description = 'Send each approved user their daily spend summary via WhatsApp';

    public function handle(WhatsAppClient $whatsapp, WhatsAppMessageFormatter $formatter): void
    {
        $users = User::where('status', 'approved')->get();

        foreach ($users as $user) {
            $transactions = $user->transactions()
                ->where('status', 'confirmed')
                ->whereDate('transaction_date', today())
                ->get();

            if ($transactions->isEmpty()) {
                continue; // skip silently, cost optimization
            }

            $totalCredit = $transactions->where('type', 'credit')->sum('amount');
            $totalDebit = $transactions->where('type', 'debit')->sum('amount');
            $net = $totalCredit - $totalDebit;

            $topCategories = $transactions->where('type', 'debit')
                ->groupBy('category')
                ->map(fn ($group) => $group->sum('amount'))
                ->sortDesc()
                ->take(3);

            $message = $this->formatMessage($totalCredit, $totalDebit, $net, $topCategories, $formatter);

            try {
                $whatsapp->sendText($user->phone, $message);
            } catch (\Throwable $e) {
                Log::error('Daily summary send failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function formatMessage(
        float $credit,
        float $debit,
        float $net,
        $topCategories,
        WhatsAppMessageFormatter $formatter,
    ): string {
        $date = today()->format('M j');
        $lines = ["📊 *Today's money summary* ({$date})", ''];
        $lines[] = 'Income: ' . $formatter->money($credit);
        $lines[] = 'Spent: ' . $formatter->money($debit);
        $lines[] = 'Net: ' . $formatter->money($net);

        if ($topCategories->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Top spending areas:';
            foreach ($topCategories as $category => $amount) {
                $emoji = TransactionCategory::matchLoose($category)?->emoji() ?? '📌';
                $lines[] = "{$emoji} {$category}: " . $formatter->money($amount);
            }
        } elseif ($debit == 0.0) {
            $lines[] = '';
            $lines[] = 'No expenses logged today.';
        }

        return implode("\n", $lines);
    }
}
