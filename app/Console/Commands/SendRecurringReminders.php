<?php

namespace App\Console\Commands;

use App\Models\ConversationContext;
use App\Models\RecurringPayment;
use App\Services\WhatsAppClient;
use App\Services\WhatsAppMessageFormatter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendRecurringReminders extends Command
{
    protected $signature = 'recurring:remind';
    protected $description = 'Send WhatsApp reminders for recurring payments due today, retry unanswered ones, and give up after 3 unanswered attempts';

    public function handle(WhatsAppClient $whatsapp, WhatsAppMessageFormatter $formatter): int
    {
        $sent = 0;
        $givenUp = 0;

        // --- New reminders: payments becoming due for the first time this cycle ---
        RecurringPayment::dueToday()
            ->whereHas('user', fn ($q) => $q->where('status', 'approved'))
            ->get()
            ->each(function (RecurringPayment $payment) use ($whatsapp, $formatter, &$sent) {
                // Guard against the scheduler firing twice in one day (cron
                // misfire, manual re-run, etc.) so we don't double-remind.
                if ($payment->last_reminded_at?->isToday()) {
                    return;
                }

                $this->sendReminder($payment, 1, $whatsapp, $formatter);
                $sent++;
            });

        // --- Retries: reminded before, no response yet, still within budget ---
        RecurringPayment::awaitingRetry()
            ->whereHas('user', fn ($q) => $q->where('status', 'approved'))
            ->get()
            ->each(function (RecurringPayment $payment) use ($whatsapp, $formatter, &$sent) {
                $this->sendReminder($payment, $payment->reminder_attempts + 1, $whatsapp, $formatter);
                $sent++;
            });

        // --- Give up: 3rd reminder went unanswered for a full day ---
        RecurringPayment::where('active', true)
            ->where('reminder_attempts', '>=', 3)
            ->where('missed_last_reminder', false)
            ->whereDate('last_reminded_at', '<', Carbon::today())
            ->whereHas('user', fn ($q) => $q->where('status', 'approved'))
            ->get()
            ->each(function (RecurringPayment $payment) use ($whatsapp, &$givenUp) {
                $this->giveUp($payment, $whatsapp);
                $givenUp++;
            });

        $this->info("Sent {$sent} reminder(s), gave up on {$givenUp} unanswered payment(s).");

        return self::SUCCESS;
    }

    /**
     * Sends (or re-sends) a reminder and opens/refreshes the
     * `recurring_confirm` context — the router uses this to interpret the
     * user's next YES/NO reply as "did I pay this?" rather than a new
     * transaction or a recurring-payment *creation* confirm (that's the
     * separate `recurring_add_confirm` context).
     *
     * Note: like every other single-slot context in this app, only one
     * `recurring_confirm` can be active per user at a time — if two
     * recurring payments are due for the same user on the same day, the
     * second reminder's context silently replaces the first's. Fine at
     * your current scale; the `pending_review` backlog pattern (see
     * InboundMessageRouter::advanceOrClosePendingReview) is the template to
     * copy if this ever actually bites.
     */
    protected function sendReminder(RecurringPayment $payment, int $attempt, WhatsAppClient $whatsapp, WhatsAppMessageFormatter $formatter): void
    {
        $payment->reminder_attempts = $attempt;
        $payment->last_reminded_at = now();
        $payment->save();

        ConversationContext::setFor(
            $payment->user_id,
            'recurring_confirm',
            ['recurring_payment_id' => $payment->id],
            now()->addDay(),
        );

        $prefix = $attempt > 1 ? "Reminder ({$attempt}/3): " : '';

        try {
            $whatsapp->sendText(
                $payment->user->phone,
                "{$prefix}Did you pay {$payment->name} — " . $formatter->money($payment->amount)
                    . "? Reply YES if you paid it, NO to skip logging it this time."
            );
        } catch (\Throwable $e) {
            Log::warning('SendRecurringReminders: failed to send reminder', [
                'recurring_payment_id' => $payment->id,
                'attempt' => $attempt,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Three unanswered reminders in a row — stop asking, flag it for the
     * dashboard badge, and roll forward to the next cycle so the payment
     * doesn't get stuck re-asking about the same missed occurrence forever.
     */
    protected function giveUp(RecurringPayment $payment, WhatsAppClient $whatsapp): void
    {
        $payment->missed_last_reminder = true;
        $payment->save();

        ConversationContext::where('user_id', $payment->user_id)
            ->where('type', 'recurring_confirm')
            ->delete();

        $payment->advanceToNextCycle();

        try {
            $whatsapp->sendText(
                $payment->user->phone,
                "⚠️ Didn't hear back about {$payment->name}, so I've marked it as missed for this cycle. "
                    . 'Next reminder: ' . $payment->next_due_date->format('M j, Y') . '. You can still log it manually anytime.'
            );
        } catch (\Throwable $e) {
            Log::warning('SendRecurringReminders: failed to send missed-reminder notice', [
                'recurring_payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}