<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Schema\Blueprint;
use App\Models\User;
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
        $this->assertStringContainsString("Auth::user()->getRawOriginal('school_id')", $view);
        $this->assertStringContainsString("School::on('mysql')->whereKey(\$schoolId)", $view);
        $this->assertStringContainsString("\$brandSchool?->getRawOriginal('logo')", $view);
        $this->assertStringContainsString('SchoolBranding::logoFallbacks', $view);
        $this->assertStringContainsString('asset($horizontalFallback)', $view);
        $this->assertStringContainsString('asset($verticalFallback)', $view);
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

    public function test_real_header_resolution_handles_missing_bowen_files_and_external_school_switch(): void
    {
        $previousConnection = config('database.connections.mysql');
        config(['database.connections.mysql' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('mysql');
        Schema::connection('mysql')->create('schools', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('code');
            $table->string('logo')->nullable();
            $table->softDeletes();
        });
        DB::connection('mysql')->table('schools')->insert([
            ['id' => 15, 'code' => 'MMBOWEN01', 'logo' => 'super-admin/school/missing-zixuan.jpg'],
            ['id' => 17, 'code' => 'MMBOWEN02', 'logo' => 'super-admin/school/missing-bahan.jpg'],
            ['id' => 19, 'code' => 'MMBOWEN03', 'logo' => 'super-admin/school/missing-timecity.jpg'],
            ['id' => 22, 'code' => 'EXTERNAL01', 'logo' => 'school/external.jpg'],
        ]);
        Storage::fake('public');
        Storage::disk('public')->put('school/external.jpg', 'image');

        $header = (string) file_get_contents(resource_path('views/layouts/header.blade.php'));
        $this->assertSame(1, preg_match('/@php(.*?)@endphp/s', $header, $match));
        $headerPhp = $match[1];
        $render = static function (array $data) use ($headerPhp): string {
            extract($data, EXTR_SKIP);
            eval($headerPhp);
            return $horizontalLogo.'|'.$verticalLogo;
        };
        $currentUser = null;
        Auth::shouldReceive('user')->andReturnUsing(static function () use (&$currentUser) { return $currentUser; });

        try {
            foreach ([15, 17, 19] as $schoolId) {
                $user = new User();
                $user->setRawAttributes(['school_id' => $schoolId, 'image' => null], true);
                $currentUser = $user;
                $result = $render([
                    'schoolSettings' => ['horizontal_logo' => '', 'vertical_logo' => 'school/missing-custom.jpg'],
                    'systemSettings' => ['horizontal_logo' => 'school/saas.jpg'],
                ]);
                $this->assertSame(asset('/assets/bowen-school/bowen-logo.jpg').'|'.asset('/assets/bowen-school/bowen-logo.jpg'), $result);
            }

            $user = new User();
            $user->setRawAttributes(['school_id' => 22, 'image' => null], true);
            $currentUser = $user;
            $this->assertSame(22, $user->getRawOriginal('school_id'));
            $this->assertSame(22, Auth::user()->getRawOriginal('school_id'));
            $this->assertSame('EXTERNAL01', \App\Models\School::on('mysql')->whereKey(22)->first(['code', 'logo'])->getRawOriginal('code'));
            $result = $render([
                'schoolSettings' => [],
                'systemSettings' => [],
            ]);
            $this->assertSame(
                Storage::disk('public')->url('school/external.jpg').'|'.Storage::disk('public')->url('school/external.jpg'),
                $result
            );

            $currentUser = (new User())->setRawAttributes(['school_id' => null, 'image' => null], true);
            $result = $render(['schoolSettings' => [], 'systemSettings' => ['horizontal_logo' => 'school/missing-system.jpg']]);
            $this->assertSame(asset('/assets/bowen-school/bowen-logo.jpg').'|'.asset('/assets/bowen-school/bowen-logo.jpg'), $result);

            $currentUser = (new User())->setRawAttributes(['school_id' => 22, 'image' => null], true);
            DB::connection('mysql')->table('schools')->where('id', 22)->update(['logo' => null]);
            $result = $render(['schoolSettings' => [], 'systemSettings' => []]);
            $this->assertSame(asset('/assets/horizontal-logo2.svg').'|'.asset('/assets/vertical-logo.svg'), $result);
        } finally {
            config(['database.connections.mysql' => $previousConnection]);
            DB::purge('mysql');
        }
    }
}
