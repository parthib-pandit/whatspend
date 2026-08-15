<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;

class TransactionQueryService
{
    public function summarize(User $user, array $filters): array
    {
        $query = Transaction::query()
            ->where('user_id', $user->id)
            ->where('status', 'confirmed');

        if (($filters['type'] ?? 'both') !== 'both' && !empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (!empty($filters['start_date'])) {
            $query->where('transaction_date', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('transaction_date', '<=', $filters['end_date']);
        }

        if (!empty($filters['min_amount'])) {
            $query->where('amount', '>=', $filters['min_amount']);
        }

        if (!empty($filters['max_amount'])) {
            $query->where('amount', '<=', $filters['max_amount']);
        }

        $total = (clone $query)->sum('amount');
        $count = (clone $query)->count();

        $byCategory = [];
        if (empty($filters['category'])) {
            $byCategory = (clone $query)
                ->selectRaw('category, SUM(amount) as total')
                ->groupBy('category')
                ->orderByDesc('total')
                ->pluck('total', 'category')
                ->map(fn ($v) => (float) $v)
                ->toArray();
        }

        return [
            'total' => (float) $total,
            'count' => $count,
            'by_category' => $byCategory,
        ];
    }

    /**
     * Run two summarize() calls off a single filter set — the primary period
     * (start_date/end_date) and the comparison period (compare_start_date/
     * compare_end_date) — and return both plus a computed diff. Purely
     * deterministic; no LLM involvement here either.
     */
    public function compare(User $user, array $filters): array
    {
        $primaryFilters = $filters;

        $comparisonFilters = array_merge($filters, [
            'start_date' => $filters['compare_start_date'] ?? null,
            'end_date' => $filters['compare_end_date'] ?? null,
        ]);

        $primary = $this->summarize($user, $primaryFilters);
        $comparison = $this->summarize($user, $comparisonFilters);

        $deltaAmount = $primary['total'] - $comparison['total'];
        $deltaPercent = $comparison['total'] > 0
            ? round(($deltaAmount / $comparison['total']) * 100, 1)
            : null; // avoid divide-by-zero; null means "not meaningful" (e.g. 0 -> 500)

        return [
            'primary' => $primary,
            'comparison' => $comparison,
            'delta_amount' => $deltaAmount,
            'delta_percent' => $deltaPercent,
        ];
    }

    public function findCandidates(User $user, array $criteria)
    {
        $query = Transaction::query()
            ->where('user_id', $user->id)
            ->where('status', 'confirmed');

        if (!empty($criteria['amount'])) {
            $query->where('amount', $criteria['amount']);
        }

        if (!empty($criteria['category'])) {
            $query->where('category', $criteria['category']);
        }

        if (!empty($criteria['date'])) {
            $query->whereDate('transaction_date', $criteria['date']);
        }

        return $query->orderByDesc('transaction_date')->limit(5)->get();
    }
}