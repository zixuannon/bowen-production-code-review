<?php

namespace App\Support;


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
        LocalQaTenantGuard::assertEnvironment($environment, $appUrl, $centralDatabase, [self::SCHOOL_CODE => self::TENANT_DATABASE]);
    }

    public static function assertTenant(string $schoolCode, string $database): void
    {
        LocalQaTenantGuard::assertTenant($schoolCode, $database, [self::SCHOOL_CODE => self::TENANT_DATABASE]);
    }
}
