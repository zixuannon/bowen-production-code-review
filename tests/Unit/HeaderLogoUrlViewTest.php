<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class HeaderLogoUrlViewTest extends TestCase
{
    public function test_header_only_emits_existing_local_logo_paths(): void
    {
        $view = (string) file_get_contents(resource_path('views/layouts/header.blade.php'));

        $this->assertStringContainsString("Storage::disk('public')->exists", $view);
        $this->assertStringContainsString("Storage::disk('public')->url", $view);
        $this->assertStringContainsString("['http://', 'https://']", $view);
        $this->assertStringContainsString("parse_url(\$path, PHP_URL_HOST)", $view);
        $this->assertStringContainsString("Str::startsWith(\$urlPath, '/storage/')", $view);
        $this->assertStringContainsString("Str::after(\$relativePath, 'storage/')", $view);
        $this->assertStringContainsString('$horizontalPath ?:', $view);
        $this->assertStringContainsString('$verticalPath ?:', $view);
        $this->assertStringContainsString("Storage::disk('public')->exists", Blade::compileString($view));
    }

    public function test_logo_url_contract_falls_back_before_a_missing_storage_request(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('school/zixuan.jpg', 'image');

        $template = <<<'BLADE'
@php
    $resolveLogoUrl = static function ($path, string $fallback): string {
        $path = trim((string) $path);
        if ($path === '') {
            return asset($fallback);
        }
        if (\Illuminate\Support\Str::startsWith($path, ['http://', 'https://'])) {
            $urlHost = strtolower((string) parse_url($path, PHP_URL_HOST));
            $appHost = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
            $requestHost = strtolower((string) request()->getHost());
            $urlPath = (string) parse_url($path, PHP_URL_PATH);
            if (! in_array($urlHost, array_filter([$appHost, $requestHost]), true)
                || ! \Illuminate\Support\Str::startsWith($urlPath, '/storage/')) {
                return $path;
            }
            $path = $urlPath;
        }
        $relativePath = ltrim($path, '/');
        if (\Illuminate\Support\Str::startsWith($relativePath, 'assets/')) {
            return is_file(public_path($relativePath)) ? asset('/'.$relativePath) : asset($fallback);
        }
        $relativePath = \Illuminate\Support\Str::after($relativePath, 'storage/');
        if (! \Illuminate\Support\Facades\Storage::disk('public')->exists($relativePath)) {
            return asset($fallback);
        }
        return \Illuminate\Support\Facades\Storage::disk('public')->url($relativePath);
    };
@endphp
{{ $resolveLogoUrl($path, $fallback) }}
BLADE;

        $render = static fn (string $path): string => trim(Blade::render($template, [
            'path' => $path,
            'fallback' => '/assets/horizontal-logo2.svg',
        ]));

        $this->assertStringEndsWith('/storage/school/zixuan.jpg', $render('school/zixuan.jpg'));
        $this->assertStringEndsWith('/storage/school/zixuan.jpg', $render('/storage/school/zixuan.jpg'));
        $this->assertStringEndsWith('/assets/horizontal-logo2.svg', $render('/storage/missing.svg'));
        $this->assertStringEndsWith('/assets/horizontal-logo2.svg', $render(url('/storage/missing.svg')));
        $this->assertStringEndsWith('/storage/school/zixuan.jpg', $render(url('/storage/school/zixuan.jpg')));
        $this->assertStringEndsWith('/assets/horizontal-logo2.svg', $render(''));
        $this->assertSame('https://cdn.example.test/zixuan.jpg', $render('https://cdn.example.test/zixuan.jpg'));
    }

    public function test_favicon_falls_back_before_a_missing_local_storage_request(): void
    {
        $view = (string) file_get_contents(resource_path('views/layouts/include.blade.php'));

        $this->assertStringContainsString("Storage::disk('public')->exists", $view);
        $this->assertStringContainsString("parse_url(\$faviconPath, PHP_URL_HOST)", $view);
        $this->assertStringContainsString("asset('/assets/vertical-logo.svg')", $view);
        $this->assertStringContainsString('href="{{ $faviconUrl }}"', $view);
    }
}
