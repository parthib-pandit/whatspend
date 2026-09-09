<?php

namespace App\Support;

class PhoneNumberNormalizer
{
    /**
     * Matches the format already stored in `users.phone` today:
     * "+91XXXXXXXXXX" — E.164 with the leading '+'. This does NOT split
     * out a separate country_code column (that column doesn't exist yet),
     * it just guarantees every lookup site produces the same string that's
     * actually in the database.
     */
    protected const DEFAULT_COUNTRY_DIGITS = '91';

    public static function toStorageFormat(string $raw, string $defaultCountryDigits = self::DEFAULT_COUNTRY_DIGITS): string
    {
        $cleaned = preg_replace('/[^\d+]/', '', trim($raw));
        $hasPlus = str_starts_with($cleaned, '+');
        $digits = ltrim($cleaned, '+');

        if ($hasPlus) {
            return '+' . $digits;
        }

        // Meta webhook 'from' arrives as e.g. "919876543210" — already has
        // the country code, just missing the '+'.
        if (strlen($digits) > 10 && str_starts_with($digits, $defaultCountryDigits)) {
            return '+' . $digits;
        }

        // Bare national number, possibly with a leading 0 ("09876543210").
        $national = ltrim($digits, '0');

        return '+' . $defaultCountryDigits . $national;
    }
}