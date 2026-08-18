<?php

namespace App\Services;

use App\Enums\TransactionCategory;
use App\Jobs\ParseTransactionMessage;
use App\Models\ConversationContext;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

class InboundMessageRouter
{
    public function __construct(
        protected CorrectionParser $correctionParser,
        protected WhatsAppClient $whatsapp,
        protected QueryIntentParser $queryIntentParser,
        protected TransactionQueryService $queryService,
        protected NarrativeSummaryService $narrativeSummary,
        protected TransactionActionParser $actionParser,
        protected RecurringExpenseDetector $recurringDetector,
        protected StatementRequestParser $statementRequestParser,
        protected StatementGenerator $statementGenerator,
        protected WhatsAppMessageFormatter $formatter,
    ) {}

    public function route(User $user, string $message): void
    {
        $pendingContext = ConversationContext::activeFor($user->id, 'pending_review');

        if ($pendingContext) {
            $this->resolvePendingReview($user, $message, $pendingContext);
            return;
        }

        $pendingAction = ConversationContext::activeFor($user->id, 'pending_action');

        if ($pendingAction) {
            $this->resolvePendingAction($user, $message, $pendingAction);
            return;
        }

        if ($this->handleUndoEdit($user, $message)) {
            return;
        }

        if ($this->handleTransactionAction($user, $message)) {
            return;
        }

        if ($this->handleStatementRequest($user, $message)) {
            return;
        }

        if ($this->handleQuery($user, $message)) {
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

        $this->whatsapp->sendText(
            $user->phone,
            '✅ Logged ' . $this->formatter->transaction($transaction)
        );
    }

    protected function rejectPending(User $user, Transaction $transaction, ConversationContext $context): void
    {
        $transaction->delete();
        $context->delete();

        $this->whatsapp->sendText($user->phone, "No problem, I discarded that one. Send it again whenever you're ready.");
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

        $this->whatsapp->sendText(
            $user->phone,
            'Done, I updated and logged ' . $this->formatter->transaction($transaction)
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
            $this->whatsapp->sendText($user->phone, "I don't see a recent transaction to undo.");
            return true;
        }

        $summary = $this->formatter->transaction($transaction);
        $transaction->delete();
        $context->delete();

        $this->whatsapp->sendText($user->phone, "Removed {$summary}.");
        return true;
    }

    protected function editLastAmount(User $user, float $amount): bool
    {
        $context = ConversationContext::activeFor($user->id, 'last_transaction');
        $transaction = $context ? Transaction::find($context->payload['transaction_id']) : null;

        if (!$transaction) {
            $this->whatsapp->sendText($user->phone, "I don't see a recent transaction to edit.");
            return true;
        }

        $transaction->update(['amount' => $amount]);
        $this->whatsapp->sendText($user->phone, 'Updated it to ' . $this->formatter->transaction($transaction) . '.');
        return true;
    }

    protected function editLastCategory(User $user, string $category): bool
    {
        $context = ConversationContext::activeFor($user->id, 'last_transaction');
        $transaction = $context ? Transaction::find($context->payload['transaction_id']) : null;

        if (!$transaction) {
            $this->whatsapp->sendText($user->phone, "I don't see a recent transaction to edit.");
            return true;
        }

        $matched = TransactionCategory::matchLoose($category);

        if (!$matched) {
            $this->whatsapp->sendText(
                $user->phone,
                "I don't recognize \"{$category}\" as a category yet. Try Bills, Groceries, Food & Dining, Transport, Shopping, Entertainment, Health, Rent, Salary, Freelance, Refund, Gift, or Other."
            );
            return true;
        }

        $transaction->update(['category' => $matched->value]);
        $this->whatsapp->sendText($user->phone, 'Updated it to ' . $this->formatter->transaction($transaction) . '.');
        return true;
    }

    /**
     * Catches "generate a PDF statement for July" / "send me a csv of this
     * month" style requests. Checked before handleQuery() so the LLM's
     * query-intent parser doesn't misclassify a document request as an
     * aggregate spending question. On recognition, generates the file,
     * sends it via WhatsApp document delivery, and always cleans up the
     * temp file — success or failure.
     */
    protected function handleStatementRequest(User $user, string $message): bool
    {
        try {
            $intent = $this->statementRequestParser->parse($message);
        } catch (\Throwable $e) {
            return false;
        }

        if (!$intent['recognized'] || $intent['confidence'] < 0.7) {
            return false;
        }

        $start = $intent['start_date'] ? Carbon::parse($intent['start_date']) : now()->startOfMonth();
        $end = $intent['end_date'] ? Carbon::parse($intent['end_date']) : now();

        $path = null;

        try {
            $path = $intent['format'] === 'csv'
                ? $this->statementGenerator->generateCsv($user, $start, $end)
                : $this->statementGenerator->generatePdf($user, $start, $end);

            $filename = 'statement.' . $intent['format'];
            $sent = $this->whatsapp->sendDocument($user->phone, $path, $filename);

            if ($sent) {
                $periodLabel = $start->isSameDay($end)
                    ? $start->format('M j')
                    : $start->format('M j') . ' - ' . $end->format('M j');

                $this->whatsapp->sendText($user->phone, "I've sent your statement for {$periodLabel}.");
            } else {
                $this->whatsapp->sendText($user->phone, "I couldn't send that statement right now. Please try again in a bit.");
            }
        } catch (\Throwable $e) {
            $this->whatsapp->sendText($user->phone, "I couldn't generate that statement right now. Please try again in a bit.");
        } finally {
            if ($path && file_exists($path)) {
                @unlink($path);
            }
        }

        return true;
    }

    protected function handleQuery(User $user, string $message): bool
    {
        try {
            $intent = $this->queryIntentParser->parse($message);
        } catch (\Throwable $e) {
            return false;
        }

        if (!$intent['recognized'] || $intent['confidence'] < 0.6) {
            return false;
        }

        $intent = $this->normalizeSpendingIntent($message, $intent);

        if ($intent['query_type'] === 'last_transaction') {
            return $this->replyLastTransaction($user);
        }

        if ($intent['query_type'] === 'recurring') {
            return $this->replyRecurring($user);
        }

        if ($intent['is_comparison']) {
            $comparison = $this->queryService->compare($user, $intent);

            try {
                $summary = $this->narrativeSummary->narrate($comparison, $intent['category']);
                $reply = '📊 ' . $summary;
            } catch (\Throwable $e) {
                $reply = $this->formatComparisonReply($intent, $comparison);
            }

            $this->whatsapp->sendText($user->phone, $reply);
            return true;
        }

        $result = $this->queryService->summarize($user, $intent);

        $this->whatsapp->sendText($user->phone, $this->formatQueryReply($intent, $result));

        return true;
    }

    /**
     * Answers a single-transaction lookup ("what was my last transaction",
     * "what did I just buy") directly from the last_transaction context —
     * no aggregation, no query filters. Distinct from handleTransactionAction's
     * "last" scope, which mutates; this branch only ever reads.
     */
    protected function replyLastTransaction(User $user): bool
    {
        $context = ConversationContext::activeFor($user->id, 'last_transaction');
        $transaction = $context ? Transaction::find($context->payload['transaction_id']) : null;

        if (!$transaction) {
            $this->whatsapp->sendText($user->phone, "I don't see a recent transaction yet.");
            return true;
        }

        $this->whatsapp->sendText(
            $user->phone,
            'Your latest transaction is ' . $this->formatter->transaction($transaction, true) . '.'
        );

        return true;
    }

    /**
     * Answers "what are my recurring expenses" style queries. Pure read —
     * runs RecurringExpenseDetector fresh each time (no caching), same
     * pattern-matching logic as the dashboard widget, no LLM involved in
     * the detection itself.
     */
    protected function replyRecurring(User $user): bool
    {
        $patterns = $this->recurringDetector->detect($user);

        if (empty($patterns)) {
            $this->whatsapp->sendText($user->phone, "I don't see any recurring expenses yet.");
            return true;
        }

        $lines = ['I found a few likely recurring expenses:', ''];

        foreach ($patterns as $pattern) {
            $emoji = TransactionCategory::matchLoose($pattern['category'])?->emoji() ?? '📌';
            $interval = round($pattern['average_interval_days']);
            $lines[] = "{$emoji} {$pattern['category']}: " . $this->formatter->money($pattern['average_amount']) . " every ~{$interval} days ({$pattern['occurrences']} times)";
        }

        $this->whatsapp->sendText($user->phone, implode("\n", $lines));
        return true;
    }

    protected function formatComparisonReply(array $intent, array $comparison): string
    {
        $primary = $comparison['primary'];
        $prior = $comparison['comparison'];
        $delta = $comparison['delta_amount'];

        $direction = $delta > 0 ? 'more' : ($delta < 0 ? 'less' : 'the same');
        $deltaLine = $prior['total'] > 0
            ? sprintf("That's %s %s than the previous period.", $this->formatter->money(abs($delta)), $direction)
            : "I don't have prior-period data to compare against.";

        return sprintf(
            '📊 This period: %s across %d %s. Previous period: %s across %d %s. %s',
            $this->formatter->money($primary['total']),
            $primary['count'],
            $this->plural('transaction', $primary['count']),
            $this->formatter->money($prior['total']),
            $prior['count'],
            $this->plural('transaction', $prior['count']),
            $deltaLine
        );
    }

    protected function formatQueryReply(array $intent, array $result): string
    {
        $type = $intent['type'] ?? 'both';
        $period = $this->describePeriod($intent['start_date'], $intent['end_date']);
        $scope = $this->queryScopeLabel($intent);

        if ($result['count'] === 0) {
            return "I couldn't find any {$scope}{$period}.";
        }

        $lines = [$this->queryTotalSentence($type, $intent['category'], $result, $period)];

        if (!empty($result['by_category'])) {
            $lines[] = '';
            $lines[] = $type === 'credit' ? 'By income category:' : 'By category:';
            foreach ($result['by_category'] as $category => $amount) {
                $emoji = TransactionCategory::matchLoose($category)?->emoji() ?? '📌';
                $lines[] = "{$emoji} {$category}: " . $this->formatter->money($amount);
            }
        }

        return implode("\n", $lines);
    }

    protected function describePeriod(?string $start, ?string $end): string
    {
        if (!$start && !$end) {
            return '';
        }

        if ($start && $start === $end) {
            return ' on ' . Carbon::parse($start)->format('M j');
        }

        if ($start && $end) {
            return ' from ' . Carbon::parse($start)->format('M j') . ' to ' . Carbon::parse($end)->format('M j');
        }

        if ($start) {
            return ' since ' . Carbon::parse($start)->format('M j');
        }

        return ' until ' . Carbon::parse($end)->format('M j');
    }

    protected function queryTotalSentence(string $type, ?string $category, array $result, string $period): string
    {
        $count = $result['count'];
        $transactions = $this->plural('transaction', $count);
        $amount = $this->formatter->money($result['total']);

        if ($category) {
            return match ($type) {
                'credit' => "You received {$amount} in {$category} across {$count} {$transactions}{$period}.",
                'debit' => "You spent {$amount} on {$category} across {$count} {$transactions}{$period}.",
                default => "You logged {$amount} in {$category} across {$count} {$transactions}{$period}.",
            };
        }

        return match ($type) {
            'credit' => "You received {$amount} across {$count} {$transactions}{$period}.",
            'debit' => "You spent {$amount} across {$count} {$transactions}{$period}.",
            default => "You logged {$amount} across {$count} {$transactions}{$period}.",
        };
    }

    protected function queryScopeLabel(array $intent): string
    {
        $type = $intent['type'] ?? 'both';
        $category = $intent['category'] ?? null;

        if ($category) {
            return match ($type) {
                'credit' => "{$category} income",
                'debit' => "{$category} spending",
                default => "{$category} transactions",
            };
        }

        return match ($type) {
            'credit' => 'income',
            'debit' => 'expenses',
            default => 'transactions',
        };
    }

    protected function normalizeSpendingIntent(string $message, array $intent): array
    {
        if (($intent['query_type'] ?? null) !== 'aggregate') {
            return $intent;
        }

        if (!in_array($intent['type'] ?? null, [null, 'both'], true)) {
            return $intent;
        }

        $lower = strtolower($message);
        $mentionsIncome = preg_match('/\b(income|salary|credit|earned|received|freelance|refund|gift)\b/i', $lower);
        $mentionsSpending = preg_match('/\b(spend|spent|spending|expense|expenses|debit|paid|payment)\b/i', $lower);

        if ($mentionsSpending && !$mentionsIncome) {
            $intent['type'] = 'debit';
        }

        return $intent;
    }

    protected function plural(string $word, int $count): string
    {
        return $count === 1 ? $word : $word . 's';
    }

    /**
     * Catches natural-language edit/delete requests that handleUndoEdit's
     * regex doesn't ("actually make that ₹850", "delete the ₹500 grocery
     * one from Tuesday"). "last" scope reuses the last_transaction context
     * directly (mutates immediately, same as the regex path). "search"
     * scope never mutates on its own — exactly one candidate triggers a
     * YES/NO confirmation step; zero or multiple candidates are dead ends
     * that ask the user to clarify, never a guess.
     */
    protected function handleTransactionAction(User $user, string $message): bool
    {
        try {
            $intent = $this->actionParser->parse($message);
        } catch (\Throwable $e) {
            return false;
        }

        if (!$intent['recognized'] || $intent['confidence'] < 0.6) {
            return false;
        }

        if ($intent['target_scope'] === 'last') {
            return $this->applyToLastTransaction($user, $intent);
        }

        return $this->searchAndConfirm($user, $intent);
    }

    protected function applyToLastTransaction(User $user, array $intent): bool
    {
        $context = ConversationContext::activeFor($user->id, 'last_transaction');
        $transaction = $context ? Transaction::find($context->payload['transaction_id']) : null;

        if (!$transaction) {
            $this->whatsapp->sendText($user->phone, "I don't see a recent transaction to work with.");
            return true;
        }

        if ($intent['action'] === 'delete') {
            $summary = $this->formatter->transaction($transaction);
            $transaction->delete();
            $context->delete();

            $this->whatsapp->sendText($user->phone, "Removed {$summary}.");
            return true;
        }

        $updates = [];
        if ($intent['new_amount'] !== null) {
            $updates['amount'] = $intent['new_amount'];
        }
        if ($intent['new_category'] !== null) {
            $matched = TransactionCategory::matchLoose($intent['new_category']);
            if ($matched) {
                $updates['category'] = $matched->value;
            }
        }

        if (empty($updates)) {
            $this->whatsapp->sendText($user->phone, "I couldn't tell what to change. Try adding the new amount or category.");
            return true;
        }

        $transaction->update($updates);

        $this->whatsapp->sendText(
            $user->phone,
            'Updated it to ' . $this->formatter->transaction($transaction) . '.'
        );
        return true;
    }

    protected function searchAndConfirm(User $user, array $intent): bool
    {
        $candidates = $this->queryService->findCandidates($user, [
            'amount' => $intent['search_amount'],
            'category' => $intent['search_category'] ? TransactionCategory::matchLoose($intent['search_category'])?->value : null,
            'date' => $intent['search_date'],
        ]);

        if ($candidates->isEmpty()) {
            $this->whatsapp->sendText($user->phone, "I couldn't find a matching transaction.");
            return true;
        }

        if ($candidates->count() > 1) {
            $lines = ['I found more than one match. Can you be a bit more specific?', ''];
            foreach ($candidates as $t) {
                $lines[] = $this->formatter->transaction($t, true);
            }
            $this->whatsapp->sendText($user->phone, implode("\n", $lines));
            return true;
        }

        $transaction = $candidates->first();
        $summary = $this->formatter->transaction($transaction, true);

        ConversationContext::setFor(
            $user->id,
            'pending_action',
            [
                'transaction_id' => $transaction->id,
                'action' => $intent['action'],
                'new_amount' => $intent['new_amount'],
                'new_category' => $intent['new_category'],
            ],
            now()->addMinutes(5)
        );

        $verb = $intent['action'] === 'delete' ? 'delete' : 'update';
        $this->whatsapp->sendText($user->phone, "Just to confirm, should I {$verb} this?\n{$summary}\nReply YES or NO.");
        return true;
    }

    protected function resolvePendingAction(User $user, string $message, ConversationContext $context): void
    {
        $normalized = strtolower(trim($message));

        if (in_array($normalized, ['yes', 'y', 'confirm', 'yeah', 'yep'])) {
            $this->executePendingAction($user, $context);
            return;
        }

        if (in_array($normalized, ['no', 'n', 'cancel', 'nah', 'nope'])) {
            $context->delete();
            $this->whatsapp->sendText($user->phone, 'Cancelled. No changes made.');
            return;
        }

        // Not a clear yes/no — leave the context alive (it'll expire on its
        // own in 5 minutes) rather than guessing or silently discarding it.
        $this->whatsapp->sendText($user->phone, 'Please reply YES or NO so I know whether to make that change.');
    }

    protected function executePendingAction(User $user, ConversationContext $context): void
    {
        $payload = $context->payload;
        $transaction = Transaction::find($payload['transaction_id']);
        $context->delete();

        if (!$transaction) {
            $this->whatsapp->sendText($user->phone, "That transaction is already gone, so there's nothing to change.");
            return;
        }

        if ($payload['action'] === 'delete') {
            $summary = $this->formatter->transaction($transaction);
            $transaction->delete();

            $this->whatsapp->sendText($user->phone, "Removed {$summary}.");
            return;
        }

        $updates = [];
        if ($payload['new_amount'] !== null) {
            $updates['amount'] = $payload['new_amount'];
        }
        if ($payload['new_category'] !== null) {
            $matched = TransactionCategory::matchLoose($payload['new_category']);
            if ($matched) {
                $updates['category'] = $matched->value;
            }
        }

        $transaction->update($updates);

        $this->whatsapp->sendText(
            $user->phone,
            'Updated it to ' . $this->formatter->transaction($transaction) . '.'
        );
    }
}
