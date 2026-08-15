<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

class RecurringExpenseDetector
{
    /**
     * Detect likely recurring expenses for a user.
     * Groups confirmed debit transactions by category, clusters by amount
     * (within tolerance), and flags clusters that repeat roughly monthly.
     *
     * @return array<int, array{
     *   category: string,
     *   average_amount: float,
     *   occurrences: int,
     *   average_interval_days: float,
     *   last_date: string,
     *   transaction_ids: array<int>
     * }>
     */
    public function detect(User $user, float $amountTolerancePercent = 10.0, int $minOccurrences = 2): array
    {
        $transactions = Transaction::where('user_id', $user->id)
            ->where('type', 'debit')
            ->where('status', 'confirmed')
            ->orderBy('transaction_date')
            ->get(['id', 'amount', 'category', 'transaction_date']);

        $byCategory = $transactions->groupBy('category');

        $patterns = [];

        foreach ($byCategory as $category => $categoryTransactions) {
            if ($category === null) {
                continue;
            }

            $clusters = $this->clusterByAmount($categoryTransactions, $amountTolerancePercent);

            foreach ($clusters as $cluster) {
                if (count($cluster) < $minOccurrences) {
                    continue;
                }

                if (! $this->looksMonthly($cluster)) {
                    continue;
                }

                $amounts = collect($cluster)->pluck('amount')->map(fn ($a) => (float) $a);
                $dates = collect($cluster)->pluck('transaction_date')->map(fn ($d) => Carbon::parse($d));

                $patterns[] = [
                    'category' => $category,
                    'average_amount' => round($amounts->avg(), 2),
                    'occurrences' => count($cluster),
                    'average_interval_days' => $this->averageIntervalDays($dates),
                    'last_date' => $dates->max()->toDateString(),
                    'transaction_ids' => collect($cluster)->pluck('id')->all(),
                ];
            }
        }

        return $patterns;
    }

    /**
     * Greedy clustering: walk transactions in order, group consecutive ones
     * whose amount is within tolerance of the cluster's running average.
     */
    private function clusterByAmount($categoryTransactions, float $tolerancePercent): array
    {
        $sorted = $categoryTransactions->sortBy('amount')->values();
        $clusters = [];

        foreach ($sorted as $txn) {
            $placed = false;

            foreach ($clusters as &$cluster) {
                $avg = collect($cluster)->avg('amount');
                $diffPercent = $avg > 0 ? abs($txn->amount - $avg) / $avg * 100 : 100;

                if ($diffPercent <= $tolerancePercent) {
                    $cluster[] = $txn;
                    $placed = true;
                    break;
                }
            }
            unset($cluster);

            if (! $placed) {
                $clusters[] = [$txn];
            }
        }

        return $clusters;
    }

    /**
     * A cluster "looks monthly" if it spans at least 2 distinct calendar
     * months and the average gap between consecutive occurrences falls
     * roughly in a monthly range (20-40 days).
     */
    private function looksMonthly(array $cluster): bool
    {
        $dates = collect($cluster)->pluck('transaction_date')->map(fn ($d) => Carbon::parse($d))->sort()->values();

        $distinctMonths = $dates->map(fn ($d) => $d->format('Y-m'))->unique()->count();
        if ($distinctMonths < 2) {
            return false;
        }

        $avgInterval = $this->averageIntervalDays($dates);

        return $avgInterval >= 20 && $avgInterval <= 40;
    }

    private function averageIntervalDays($dates): float
    {
        $dates = collect($dates)->sort()->values();
        if ($dates->count() < 2) {
            return 0.0;
        }

        $gaps = [];
        for ($i = 1; $i < $dates->count(); $i++) {
            $gaps[] = abs($dates[$i]->diffInDays($dates[$i - 1]));
        }

        return round(array_sum($gaps) / count($gaps), 1);
    }
}