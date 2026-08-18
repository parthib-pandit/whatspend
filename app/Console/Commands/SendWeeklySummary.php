<?php

namespace App\Console\Commands;

use App\Enums\TransactionCategory;
use App\Models\User;
use App\Services\WhatsAppClient;
use App\Services\WhatsAppMessageFormatter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendWeeklySummary extends Command
{
    protected $signature = 'summary:weekly';
    protected $description = 'Send each approved user their weekly spend summary via WhatsApp';

    public function handle(WhatsAppClient $whatsapp, WhatsAppMessageFormatter $formatter): void
    {
        $users = User::where('status', 'approved')->get();

        $thisWeekStart = now()->subDays(6)->startOfDay();
        $thisWeekEnd = now()->endOfDay();

        $lastWeekStart = now()->subDays(13)->startOfDay();
        $lastWeekEnd = now()->subDays(7)->endOfDay();

        foreach ($users as $user) {
            $thisWeek = $user->transactions()
                ->where('status', 'confirmed')
                ->whereBetween('transaction_date', [$thisWeekStart, $thisWeekEnd])
                ->get();

            if ($thisWeek->isEmpty()) {
                continue; // skip silently, same cost optimization as daily
            }

            $lastWeek = $user->transactions()
                ->where('status', 'confirmed')
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

            $message = $this->formatMessage(
                $totalCredit,
                $totalDebit,
                $net,
                $topCategories,
                $lastWeekDebit,
                $thisWeekStart,
                $thisWeekEnd,
                $formatter,
            );

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

    private function formatMessage(
        float $credit,
        float $debit,
        float $net,
        $topCategories,
        float $lastWeekDebit,
        $start,
        $end,
        WhatsAppMessageFormatter $formatter,
    ): string {
        $period = $start->format('M j') . ' - ' . $end->format('M j');
        $lines = ["📅 *Your weekly money summary* ({$period})", ''];
        $lines[] = 'Income: ' . $formatter->money($credit);
        $lines[] = 'Spent: ' . $formatter->money($debit);
        $lines[] = 'Net: ' . $formatter->money($net);

        if ($lastWeekDebit > 0) {
            $diff = $debit - $lastWeekDebit;
            $pct = round(($diff / $lastWeekDebit) * 100, 1);
            $direction = $diff >= 0 ? 'up' : 'down';
            $lines[] = '';
            $lines[] = 'Your spending is ' . abs($pct) . "% {$direction} compared with last week.";
        }

        if ($topCategories->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Top spending areas:';
            foreach ($topCategories as $category => $amount) {
                $emoji = TransactionCategory::matchLoose($category)?->emoji() ?? '📌';
                $lines[] = "{$emoji} {$category}: " . $formatter->money($amount);
            }
        } elseif ($debit == 0.0) {
            $lines[] = '';
            $lines[] = 'No expenses logged this week.';
        }

        return implode("\n", $lines);
    }
}
