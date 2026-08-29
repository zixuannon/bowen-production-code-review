<?php

namespace Tests\Unit;

use App\Services\CentralFinanceReceiptViewModelFactory;
use Tests\TestCase;

final class CentralFinanceReceiptLogoUrlTest extends TestCase
{
    /** @dataProvider logoPaths */
    public function test_receipt_logo_url_uses_the_same_safe_storage_contract(string $path, string $expected): void
    {
        $method = new \ReflectionMethod(CentralFinanceReceiptViewModelFactory::class, 'logoUrl');
        $url = $method->invoke(app(CentralFinanceReceiptViewModelFactory::class), $path);

        if (str_starts_with($expected, 'http')) {
            $this->assertSame($expected, $url);
            return;
        }

        $this->assertSame($expected, parse_url($url, PHP_URL_PATH));
    }

    public static function logoPaths(): array
    {
        return [
            'relative-public-path' => ['school/zixuan.jpg', '/storage/school/zixuan.jpg'],
            'already-public-storage' => ['/storage/school/zixuan.jpg', '/storage/school/zixuan.jpg'],
            'storage-without-leading-slash' => ['storage/school/zixuan.jpg', '/storage/school/zixuan.jpg'],
            'external-url' => ['https://cdn.example.test/zixuan.jpg', 'https://cdn.example.test/zixuan.jpg'],
            'fallback-only-without-a-logo' => ['', '/assets/vertical-logo.svg'],
        ];
    }
}
