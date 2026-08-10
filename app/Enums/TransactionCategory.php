<?php

namespace App\Enums;

enum TransactionCategory: string
{
    // Debit categories
    case Bills = 'Bills';
    case Groceries = 'Groceries';
    case FoodDining = 'Food & Dining';
    case Transport = 'Transport';
    case Shopping = 'Shopping';
    case Entertainment = 'Entertainment';
    case Health = 'Health';
    case Rent = 'Rent';

    // Credit categories
    case Salary = 'Salary';
    case Freelance = 'Freelance';
    case Refund = 'Refund';
    case Gift = 'Gift';

    // Shared
    case Other = 'Other';

    public static function debitCategories(): array
    {
        return [
            self::Bills,
            self::Groceries,
            self::FoodDining,
            self::Transport,
            self::Shopping,
            self::Entertainment,
            self::Health,
            self::Rent,
            self::Other,
        ];
    }

    public static function creditCategories(): array
    {
        return [
            self::Salary,
            self::Freelance,
            self::Refund,
            self::Gift,
            self::Other,
        ];
    }

    public static function matchLoose(string $input): ?self
    {
        $normalized = strtolower(trim($input));

        foreach (self::cases() as $case) {
            if (strtolower($case->value) === $normalized) {
                return $case;
            }
        }

        return null;
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Bills => '🧾',
            self::Groceries => '🛒',
            self::FoodDining => '🍽️',
            self::Transport => '🚗',
            self::Shopping => '🛍️',
            self::Entertainment => '🎬',
            self::Health => '💊',
            self::Rent => '🏠',
            self::Salary => '💰',
            self::Freelance => '💼',
            self::Refund => '↩️',
            self::Gift => '🎁',
            self::Other => '📌',
        };
    }
}