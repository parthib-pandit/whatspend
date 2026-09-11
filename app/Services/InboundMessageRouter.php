<?php

namespace App\Services;

use App\Enums\TransactionCategory;
use App\Jobs\ParseTransactionMessage;
use App\Models\ConversationContext;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\RecurringPayment;

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
        protected RecurringPaymentParser $recurringPaymentParser,
    ) {}

        public function route(User $user, string $message): void
        {
        if (strtolower(trim($message)) === 'pending') {
            $this->listPending($user);
            return;
        }

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

        $pendingRecurring = ConversationContext::activeFor($user->id, 'recurring_add_confirm');

        if ($pendingRecurring) {
            $this->resolveRecurringAddConfirm($user, $message, $pendingRecurring);
            return;
        }

        // Distinct from recurring_add_confirm above: that one confirms
        // *creating* a recurring rule, this one confirms *logging a
        // payment* against a rule that already exists (opened by
        // recurring:remind).
        $pendingRecurringConfirm = ConversationContext::activeFor($user->id, 'recurring_confirm');

        if ($pendingRecurringConfirm) {
            $this->resolveRecurringConfirm($user, $message, $pendingRecurringConfirm);
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

        if ($this->handleRecurringPaymentRequest($user, $message)) {
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
        $this->advanceOrClosePendingReview($user, $context);

        ConversationContext::setFor($user->id, 'last_transaction', ['transaction_id' => $transaction->id]);

        $this->whatsapp->sendText(
            $user->phone,
            '✅ Logged ' . $this->formatter->transaction($transaction)
        );
    }

    protected function rejectPending(User $user, Transaction $transaction, ConversationContext $context): void
    {
        $transaction->delete();
        $this->advanceOrClosePendingReview($user, $context);

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
        $this->advanceOrClosePendingReview($user, $context);

        ConversationContext::setFor($user->id, 'last_transaction', ['transaction_id' => $transaction->id]);

        $this->whatsapp->sendText(
            $user->phone,
            'Done, I updated and logged ' . $this->formatter->transaction($transaction)
        );
    }

        /**
     * Called after a pending_review context is resolved (confirmed,
     * rejected, or corrected). If another low-confidence transaction
     * came in while this one was awaiting confirmation, it was queued
     * into this context's backlog instead of overwriting it (see
     * ParseTransactionMessage) — this promotes the next queued
     * transaction into a fresh pending_review context instead of leaving
     * it orphaned.
     */
    protected function advanceOrClosePendingReview(User $user, ConversationContext $context): void
    {
        $backlog = $context->payload['backlog'] ?? [];
        $context->delete();

        while (!empty($backlog)) {
            $nextId = array_shift($backlog);
            $next = Transaction::find($nextId);

            if (!$next) {
                continue; // gone somehow — skip to the next queued one
            }

            ConversationContext::setFor(
                $user->id,
                'pending_review',
                ['transaction_id' => $next->id, 'backlog' => $backlog],
                now()->addMinutes(5),
            );

            $this->whatsapp->sendText(
                $user->phone,
                'Next up — I read this as ' . $this->formatter->transaction($next) . ". Is that right? Reply YES or NO."
            );
            return;
        }
    }

    /**
     * WhatsApp "pending" command — lists every transaction currently
     * awaiting YES/NO confirmation (the active one plus anything queued
     * behind it in the backlog), so nothing gets silently stuck.
     */
    protected function listPending(User $user): void
    {
        $context = ConversationContext::activeFor($user->id, 'pending_review');

        if (!$context) {
            $this->whatsapp->sendText($user->phone, "Nothing's waiting on confirmation right now.");
            return;
        }

        $ids = array_merge([$context->payload['transaction_id']], $context->payload['backlog'] ?? []);
        $transactions = collect($ids)->map(fn ($id) => Transaction::find($id))->filter();

        if ($transactions->isEmpty()) {
            $this->whatsapp->sendText($user->phone, "Nothing's waiting on confirmation right now.");
            return;
        }

        $lines = ['Waiting on your confirmation:', ''];
        foreach ($transactions->values() as $i => $t) {
            $marker = $i === 0 ? '👉' : '  ';
            $lines[] = "{$marker} " . $this->formatter->transaction($t, true);
        }
        $lines[] = '';
        $lines[] = "Reply YES or NO to handle the first one — I'll move to the next automatically.";

        $this->whatsapp->sendText($user->phone, implode("\n", $lines));
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
    public function routeUnknown(string $phone, string $message): void
    {
        $context = ConversationContext::activeForPhone($phone, 'signup_flow');

        if ($context) {
            $this->continueSignup($phone, $message, $context);
            return;
        }

        $this->startSignup($phone);
    }

    protected function startSignup(string $phone): void
    {
        ConversationContext::setForPhone($phone, 'signup_flow', ['step' => 'ask_name'], now()->addMinutes(15));

        $this->whatsapp->sendText($phone, "Looks like you're new here! 👋 What's your name?");
    }

    protected function continueSignup(string $phone, string $message, ConversationContext $context): void
    {
        $step = $context->payload['step'] ?? 'ask_name';

        match ($step) {
            'ask_name' => $this->signupCollectName($phone, $message, $context),
            'confirm' => $this->signupConfirm($phone, $message, $context),
            default => $this->startSignup($phone),
        };
    }

    protected function signupCollectName(string $phone, string $message, ConversationContext $context): void
    {
        $name = trim($message);

        if ($name === '' || mb_strlen($name) > 100) {
            $this->whatsapp->sendText($phone, "Sorry, I didn't catch that — what's your name?");
            return;
        }

        ConversationContext::setForPhone($phone, 'signup_flow', ['step' => 'confirm', 'name' => $name], now()->addMinutes(15));

        $this->whatsapp->sendText($phone, "Thanks, {$name}! Should I create your account? Reply YES to confirm, or NO to start over.");
    }

    protected function signupConfirm(string $phone, string $message, ConversationContext $context): void
    {
        $normalized = strtolower(trim($message));

        if (in_array($normalized, ['no', 'n', 'cancel', 'restart'])) {
            $context->delete();
            $this->whatsapp->sendText($phone, "No problem — message me again whenever you're ready to sign up.");
            return;
        }

        if (!in_array($normalized, ['yes', 'y', 'confirm', 'yeah', 'yep'])) {
            $this->whatsapp->sendText($phone, "Reply YES to confirm, or NO to start over.");
            return;
        }

        $name = $context->payload['name'] ?? null;

        if (!$name) {
            $context->delete();
            $this->startSignup($phone);
            return;
        }

        // Guard against a dashboard signup landing on this same number while
        // the WhatsApp flow was mid-confirmation.
        if (User::where('phone', $phone)->exists()) {
            $context->delete();
            $this->whatsapp->sendText($phone, "Looks like this number's already registered — try logging in on the dashboard.");
            return;
        }

        User::create([
            'name' => $name,
            'phone' => $phone,
            'email' => null,
            'password' => Hash::make(Str::random(40)),
            'status' => 'pending',
        ]);

        $context->delete();

        $this->whatsapp->sendText($phone, "You're all set, {$name}! 🎉 Just waiting on approval — I'll message you the moment you're in.");
    }
    /**
     * Catches "remind me to pay rent 15000 every month on the 5th" style
     * declarations. Checked before handleStatementRequest/handleQuery so a
     * recurring-payment setup request doesn't get misclassified as a
     * document request or a spending question. The LLM extracts raw fields
     * only (name/amount/category/interval/day) — next_due_date is always
     * computed deterministically by RecurringPayment::calculateInitialDueDate,
     * never by the LLM, same "LLM extracts, Laravel computes" principle as
     * the rest of the app.
     */
    protected function handleRecurringPaymentRequest(User $user, string $message): bool
    {
        try {
            $intent = $this->recurringPaymentParser->parse($message);
        } catch (\Throwable $e) {
            return false;
        }

        if (!$intent['recognized'] || $intent['confidence'] < 0.7) {
            return false;
        }

        if (!$intent['amount'] || !$intent['name']) {
            $this->whatsapp->sendText(
                $user->phone,
                "I caught that you want a recurring reminder, but I'm missing the name or amount. Try something like \"remind me to pay rent 15000 every month on the 5th.\""
            );
            return true;
        }

        $category = TransactionCategory::matchLoose($intent['category'] ?? 'Other')?->value ?? 'Other';

        $nextDueDate = RecurringPayment::calculateInitialDueDate(
            $intent['interval_unit'],
            $intent['interval_count'],
            $intent['day_of_month'],
            $intent['day_of_week'],
        );

        ConversationContext::setFor(
            $user->id,
            'recurring_add_confirm',
            [
                'name' => $intent['name'],
                'amount' => $intent['amount'],
                'category' => $category,
                'interval_unit' => $intent['interval_unit'],
                'interval_count' => $intent['interval_count'],
                'day_of_month' => $intent['day_of_month'],
                'day_of_week' => $intent['day_of_week'],
                'next_due_date' => $nextDueDate->toDateString(),
            ],
            now()->addMinutes(5),
        );

        $intervalLabel = $intent['interval_count'] > 1
            ? "every {$intent['interval_count']} {$intent['interval_unit']}s"
            : "every {$intent['interval_unit']}";

        $this->whatsapp->sendText(
            $user->phone,
            "Set up a reminder for {$intent['name']} — " . $this->formatter->money($intent['amount'])
                . " {$intervalLabel}, category {$category}. First reminder: " . $nextDueDate->format('M j, Y')
                . ". Reply YES to confirm, or NO to cancel."
        );

        return true;
    }

    protected function resolveRecurringAddConfirm(User $user, string $message, ConversationContext $context): void
    {
        $normalized = strtolower(trim($message));

        if (in_array($normalized, ['yes', 'y', 'confirm', 'yeah', 'yep'])) {
            $this->executeRecurringAdd($user, $context);
            return;
        }

        if (in_array($normalized, ['no', 'n', 'cancel', 'nah', 'nope'])) {
            $context->delete();
            $this->whatsapp->sendText($user->phone, "No problem, I didn't set that up. Send it again whenever you're ready.");
            return;
        }

        // Not a clear yes/no — leave the context alive (it'll expire on its
        // own in 5 minutes) rather than guessing.
        $this->whatsapp->sendText($user->phone, "Reply YES to confirm the reminder, or NO to cancel.");
    }

    protected function executeRecurringAdd(User $user, ConversationContext $context): void
    {
        $payload = $context->payload;
        $context->delete();

        $recurring = RecurringPayment::create([
            'user_id' => $user->id,
            'name' => $payload['name'],
            'amount' => $payload['amount'],
            'category' => $payload['category'],
            'interval_unit' => $payload['interval_unit'],
            'interval_count' => $payload['interval_count'],
            'day_of_month' => $payload['day_of_month'],
            'day_of_week' => $payload['day_of_week'],
            'next_due_date' => $payload['next_due_date'],
        ]);

        $this->whatsapp->sendText(
        $user->phone,
        "✅ All set — I'll remind you about {$recurring->name} on " . $recurring->next_due_date->format('M j, Y') . '.'
    );
}

    /**
     * Resolves a `recurring_confirm` context opened by recurring:remind.
     * YES logs a real Transaction against the recurring payment's amount/
     * category and advances the cycle; NO just advances the cycle without
     * logging anything (the user is telling us this occurrence didn't
     * happen, not that we misread anything — so there's nothing to correct,
     * only a cycle to move past).
     */
    protected function resolveRecurringConfirm(User $user, string $message, ConversationContext $context): void
    {
        $normalized = strtolower(trim($message));

        $recurring = RecurringPayment::find($context->payload['recurring_payment_id']);

        if (!$recurring) {
            // Deleted from the dashboard while a reminder was in flight.
            $context->delete();
            return;
        }

        if (in_array($normalized, ['yes', 'y', 'confirm', 'yeah', 'yep', 'paid'])) {
            $this->executeRecurringConfirmPaid($user, $recurring, $context);
            return;
        }

        if (in_array($normalized, ['no', 'n', 'skip', 'nah', 'nope'])) {
            $this->executeRecurringConfirmSkip($user, $recurring, $context);
            return;
        }

        // Not a clear yes/no — leave the context alive rather than
        // guessing; recurring:remind will retry tomorrow regardless.
        $this->whatsapp->sendText($user->phone, "Reply YES if you paid {$recurring->name}, or NO to skip logging it this time.");
    }

    protected function executeRecurringConfirmPaid(User $user, RecurringPayment $recurring, ConversationContext $context): void
    {
        $context->delete();

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'type' => 'debit',
            'amount' => $recurring->amount,
            'category' => $recurring->category,
            'note' => "Recurring: {$recurring->name}",
            'source' => 'whatsapp',
            'status' => 'confirmed',
            'transaction_date' => Carbon::today(),
        ]);

        $recurring->missed_last_reminder = false;
        $recurring->advanceToNextCycle();

        ConversationContext::setFor($user->id, 'last_transaction', ['transaction_id' => $transaction->id]);

        $this->whatsapp->sendText(
            $user->phone,
            '✅ Logged ' . $this->formatter->transaction($transaction) . ". Next {$recurring->name} reminder: " . $recurring->next_due_date->format('M j, Y') . '.'
        );
    }

    protected function executeRecurringConfirmSkip(User $user, RecurringPayment $recurring, ConversationContext $context): void
    {
        $context->delete();

        $recurring->missed_last_reminder = false;
        $recurring->advanceToNextCycle();

        $this->whatsapp->sendText(
            $user->phone,
            "Okay, skipped. Next {$recurring->name} reminder: " . $recurring->next_due_date->format('M j, Y') . '.'
        );
    }
}
