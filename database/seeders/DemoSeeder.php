<?php

namespace Database\Seeders;

use App\Enums\TransactionCategory;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public const DEMO_PHONE = '+910000000000';
    public const DEMO_EMAIL = 'demo@whatspend.local';

    public function run(): void
    {
        $user = $this->findOrCreateDemoUser();
        $this->seedFor($user);
    }

    public function findOrCreateDemoUser(): User
    {
        return User::firstOrCreate(
            ['phone' => self::DEMO_PHONE],
            [
                'name' => 'Demo User',
                'email' => self::DEMO_EMAIL,
                'password' => bcrypt(str()->random(32)),
                'status' => 'approved',
                'is_admin' => false,
                'is_demo' => true,
            ]
        );
    }

    /**
     * Seeds a fresh, "populated" state for the given demo user. Assumes
     * the caller has already cleared out any prior transactions/budgets
     * for this user (see ResetDemoEnvironment) — this method only adds.
     */
    public function seedFor(User $user): void
    {
        $this->seedTransactions($user);
        $this->seedTriggeredBudget($user);
        $this->seedRecurringPattern($user);
    }

    protected function seedTransactions(User $user): void
    {
        $debitCategories = [
            TransactionCategory::Groceries,
            TransactionCategory::FoodDining,
            TransactionCategory::Transport,
            TransactionCategory::Shopping,
            TransactionCategory::Entertainment,
            TransactionCategory::Health,
            TransactionCategory::Bills,
        ];

        // Spread realistic-looking debit transactions across the last 4 months.
        for ($monthsAgo = 3; $monthsAgo >= 0; $monthsAgo--) {
            $monthStart = now()->subMonths($monthsAgo)->startOfMonth();

            foreach ($debitCategories as $category) {
                $count = rand(2, 5);

                for ($i = 0; $i < $count; $i++) {
                    Transaction::create([
                        'user_id' => $user->id,
                        'type' => 'debit',
                        'amount' => $this->realisticAmount($category),
                        'category' => $category->value,
                        'note' => $this->sampleNote($category),
                        'source' => 'manual',
                        'status' => 'confirmed',
                        'transaction_date' => $monthStart->copy()->addDays(rand(0, 27)),
                    ]);
                }
            }

            // One salary credit per month.
            Transaction::create([
                'user_id' => $user->id,
                'type' => 'credit',
                'amount' => 45000,
                'category' => TransactionCategory::Salary->value,
                'note' => 'Monthly salary',
                'source' => 'manual',
                'status' => 'confirmed',
                'transaction_date' => $monthStart->copy()->addDay(),
            ]);
        }
    }

    protected function realisticAmount(TransactionCategory $category): float
    {
        return match ($category) {
            TransactionCategory::Groceries => rand(400, 1800),
            TransactionCategory::FoodDining => rand(150, 900),
            TransactionCategory::Transport => rand(80, 600),
            TransactionCategory::Shopping => rand(500, 4000),
            TransactionCategory::Entertainment => rand(200, 1200),
            TransactionCategory::Health => rand(200, 2500),
            TransactionCategory::Bills => rand(600, 2200),
            default => rand(200, 1000),
        };
    }

    protected function sampleNote(TransactionCategory $category): string
    {
        return match ($category) {
            TransactionCategory::Groceries => 'Weekly groceries',
            TransactionCategory::FoodDining => 'Dinner out',
            TransactionCategory::Transport => 'Cab / fuel',
            TransactionCategory::Shopping => 'Online order',
            TransactionCategory::Entertainment => 'Movie / streaming',
            TransactionCategory::Health => 'Pharmacy',
            TransactionCategory::Bills => 'Utility bill',
            default => 'Expense',
        };
    }

    /**
     * A Groceries budget already crossed its alert threshold this month —
     * last_alerted_period is set to the current period so the dashboard
     * shows it as an already-fired alert, matching what a real triggered
     * budget looks like rather than one that's merely close.
     */
    protected function seedTriggeredBudget(User $user): void
    {
        $currentMonthGroceries = Transaction::where('user_id', $user->id)
            ->where('category', TransactionCategory::Groceries->value)
            ->where('type', 'debit')
            ->whereYear('transaction_date', now()->year)
            ->whereMonth('transaction_date', now()->month)
            ->sum('amount');

        // Set the limit comfortably below what's already been spent this
        // month, so the "triggered" state is real, not just plausible.
        $limit = max(500, $currentMonthGroceries * 0.7);

        Budget::create([
            'user_id' => $user->id,
            'category' => TransactionCategory::Groceries->value,
            'monthly_limit' => round($limit, 2),
            'alert_threshold_percent' => 80,
            'last_alerted_period' => now()->format('Y-m'),
        ]);
    }

    /**
     * Rent-like recurring expense: same amount, same category, once a
     * month for the last 3 months — comfortably inside
     * RecurringExpenseDetector's 20–40 day interval / 10% tolerance /
     * 2+ distinct calendar months rules.
     */
    protected function seedRecurringPattern(User $user): void
    {
        for ($monthsAgo = 2; $monthsAgo >= 0; $monthsAgo--) {
            Transaction::create([
                'user_id' => $user->id,
                'type' => 'debit',
                'amount' => 15000,
                'category' => TransactionCategory::Rent->value,
                'note' => 'Monthly rent',
                'source' => 'manual',
                'status' => 'confirmed',
                'transaction_date' => now()->subMonths($monthsAgo)->startOfMonth()->addDays(4),
            ]);
        }
    }
}