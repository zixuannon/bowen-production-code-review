<?php

namespace App\Support;

final class SchoolBranding
{
    private const BOWEN_SCHOOL_CODES = ['MMBOWEN01', 'MMBOWEN02', 'MMBOWEN03'];

    /** Global Bowen administrators have no school context. */
    public static function logoFallbacks(?string $canonicalCode, bool $hasSchoolContext): array
    {
        $isBowen = ! $hasSchoolContext
            || in_array(strtoupper((string) $canonicalCode), self::BOWEN_SCHOOL_CODES, true);

        return $isBowen
            ? ['/assets/bowen-school/bowen-logo.jpg', '/assets/bowen-school/bowen-logo.jpg']
            : ['/assets/horizontal-logo2.svg', '/assets/vertical-logo.svg'];
    }
}
