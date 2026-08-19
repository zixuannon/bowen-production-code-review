<?php

namespace App\Support;

use LogicException;

/** Fixed local-only tenant allowlist guard shared by synthetic QA commands. */
final class LocalQaTenantGuard
{
    /** @param array<string,string> $allowed */
    public static function assertEnvironment(string $environment, string $appUrl, string $centralDatabase, array $allowed): void
    {
        if (!in_array($environment, ['local', 'testing'], true)) throw new LogicException('Synthetic QA tooling is local/test-only.');
        if (!in_array(parse_url($appUrl, PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true)) throw new LogicException('Synthetic QA tooling requires localhost APP_URL.');
        if (self::forbidden($centralDatabase)) throw new LogicException('Synthetic QA tooling refused the central database.');
        foreach ($allowed as $code => $database) self::assertTenant($code, $database, $allowed);
    }

    /** @param array<string,string> $allowed */
    public static function assertTenant(string $code, string $database, array $allowed): void
    {
        if (($allowed[$code] ?? null) !== $database || self::forbidden($database)) throw new LogicException('Only fixed local QA tenants may be rebuilt.');
    }

    private static function forbidden(string $database): bool
    {
        return $database === '' || $database === 'eschool_staging' || $database === 'sql_43_160_241_126' || str_starts_with($database, 'eschool_saas_') || str_starts_with($database, 'eschool_staging_') || str_starts_with($database, 'sql_43_160_241_126_');
    }
}
