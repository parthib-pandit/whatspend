<?php

namespace App\Services;

use App\Enums\TransactionCategory;
use App\Jobs\ParseTransactionMessage;
use App\Models\ConversationContext;
use App\Models\Transaction;
use App\Models\User;

class InboundMessageRouter
{
    public function __construct(
        protected CorrectionParser $correctionParser,
        protected WhatsAppClient $whatsapp,
    ) {}

    public function route(User $user, string $message): void
    {
        $pendingContext = ConversationContext::activeFor($user->id, 'pending_review');

        if ($pendingContext) {
            $this->resolvePendingReview($user, $message, $pendingContext);
            return;
        }

        if ($this->handleUndoEdit($user, $message)) {
            return;
        }

        ParseTransactionMessage::dispatch($user, $message);
    }

    protected function resolvePendingReview(User $user, string $message, ConversationContext $context): void
    {
        $transaction = Transaction::find($context->payload['transaction_id']);

        if (!$transaction) {
            $context->delete();
            ParseTransactionMessage::dispatch($user, $message);
            return;
        }

        $result = $this->correctionParser->parse($message, $transaction);

        match ($result['action']) {
            'confirm' => $this->confirmPending($user, $transaction, $context),
            'reject' => $this->rejectPending($user, $transaction, $context),
            'correct' => $this->correctPending($user, $transaction, $context, $result),
        };
    }

    protected function confirmPending(User $user, Transaction $transaction, ConversationContext $context): void
    {
        $transaction->update(['status' => 'confirmed']);
        $context->delete();

        ConversationContext::setFor($user->id, 'last_transaction', ['transaction_id' => $transaction->id]);

        $emoji = TransactionCategory::matchLoose($transaction->category)?->emoji() ?? '📌';

        $this->whatsapp->sendText(
            $user->phone,
            "✅ Logged {$emoji} ₹{$transaction->amount} · {$transaction->category}"
        );
    }

    protected function rejectPending(User $user, Transaction $transaction, ConversationContext $context): void
    {
        $transaction->delete();
        $context->delete();

        $this->whatsapp->sendText($user->phone, "🗑️ Discarded — send it again whenever you're ready.");
    }

    protected function correctPending(User $user, Transaction $transaction, ConversationContext $context, array $result): void
    {
        $updates = ['status' => 'confirmed'];

        if ($result['amount'] !== null) {
            $updates['amount'] = $result['amount'];
        }
        if ($result['category'] !== null) {
            $updates['category'] = $result['category'];
        }

        $transaction->update($updates);
        $context->delete();

        ConversationContext::setFor($user->id, 'last_transaction', ['transaction_id' => $transaction->id]);

        $emoji = TransactionCategory::matchLoose($transaction->category)?->emoji() ?? '📌';

        $this->whatsapp->sendText(
            $user->phone,
            "✅ Got it — updated & logged {$emoji} ₹{$transaction->amount} · {$transaction->category}"
        );
    }

    protected function handleUndoEdit(User $user, string $message): bool
    {
        $normalized = strtolower(trim($message));

        if (in_array($normalized, ['undo last', 'undo', 'delete previous expense', 'delete last'])) {
            return $this->undoLast($user);
        }

        if (preg_match('/^edit last amount to\s*₹?(\d+(\.\d+)?)$/iu', $normalized, $m)) {
            return $this->editLastAmount($user, (float) $m[1]);
        }

        if (preg_match('/^edit last category to\s*(.+)$/iu', $normalized, $m)) {
            return $this->editLastCategory($user, trim($m[1]));
        }

        return false;
    }

    protected function undoLast(User $user): bool
    {
        $context = ConversationContext::activeFor($user->id, 'last_transaction');
        $transaction = $context ? Transaction::find($context->payload['transaction_id']) : null;

        if (!$transaction) {
            $this->whatsapp->sendText($user->phone, "🤷 Nothing recent to undo.");
            return true;
        }

        $emoji = TransactionCategory::matchLoose($transaction->category)?->emoji() ?? '📌';
        $summary = "{$emoji} ₹{$transaction->amount} · {$transaction->category}";
        $transaction->delete();
        $context->delete();

        $this->whatsapp->sendText($user->phone, "🗑️ Removed {$summary}");
        return true;
    }

    protected function editLastAmount(User $user, float $amount): bool
    {
        $context = ConversationContext::activeFor($user->id, 'last_transaction');
        $transaction = $context ? Transaction::find($context->payload['transaction_id']) : null;

        if (!$transaction) {
            $this->whatsapp->sendText($user->phone, "🤷 Nothing recent to edit.");
            return true;
        }

        $transaction->update(['amount' => $amount]);
        $emoji = TransactionCategory::matchLoose($transaction->category)?->emoji() ?? '📌';
        $this->whatsapp->sendText($user->phone, "✏️ Updated {$emoji} ₹{$amount} · {$transaction->category}");
        return true;
    }

    protected function editLastCategory(User $user, string $category): bool
    {
        $context = ConversationContext::activeFor($user->id, 'last_transaction');
        $transaction = $context ? Transaction::find($context->payload['transaction_id']) : null;

        if (!$transaction) {
            $this->whatsapp->sendText($user->phone, "🤷 Nothing recent to edit.");
            return true;
        }

        $matched = TransactionCategory::matchLoose($category);

        if (!$matched) {
            $this->whatsapp->sendText(
                $user->phone,
                "🙈 \"{$category}\" isn't a category I recognize. Try one of: Bills, Groceries, Food & Dining, Transport, Shopping, Entertainment, Health, Rent, Salary, Freelance, Refund, Gift, Other."
            );
            return true;
        }

        $transaction->update(['category' => $matched->value]);
        $this->whatsapp->sendText($user->phone, "✏️ Updated {$matched->emoji()} ₹{$transaction->amount} · {$matched->value}");
        return true;
    }
}