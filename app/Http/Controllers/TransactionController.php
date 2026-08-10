<?php

namespace App\Http\Controllers;

use App\Enums\TransactionCategory;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TransactionController extends Controller
{
    public function index(Request $request): View
    {
        $transactions = Transaction::where('user_id', auth()->id())
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('transaction_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('transaction_date', '<=', $request->to))
            ->orderByDesc('transaction_date')
            ->paginate(20)
            ->withQueryString();

        $categoryBreakdown = Transaction::where('user_id', auth()->id())
            ->where('type', 'debit')
            ->whereYear('transaction_date', now()->year)
            ->whereMonth('transaction_date', now()->month)
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        $monthlyTrend = Transaction::where('user_id', auth()->id())
            ->where('type', 'debit')
            ->where('transaction_date', '>=', now()->subMonths(11)->startOfMonth())
            ->selectRaw("strftime('%Y-%m', transaction_date) as month, SUM(amount) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return view('transactions.index', [
            'transactions' => $transactions,
            'categories' => TransactionCategory::cases(),
            'categoryBreakdown' => $categoryBreakdown,
            'monthlyTrend' => $monthlyTrend,
        ]);
    }

    public function create(): View
    {
        return view('transactions.create', [
            'categories' => TransactionCategory::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:credit,debit'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'category' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:255'],
            'transaction_date' => ['required', 'date'],
        ]);

        auth()->user()->transactions()->create([
            ...$validated,
            'source' => 'manual',
            'status' => 'confirmed',
        ]);

        return redirect()->route('transactions.index')->with('status', 'Transaction added.');
    }

    public function edit(Transaction $transaction): View
    {
        $this->authorizeOwnership($transaction);

        return view('transactions.edit', [
            'transaction' => $transaction,
            'categories' => TransactionCategory::cases(),
        ]);
    }

    public function update(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorizeOwnership($transaction);

        $validated = $request->validate([
            'type' => ['required', 'in:credit,debit'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'category' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:255'],
            'transaction_date' => ['required', 'date'],
        ]);

        $transaction->update($validated);

        return redirect()->route('transactions.index')->with('status', 'Transaction updated.');
    }

    public function destroy(Transaction $transaction): RedirectResponse
    {
        $this->authorizeOwnership($transaction);

        $transaction->delete();

        return redirect()->route('transactions.index')->with('status', 'Transaction deleted.');
    }

    private function authorizeOwnership(Transaction $transaction): void
    {
        abort_unless($transaction->user_id === auth()->id(), 403);
    }
}