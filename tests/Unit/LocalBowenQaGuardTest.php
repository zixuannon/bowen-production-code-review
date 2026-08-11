<?php

namespace Tests\Unit;

use App\Support\LocalBowenQaGuard;
use LogicException;
use PHPUnit\Framework\TestCase;

class LocalBowenQaGuardTest extends TestCase
{
    public function test_accepts_only_local_bowen_qa_identity(): void
    {
        LocalBowenQaGuard::assertEnvironment('local', 'http://127.0.0.1:8000', 'eschool');
        LocalBowenQaGuard::assertTenant('BOWEN_QA', 'eschool_local_bowen_qa');
        $this->assertTrue(true);
    }

    /** @dataProvider rejectedIdentityProvider */
    public function test_rejects_non_local_or_production_like_identities(string $environment, string $url, string $central, string $code, string $tenant): void
    {
        $this->expectException(LogicException::class);
        LocalBowenQaGuard::assertEnvironment($environment, $url, $central);
        LocalBowenQaGuard::assertTenant($code, $tenant);
    }

    public static function rejectedIdentityProvider(): array
    {
        return [
            ['production', 'http://127.0.0.1:8000', 'eschool', 'BOWEN_QA', 'eschool_local_bowen_qa'],
            ['local', 'https://school.mmbowen.com', 'eschool', 'BOWEN_QA', 'eschool_local_bowen_qa'],
            ['local', 'http://127.0.0.1:8000', 'sql_43_160_241_126', 'BOWEN_QA', 'eschool_local_bowen_qa'],
            ['local', 'http://127.0.0.1:8000', 'eschool', 'FINANCE_QA', 'eschool_local_bowen_qa'],
            ['local', 'http://127.0.0.1:8000', 'eschool', 'BOWEN_QA', 'eschool_saas_1_demo'],
        ];
    }
}
