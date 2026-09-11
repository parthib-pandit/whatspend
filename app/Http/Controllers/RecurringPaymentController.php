<?php

namespace App\Http\Controllers;

use App\Enums\TransactionCategory;
use App\Models\RecurringPayment;
use Illuminate\Http\Request;

class RecurringPaymentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $recurringPayments = RecurringPayment::where('user_id', $user->id)
            ->orderBy('next_due_date')
            ->get();

        $categories = collect(TransactionCategory::debitCategories())
            ->pluck('value')
            ->values();

        return view('recurring-payments.index', [
            'recurringPayments' => $recurringPayments,
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $this->validatePayload($request);

        $nextDueDate = RecurringPayment::calculateInitialDueDate(
            $validated['interval_unit'],
            $validated['interval_count'],
            $validated['day_of_month'] ?? null,
            $validated['day_of_week'] ?? null,
        );

        RecurringPayment::create([
            ...$validated,
            'user_id' => $user->id,
            'next_due_date' => $nextDueDate,
        ]);

        return redirect()->route('recurring-payments.index')->with('status', 'Recurring payment added.');
    }

    public function update(Request $request, RecurringPayment $recurringPayment)
    {
        abort_unless($recurringPayment->user_id === $request->user()->id, 403);

        $validated = $this->validatePayload($request);

        // Deliberately does NOT recalculate next_due_date on edit — changing
        // amount/category shouldn't reset the cycle the user is already
        // mid-way through. Interval/day changes take effect starting the
        // *next* cycle (see RecurringPayment::advanceToNextCycle), not this one.
        $recurringPayment->update($validated);

        return redirect()->route('recurring-payments.index')->with('status', 'Recurring payment updated.');
    }

    public function destroy(Request $request, RecurringPayment $recurringPayment)
    {
        abort_unless($recurringPayment->user_id === $request->user()->id, 403);

        $recurringPayment->delete();

        return redirect()->route('recurring-payments.index')->with('status', 'Recurring payment removed.');
    }

    /**
     * Toggle active/paused without a full edit — used by a simple button
     * on the dashboard rather than a full form resubmission.
     */
    public function toggle(Request $request, RecurringPayment $recurringPayment)
    {
        abort_unless($recurringPayment->user_id === $request->user()->id, 403);

        $recurringPayment->update(['active' => ! $recurringPayment->active]);

        return redirect()->route('recurring-payments.index')
            ->with('status', $recurringPayment->active ? 'Resumed.' : 'Paused.');
    }

    protected function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'category' => 'required|string',
            'interval_unit' => 'required|in:day,week,month',
            'interval_count' => 'required|integer|min:1|max:365',
            'day_of_month' => 'nullable|integer|min:1|max:31',
            'day_of_week' => 'nullable|integer|min:0|max:6',
        ]);

        // day_of_month only makes sense for month-based intervals, and
        // day_of_week only for week-based ones — clear whichever doesn't
        // apply rather than trusting the client to omit it correctly.
        if ($validated['interval_unit'] !== 'month') {
            $validated['day_of_month'] = null;
        }
        if ($validated['interval_unit'] !== 'week') {
            $validated['day_of_week'] = null;
        }

        return $validated;
    }
}