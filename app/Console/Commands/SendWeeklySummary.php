<?php

namespace App\Console\Commands;

use App\Enums\TransactionCategory;
use App\Models\User;
use App\Services\WhatsAppClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendWeeklySummary extends Command
{
    protected $signature = 'summary:weekly';
    protected $description = 'Send each approved user their weekly spend summary via WhatsApp';

    public function handle(WhatsAppClient $whatsapp): void
    {
        $users = User::where('status', 'approved')->get();

        $thisWeekStart = now()->subDays(6)->startOfDay();
        $thisWeekEnd = now()->endOfDay();

        $lastWeekStart = now()->subDays(13)->startOfDay();
        $lastWeekEnd = now()->subDays(7)->endOfDay();

        foreach ($users as $user) {
            $thisWeek = $user->transactions()
                ->whereBetween('transaction_date', [$thisWeekStart, $thisWeekEnd])
                ->get();

            if ($thisWeek->isEmpty()) {
                continue; // skip silently, same cost optimization as daily
            }

            $lastWeek = $user->transactions()
                ->whereBetween('transaction_date', [$lastWeekStart, $lastWeekEnd])
                ->get();

            $totalCredit = $thisWeek->where('type', 'credit')->sum('amount');
            $totalDebit = $thisWeek->where('type', 'debit')->sum('amount');
            $net = $totalCredit - $totalDebit;

            $lastWeekDebit = $lastWeek->where('type', 'debit')->sum('amount');

            $topCategories = $thisWeek->where('type', 'debit')
                ->groupBy('category')
                ->map(fn ($group) => $group->sum('amount'))
                ->sortDesc()
                ->take(3);

            $message = $this->formatMessage($totalCredit, $totalDebit, $net, $topCategories, $lastWeekDebit);

            try {
                $whatsapp->sendText($user->phone, $message);
            } catch (\Throwable $e) {
                Log::error('Weekly summary send failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function formatMessage(float $credit, float $debit, float $net, $topCategories, float $lastWeekDebit): string
    {
        $lines = ['📅 *Weekly Summary*'];
        $lines[] = '💰 Credit: ₹' . number_format($credit, 2);
        $lines[] = '💸 Debit: ₹' . number_format($debit, 2);
        $lines[] = ($net >= 0 ? '📈' : '📉') . ' Net: ₹' . number_format($net, 2);

        if ($lastWeekDebit > 0) {
            $diff = $debit - $lastWeekDebit;
            $pct = round(($diff / $lastWeekDebit) * 100, 1);
            $direction = $diff >= 0 ? '⬆️ up' : '⬇️ down';
            $lines[] = "\nSpending is {$direction} " . abs($pct) . '% vs last week';
        }

        if ($topCategories->isNotEmpty()) {
            $lines[] = "\n🏷️ Top categories:";
            foreach ($topCategories as $category => $amount) {
                $emoji = TransactionCategory::matchLoose($category)?->emoji() ?? '📌';
                $lines[] = "{$emoji} {$category}: ₹" . number_format($amount, 2);
            }
        }

        return implode("\n", $lines);
    }
}