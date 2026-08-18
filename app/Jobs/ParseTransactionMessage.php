<?php

namespace App\Jobs;

use App\Models\ConversationContext;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionParser;
use App\Services\WhatsAppClient;
use App\Services\WhatsAppMessageFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ParseTransactionMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public User $user,
        public string $message,
    ) {}

    public function backoff(): array
    {
        return [10, 30]; // 10s before attempt 2, 30s before attempt 3
    }

    /**
     * NOTE on retry safety: the only thing inside the retryable try/catch
     * below is $parser->parse(). Transaction::create() and the WhatsApp
     * reply only happen AFTER parse() has already succeeded. That means a
     * retry always re-runs from "ask the LLM again" — it never re-saves a
     * transaction that was already saved on a previous attempt. Keep it
     * this way: don't move Transaction::create() before the try/catch, or
     * a retried attempt could create duplicate rows.
     */
    public function handle(TransactionParser $parser, WhatsAppClient $whatsapp, WhatsAppMessageFormatter $formatter): void
    {
        try {
            $parsed = $parser->parse($this->message);
        } catch (\Throwable $e) {
            Log::warning('ParseTransactionMessage: parse attempt failed', [
                'user_id' => $this->user->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e; // let ShouldQueue retry mechanism handle it
        }

        if (($parsed['type'] ?? 'unknown') === 'unknown') {
            $whatsapp->sendText(
                $this->user->phone,
                "I couldn't read that as a transaction. Try something like \"spent 300 on groceries\" or \"got 5k salary\"."
            );
            return;
        }

        $confidence = $parsed['confidence'] ?? 0;
        $status = $confidence >= 0.7 ? 'confirmed' : 'pending_review';

        $transaction = Transaction::create([
            'user_id' => $this->user->id,
            'type' => $parsed['type'],
            'amount' => $parsed['amount'],
            'category' => $parsed['category'],
            'note' => $parsed['note'] ?? null,
            'raw_message' => $this->message,
            'source' => 'whatsapp',
            'confidence' => $confidence,
            'status' => $status,
            'transaction_date' => $parsed['transaction_date'] ?? now()->toDateString(),
        ]);

        if ($status === 'confirmed') {
            ConversationContext::setFor(
                $this->user->id,
                'last_transaction',
                ['transaction_id' => $transaction->id],
            );

            $sent = $whatsapp->sendTextSucceeded(
                $this->user->phone,
                'Logged ' . $formatter->transaction($transaction) . '.'
            );
        } else {
            ConversationContext::setFor(
                $this->user->id,
                'pending_review',
                ['transaction_id' => $transaction->id],
                now()->addMinutes(5),
            );

            $sent = $whatsapp->sendTextSucceeded(
                $this->user->phone,
                'I read this as ' . $formatter->transaction($transaction) . ". Is that right? Reply YES or NO."
            );
        }

        // The transaction is already correctly saved at this point regardless
        // of whether the confirmation text made it out. We deliberately do
        // NOT throw here — retrying the job would re-run parse() and
        // Transaction::create() again, creating a duplicate row, just to
        // resend a notification. Instead, log it clearly so it's visible
        // (e.g. in a "notifications failed" report) without corrupting data.
        if (!$sent) {
            Log::warning('ParseTransactionMessage: transaction saved but WhatsApp confirmation failed to send', [
                'user_id' => $this->user->id,
                'transaction_id' => $transaction->id,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ParseTransactionMessage failed after all retries', [
            'user_id' => $this->user->id,
            'message' => $this->message,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);

        try {
            app(WhatsAppClient::class)->sendText(
                $this->user->phone,
                "Something went wrong while reading that message. Please try sending it again in a bit."
            );
        } catch (\Throwable $e) {
            // Don't let a failure-notification failure mask the real error above.
            Log::error('ParseTransactionMessage: failure notification itself could not be sent', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
