<?php

namespace App\Console\Commands;

use App\Models\Budget;
use App\Models\Transaction;
use App\Services\WhatsAppClient;
use App\Services\WhatsAppMessageFormatter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckBudgets extends Command
{
    protected $signature = 'budgets:check';
    protected $description = 'Check all budgets against current month spend and send WhatsApp alerts on threshold crossing';

    public function handle(WhatsAppClient $whatsapp, WhatsAppMessageFormatter $formatter): int
    {
        $now = Carbon::now('Asia/Kolkata');
        $currentPeriod = $now->format('Y-m');
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();

        $budgets = Budget::with('user')
            ->whereHas('user', fn ($q) => $q->where('status', 'approved'))
            ->get();

        $this->info("Checking {$budgets->count()} budget(s) for period {$currentPeriod}...");

        foreach ($budgets as $budget) {
            // Already alerted this month for this budget — skip.
            if ($budget->last_alerted_period === $currentPeriod) {
                continue;
            }

            $query = Transaction::where('user_id', $budget->user_id)
                ->where('type', 'debit')
                ->where('status', 'confirmed')
                ->whereBetween('transaction_date', [$monthStart, $monthEnd]);

            if (! $budget->isOverall()) {
                $query->where('category', $budget->category);
            }

            $spent = (float) $query->sum('amount');
            $limit = (float) $budget->monthly_limit;
            $thresholdAmount = $limit * ($budget->alert_threshold_percent / 100);

            if ($spent < $thresholdAmount) {
                continue;
            }

            $label = $budget->isOverall() ? 'overall spending' : $budget->category;
            $percentOfLimit = $limit > 0 ? round(($spent / $limit) * 100) : 0;

            $message = "Heads up: you've spent {$formatter->money($spent)} on {$label} this month "
                . "({$percentOfLimit}% of your {$formatter->money($limit)} limit).";

            try {
                $whatsapp->sendText($budget->user->phone, $message);
                $budget->update(['last_alerted_period' => $currentPeriod]);
                $this->info("Alerted user {$budget->user_id} for budget #{$budget->id} ({$label}).");
            } catch (\Throwable $e) {
                Log::warning('CheckBudgets: failed to send alert', [
                    'budget_id' => $budget->id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("Failed to alert user {$budget->user_id} for budget #{$budget->id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
