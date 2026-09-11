<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecurringPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'amount',
        'category',
        'interval_unit',
        'interval_count',
        'day_of_month',
        'day_of_week',
        'next_due_date',
        'active',
        'last_reminded_at',
        'reminder_attempts',
        'missed_last_reminder',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'interval_count' => 'integer',
        'day_of_month' => 'integer',
        'day_of_week' => 'integer',
        'next_due_date' => 'date',
        'active' => 'boolean',
        'last_reminded_at' => 'datetime',
        'reminder_attempts' => 'integer',
        'missed_last_reminder' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeDueToday(Builder $query): Builder
    {
        return $query->where('active', true)
            ->whereDate('next_due_date', Carbon::today());
    }

    /**
     * Payments with an open (unanswered) reminder from a prior day, still
     * within the 3-attempt retry budget. Attempt count of 0 means no
     * reminder has gone out yet for this cycle — that's dueToday()'s job,
     * not this one.
     */
    public function scopeAwaitingRetry(Builder $query): Builder
    {
        return $query->where('active', true)
            ->whereBetween('reminder_attempts', [1, 2])
            ->where(function ($q) {
                $q->whereNull('last_reminded_at')
                  ->orWhereDate('last_reminded_at', '<', Carbon::today());
            });
    }

    /**
     * Deterministic date math — kept out of the LLM parsing layer on
     * purpose, same "LLM extracts, Laravel computes" principle as the rest
     * of the app. Computes the FIRST next_due_date for a newly-declared
     * recurring payment based on day_of_month / day_of_week, or falls back
     * to "one interval from today" if neither is set.
     */
    public static function calculateInitialDueDate(
        string $intervalUnit,
        int $intervalCount,
        ?int $dayOfMonth,
        ?int $dayOfWeek,
    ): Carbon {
        $today = Carbon::today();

        if ($intervalUnit === 'month' && $dayOfMonth !== null) {
            $candidate = $today->copy()->day(min($dayOfMonth, $today->daysInMonth));

            return $candidate->isPast() && !$candidate->isToday()
                ? $today->copy()->addMonthNoOverflow()->day(min($dayOfMonth, $today->copy()->addMonthNoOverflow()->daysInMonth))
                : $candidate;
        }

        if ($intervalUnit === 'week' && $dayOfWeek !== null) {
            $candidate = $today->copy()->next($dayOfWeek);

            // Carbon::next() always moves forward, even if today already
            // matches — check today explicitly first so "every Friday"
            // declared on a Friday doesn't skip a whole week.
            return $today->dayOfWeek === $dayOfWeek ? $today->copy() : $candidate;
        }

        // No day-of-month/week given — just project one interval forward.
        return match ($intervalUnit) {
            'day' => $today->copy()->addDays($intervalCount),
            'week' => $today->copy()->addWeeks($intervalCount),
            'month' => $today->copy()->addMonthsNoOverflow($intervalCount),
        };
    }

    /**
     * Advances to the next cycle after a payment is confirmed logged (or
     * after the 3rd unanswered reminder — see recurring:remind). Resets the
     * reminder state so the next cycle starts clean.
     */
    public function advanceToNextCycle(): void
    {
        $current = Carbon::parse($this->next_due_date);

        $this->next_due_date = match ($this->interval_unit) {
            'day' => $current->addDays($this->interval_count),
            'week' => $current->addWeeks($this->interval_count),
            // addMonthsNoOverflow matters here: day_of_month=31 advancing
            // into a 30-day month should clamp, not roll into the month after.
            'month' => $current->addMonthsNoOverflow($this->interval_count),
        };

        $this->last_reminded_at = null;
        $this->reminder_attempts = 0;
        $this->save();
    }
}