<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact DECIMAL(20,4) arithmetic for Central Finance obligation documents.
 *
 * Physical-money services predate this class and use their existing contract.
 * Layer 3 obligations must never use PHP floating-point arithmetic.
 */
final class CentralFinanceDecimal
{
    public const SCALE = 4;

    public static function normalize(string|int $value): string
    {
        $value = trim((string) $value);
        if (!preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,4})?$/', $value)) {
            throw new InvalidArgumentException('A four-decimal financial amount is required.');
        }

        return bcadd($value, '0', self::SCALE);
    }

    public static function add(string|int $left, string|int $right): string
    {
        return bcadd(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function subtract(string|int $left, string|int $right): string
    {
        return bcsub(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function compare(string|int $left, string|int $right): int
    {
        return bccomp(self::normalize($left), self::normalize($right), self::SCALE);
    }

    /** Percentage is expressed as a human-readable value, e.g. 10 = 10%. */
    public static function percentageOf(string|int $amount, string|int $percentage): string
    {
        $amount = self::normalize($amount);
        $percentage = self::normalize($percentage);
        if (self::compare($percentage, '0') <= 0 || self::compare($percentage, '100') > 0) {
            throw new InvalidArgumentException('Promotion percentage must be greater than zero and no more than 100.');
        }

        return bcdiv(bcmul($amount, $percentage, 8), '100', self::SCALE);
    }
}
