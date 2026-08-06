<?php

namespace App\Helpers;

/**
 * Safe decimal arithmetic using integer minor units.
 *
 * All amounts are stored as DECIMAL(12,2) in the database.
 * To avoid floating-point precision issues, this helper:
 *   1. Converts to integer minor units (e.g. "150000.25" → 15000025)
 *   2. Performs comparisons and arithmetic on integers
 *   3. Converts back to decimal string for display/storage
 *
 * P0 constraint: No float arithmetic. No bcmath dependency.
 */
class MoneyDecimal
{
    /**
     * Scale factor: 10^2 = 100 for DECIMAL(12,2).
     */
    const SCALE = 100;

    /**
     * Normalize a decimal string by stripping extra whitespace and
     * ensuring consistent format with exactly 2 decimal places.
     * Returns "0.00" for empty/whitespace input.
     *
     * Does NOT use toMinorUnits/fromMinorUnits internally to avoid
     * integer overflow on large values when only formatting is needed.
     */
    public static function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '0.00';
        }

        // Handle negative sign
        $negative = false;
        if (str_starts_with($value, '-')) {
            $negative = true;
            $value = substr($value, 1);
        }

        $parts = explode('.', $value);
        $integer = ($parts[0] === '' ? '0' : ltrim($parts[0], '0')) ?: '0';
        $fraction = isset($parts[1]) ? substr($parts[1], 0, 2) : '';
        $fraction = str_pad($fraction, 2, '0');

        return ($negative ? '-' : '') . $integer . '.' . $fraction;
    }

    /**
     * Convert a decimal string to minor units (integer).
     *
     * "150000.25" → 15000025
     * "50000"     → 5000000
     * "0"         → 0
     *
     * Throws \InvalidArgumentException for non-numeric input.
     */
    public static function toMinorUnits(string $value): int
    {
        $value = self::normalize($value);

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException("MoneyDecimal: non-numeric value \"{$value}\"");
        }

        // Use string-based conversion to avoid float precision loss
        $parts = explode('.', $value);
        $integer = $parts[0];
        $fraction = $parts[1] ?? '';

        // Pad or truncate fraction to 2 decimal places
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        // Remove leading zeros from integer part for consistent parsing
        $integer = ltrim($integer, '-'); // keep sign separate
        $sign = str_starts_with($value, '-') ? -1 : 1;
        $integer = $integer === '' ? '0' : $integer;

        $result = (int) ($integer . $fraction);
        return $sign * $result;
    }

    /**
     * Convert minor units back to decimal string.
     *
     * 15000025 → "150000.25"
     * 5000000  → "50000.00"
     */
    public static function fromMinorUnits(int $minorUnits): string
    {
        $negative = $minorUnits < 0;
        $minorUnits = abs($minorUnits);

        $integer = intdiv($minorUnits, self::SCALE);
        $fraction = $minorUnits % self::SCALE;
        $fractionStr = str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);

        $result = $integer . '.' . $fractionStr;
        return $negative ? '-' . $result : $result;
    }

    /**
     * Compare two decimal strings.
     *
     * Returns: -1 if a < b, 0 if a == b, 1 if a > b.
     */
    public static function compare(string $a, string $b): int
    {
        $aInt = self::toMinorUnits($a);
        $bInt = self::toMinorUnits($b);
        return $aInt <=> $bInt;
    }

    /**
     * Add two decimal strings.
     */
    public static function add(string $a, string $b): string
    {
        $aInt = self::toMinorUnits($a);
        $bInt = self::toMinorUnits($b);
        return self::fromMinorUnits($aInt + $bInt);
    }

    /**
     * Subtract b from a (a - b).
     */
    public static function subtract(string $a, string $b): string
    {
        $aInt = self::toMinorUnits($a);
        $bInt = self::toMinorUnits($b);
        return self::fromMinorUnits($aInt - $bInt);
    }

    /**
     * Check if a equals b.
     */
    public static function equals(string $a, string $b): bool
    {
        return self::compare($a, $b) === 0;
    }

    /**
     * Check if a > b.
     */
    public static function greaterThan(string $a, string $b): bool
    {
        return self::compare($a, $b) === 1;
    }

    /**
     * Check if a >= b.
     */
    public static function greaterThanOrEqual(string $a, string $b): bool
    {
        return self::compare($a, $b) >= 0;
    }

    /**
     * Check if a < b.
     */
    public static function lessThan(string $a, string $b): bool
    {
        return self::compare($a, $b) === -1;
    }

    /**
     * Check if a <= b.
     */
    public static function lessThanOrEqual(string $a, string $b): bool
    {
        return self::compare($a, $b) <= 0;
    }

    /**
     * Convert float or int to decimal string for safe storage.
     *
     * This is the ONLY place where float-to-string conversion happens.
     * P0 only supports MMK, so this is typically not needed.
     * Provided for backward compatibility.
     */
    public static function fromFloat(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
