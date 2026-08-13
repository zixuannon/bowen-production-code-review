<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class RequiredReleaseAssetsTest extends TestCase
{
    /** @var array<int, string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            if (is_dir($path)) {
                rmdir($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_release_asset_checker_accepts_present_assets_and_rejects_missing_or_mismatched_assets(): void
    {
        $root = $this->temporaryDirectory();
        mkdir($root . '/public', 0777);
        mkdir($root . '/public/assets', 0777);
        file_put_contents($root . '/public/assets/example.txt', 'expected asset');

        $manifest = $this->temporaryFile();
        file_put_contents($manifest, "public/assets/example.txt\t" . hash('sha256', 'expected asset') . "\tfatal\tSynthetic release asset\n");

        $success = $this->checker($root, $manifest);
        $success->run();
        $this->assertSame(0, $success->getExitCode());
        $this->assertStringContainsString('Required asset verification passed: 1 asset(s).', $success->getOutput());

        unlink($root . '/public/assets/example.txt');
        $missing = $this->checker($root, $manifest);
        $missing->run();
        $this->assertSame(1, $missing->getExitCode());
        $this->assertStringContainsString('MISSING [fatal] public/assets/example.txt', $missing->getErrorOutput());

        file_put_contents($root . '/public/assets/example.txt', 'wrong asset');
        $mismatched = $this->checker($root, $manifest);
        $mismatched->run();
        $this->assertSame(1, $mismatched->getExitCode());
        $this->assertStringContainsString('MISMATCH [fatal] public/assets/example.txt', $mismatched->getErrorOutput());

        unlink($root . '/public/assets/example.txt');
    }

    public function test_release_asset_contract_covers_every_runtime_reference_and_excludes_temporary_assets(): void
    {
        $manifest = file_get_contents(base_path('release/required-assets.tsv'));

        $this->assertIsString($manifest);
        foreach ([
            'public/assets/fonts/NotoSansSC-Regular.ttf',
            'public/assets/fonts/NotoSansSC-Bold.ttf',
            'public/assets/fonts/font-awesome.min.css',
            'public/assets/fonts/fontawesome-webfont.eot',
            'public/assets/fonts/fontawesome-webfont.woff2',
            'public/assets/fonts/fontawesome-webfont.woff',
            'public/assets/fonts/fontawesome-webfont.ttf',
            'public/assets/ckeditor-4/vendor/promise.js',
        ] as $requiredPath) {
            $this->assertStringContainsString($requiredPath, $manifest);
        }

        $this->assertStringNotContainsString('copy.ttf', $manifest);
        $this->assertStringNotContainsString('.upload.tmp', $manifest);
        $this->assertStringNotContainsString('fontawesome-webfont (1).eot', $manifest);
    }

    private function checker(string $root, string $manifest): Process
    {
        return new Process([
            'sh',
            base_path('scripts/release/verify_required_assets.sh'),
            '--root',
            $root,
            '--manifest',
            $manifest,
        ]);
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . '/eschool_assets_' . bin2hex(random_bytes(8));
        mkdir($path, 0777);
        $this->temporaryPaths[] = $path;
        $this->temporaryPaths[] = $path . '/public';
        $this->temporaryPaths[] = $path . '/public/assets';

        return $path;
    }

    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'eschool_assets_');
        $this->temporaryPaths[] = $path;

        return $path;
    }
}
