<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Canonical accounting-date contract for Central Finance.
 *
 * Financial source rows retain their existing date columns (paid_at,
 * income_date, expense_date and entry_date).  This value object prevents a
 * controller or import from silently treating an audit timestamp as a
 * reporting date, and makes the allowed input format unambiguous.
 */
final class CentralFinanceBusinessDate
{
    public const TIMEZONE = 'Asia/Yangon';

    public static function parse(string $value): CarbonImmutable
    {
        $value = trim($value);
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, self::TIMEZONE);
        $errors = CarbonImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Transaction Date must be a real YYYY-MM-DD date.');
        }

        // A future accounting date would make period reporting appear to have
        // money that has not happened yet.  Historical backdating remains
        // allowed and is preserved in the document/audit trail.
        if ($date->greaterThan(self::today())) {
            throw new InvalidArgumentException('Transaction Date cannot be in the future.');
        }

        return $date;
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }
}
