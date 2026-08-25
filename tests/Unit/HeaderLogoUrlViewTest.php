<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class HeaderLogoUrlViewTest extends TestCase
{
    public function test_header_normalizes_stored_logo_paths_without_changing_external_or_storage_urls(): void
    {
        $view = (string) file_get_contents(resource_path('views/layouts/header.blade.php'));

        $this->assertStringContainsString('Storage::url($path)', $view);
        $this->assertStringContainsString("['http://', 'https://', '/']", $view);
        $this->assertStringContainsString('Str::startsWith($path, \'storage/\')', $view);
        $this->assertStringContainsString('$horizontalPath ?:', $view);
        $this->assertStringContainsString('$verticalPath ?:', $view);
        $this->assertStringContainsString('Storage::url($path)', Blade::compileString($view));
    }

    /** @dataProvider logoPaths */
    public function test_logo_url_contract(string $path, string $fallback, string $expected): void
    {
        $template = <<<'BLADE'
@php
    $resolveLogoUrl = static function ($path, string $fallback): string {
        $path = trim((string) $path);
        if ($path === '') return asset($fallback);
        if (\Illuminate\Support\Str::startsWith($path, ['http://', 'https://', '/'])) return $path;
        if (\Illuminate\Support\Str::startsWith($path, 'storage/')) return '/'.$path;
        return \Illuminate\Support\Facades\Storage::url($path);
    };
@endphp
{{ $resolveLogoUrl($path, $fallback) }}
BLADE;

        $url = trim(Blade::render($template, compact('path', 'fallback')));
        $this->assertStringEndsWith($expected, $url);
    }

    public static function logoPaths(): array
    {
        return [
            'storage-relative' => ['school/zixuan.jpg', '/assets/horizontal-logo2.svg', '/storage/school/zixuan.jpg'],
            'already-public-storage' => ['/storage/school/zixuan.jpg', '/assets/horizontal-logo2.svg', '/storage/school/zixuan.jpg'],
            'storage-without-leading-slash' => ['storage/school/zixuan.jpg', '/assets/horizontal-logo2.svg', '/storage/school/zixuan.jpg'],
            'external-url' => ['https://cdn.example.test/zixuan.jpg', '/assets/horizontal-logo2.svg', 'https://cdn.example.test/zixuan.jpg'],
            'empty-fallback' => ['', '/assets/horizontal-logo2.svg', '/assets/horizontal-logo2.svg'],
        ];
    }
}
