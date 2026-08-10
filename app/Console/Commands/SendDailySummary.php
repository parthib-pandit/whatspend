<?php

namespace App\Console\Commands;

use App\Enums\TransactionCategory;
use App\Models\User;
use App\Services\WhatsAppClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendDailySummary extends Command
{
    protected $signature = 'summary:daily';
    protected $description = 'Send each approved user their daily spend summary via WhatsApp';

    public function handle(WhatsAppClient $whatsapp): void
    {
        $users = User::where('status', 'approved')->get();

        foreach ($users as $user) {
            $transactions = $user->transactions()
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

            $message = $this->formatMessage($totalCredit, $totalDebit, $net, $topCategories);

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

    private function formatMessage(float $credit, float $debit, float $net, $topCategories): string
    {
        $lines = ['📊 *Daily Summary*'];
        $lines[] = '💰 Credit: ₹' . number_format($credit, 2);
        $lines[] = '💸 Debit: ₹' . number_format($debit, 2);
        $lines[] = ($net >= 0 ? '📈' : '📉') . ' Net: ₹' . number_format($net, 2);

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