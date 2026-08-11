<?php

namespace App\Support;

use LogicException;

/**
 * Identity boundary for destructive synthetic QA work.  The database names
 * are deliberately constants: callers must never be able to redirect this
 * tooling at another tenant.
 */
final class LocalBowenQaGuard
{
    public const SCHOOL_CODE = 'BOWEN_QA';
    public const TENANT_DATABASE = 'eschool_local_bowen_qa';

    public static function assertEnvironment(string $environment, string $appUrl, string $centralDatabase): void
    {
        if (!in_array($environment, ['local', 'testing'], true)) {
            throw new LogicException('BOWEN_QA tooling is local/test-only. APP_ENV must be local or testing.');
        }

        $host = parse_url($appUrl, PHP_URL_HOST);
        if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new LogicException('BOWEN_QA tooling requires an APP_URL on localhost, 127.0.0.1, or ::1.');
        }

        if (self::isForbiddenDatabase($centralDatabase)) {
            throw new LogicException('BOWEN_QA tooling refused the configured central database.');
        }
    }

    public static function assertTenant(string $schoolCode, string $database): void
    {
        if ($schoolCode !== self::SCHOOL_CODE || $database !== self::TENANT_DATABASE || self::isForbiddenDatabase($database)) {
            throw new LogicException('Only the fixed local BOWEN_QA tenant database may be reset or seeded.');
        }
    }

    private static function isForbiddenDatabase(string $database): bool
    {
        return $database === ''
            || $database === 'sql_43_160_241_126'
            || $database === 'eschool_staging'
            || str_starts_with($database, 'eschool_saas_')
            || str_starts_with($database, 'eschool_staging_')
            || str_starts_with($database, 'sql_43_160_241_126_');
    }
}
