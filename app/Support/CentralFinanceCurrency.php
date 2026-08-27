<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Central Finance stores original-currency amounts only.  This deliberately
 * does not provide exchange rates or a reporting-currency conversion.
 */
final class CentralFinanceCurrency
{
    public const MMK = 'MMK';
    public const USD = 'USD';
    public const CNY = 'CNY';

    /** @var list<string> */
    public const ALLOWED = [self::MMK, self::USD, self::CNY];

    public static function normalize(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (!in_array($currency, self::ALLOWED, true)) {
            throw new InvalidArgumentException('Central Finance supports MMK, USD, and CNY only.');
        }

        return $currency;
    }

    /**
     * Spreadsheet templates are an integration contract, not free text.  A
     * canonical code prevents a silent typo such as `usd` from masquerading
     * as a deliberately selected USD account.
     */
    public static function assertCanonical(string $currency): string
    {
        $raw = trim($currency);
        $normalized = self::normalize($raw);

        if ($raw !== $normalized) {
            throw new InvalidArgumentException('Currency must use the canonical uppercase code: MMK, USD, or CNY.');
        }

        return $normalized;
    }

    public static function same(string $left, string $right): bool
    {
        return self::normalize($left) === self::normalize($right);
    }
}
