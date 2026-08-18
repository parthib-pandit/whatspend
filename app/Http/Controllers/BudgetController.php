<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Transaction;
use App\Enums\TransactionCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BudgetController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $budgets = Budget::where('user_id', $user->id)
            ->orderByRaw('category IS NULL DESC') // overall budget shown first
            ->orderBy('category')
            ->get();

        // whereBetween on plain dates rather than strftime/DATE_FORMAT —
        // keeps this portable across your local SQLite and production MySQL.
        $monthStart = Carbon::now()->startOfMonth()->toDateString();
        $monthEnd = Carbon::now()->endOfMonth()->toDateString();

        $budgetsWithSpend = $budgets->map(function (Budget $budget) use ($user, $monthStart, $monthEnd) {
            $query = Transaction::where('user_id', $user->id)
                ->where('type', 'debit')
                ->where('status', 'confirmed')
                ->whereBetween('transaction_date', [$monthStart, $monthEnd]);

            if ($budget->category) {
                $query->where('category', $budget->category);
            }

            $spent = (float) $query->sum('amount');
            $percent = $budget->monthly_limit > 0
                ? round(($spent / $budget->monthly_limit) * 100)
                : 0;

            return [
                'budget' => $budget,
                'spent' => $spent,
                'percent' => $percent,
                'over' => $spent > $budget->monthly_limit,
                'near' => $percent >= $budget->alert_threshold_percent,
            ];
        });

        // Categories that don't already have a budget, for the "add" dropdown.
        // Adjust TransactionCategory::cases() below if your enum splits
        // debit/credit categories differently — budgets should only offer
        // debit categories (Bills, Groceries, Food & Dining, etc.).
        $usedCategories = $budgets->pluck('category')->filter()->all();
        $availableCategories = collect(TransactionCategory::cases())
            ->pluck('value')
            ->reject(fn ($c) => in_array($c, $usedCategories))
            ->values();

        $hasOverallBudget = $budgets->contains(fn ($b) => is_null($b->category));

        return view('budgets.index', [
            'budgetsWithSpend' => $budgetsWithSpend,
            'availableCategories' => $availableCategories,
            'hasOverallBudget' => $hasOverallBudget,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'category' => 'nullable|string',
            'monthly_limit' => 'required|numeric|min:1',
            'alert_threshold_percent' => 'nullable|integer|min:1|max:100',
        ]);

        Budget::updateOrCreate(
            [
                'user_id' => $user->id,
                'category' => $validated['category'] ?: null,
            ],
            [
                'monthly_limit' => $validated['monthly_limit'],
                'alert_threshold_percent' => $validated['alert_threshold_percent'] ?? 80,
            ]
        );

        return redirect()->route('budgets.index')->with('status', 'Budget saved.');
    }

    public function update(Request $request, Budget $budget)
    {
        abort_unless($budget->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'monthly_limit' => 'required|numeric|min:1',
            'alert_threshold_percent' => 'nullable|integer|min:1|max:100',
        ]);

        $budget->update([
            'monthly_limit' => $validated['monthly_limit'],
            'alert_threshold_percent' => $validated['alert_threshold_percent'] ?? $budget->alert_threshold_percent,
        ]);

        return redirect()->route('budgets.index')->with('status', 'Budget updated.');
    }

    public function destroy(Request $request, Budget $budget)
    {
        abort_unless($budget->user_id === $request->user()->id, 403);

        $budget->delete();

        return redirect()->route('budgets.index')->with('status', 'Budget removed.');
    }
}