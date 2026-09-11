<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class RuntimeLinkGuardTest extends TestCase
{
    private string $root;
    private string $shared;
    private string $baseline;

    protected function setUp(): void
    {
        parent::setUp();
        $id = bin2hex(random_bytes(8));
        $this->root = sys_get_temp_dir()."/round5-release-$id";
        $this->shared = sys_get_temp_dir()."/round5-shared-$id";
        mkdir($this->root.'/public', 0775, true); mkdir($this->root.'/bootstrap/cache', 0775, true);
        mkdir($this->shared.'/storage/app/public', 0775, true); file_put_contents($this->shared.'/.env', 'APP_ENV=testing');
        $this->root = realpath($this->root); $this->shared = realpath($this->shared);
        symlink($this->shared.'/.env', $this->root.'/.env');
        symlink($this->shared.'/storage', $this->root.'/storage');
        symlink($this->shared.'/storage/app/public', $this->root.'/public/storage');
        $user = posix_getpwuid(posix_geteuid())['name']; $group = posix_getgrgid(posix_getegid())['name'];
        $this->baseline = $this->shared.'/baseline.json';
        file_put_contents($this->baseline, json_encode([
            'shared_env_target'=>$this->shared.'/.env', 'shared_storage_target'=>$this->shared.'/storage',
            'shared_public_storage_target'=>$this->shared.'/storage/app/public', 'runtime_user'=>$user, 'runtime_group'=>$group,
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root); $this->removeDirectory($this->shared);
        parent::tearDown();
    }

    public function test_exact_targets_owner_and_writability_pass(): void
    {
        $process = $this->guard(); $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('RUNTIME_LINK_GUARD_PASS', $process->getOutput());
    }

    public function test_wrong_and_broken_symlinks_fail_closed(): void
    {
        unlink($this->root.'/.env'); symlink($this->shared.'/storage', $this->root.'/.env');
        $wrong = $this->guard(); $wrong->run();
        $this->assertSame(1, $wrong->getExitCode());
        $this->assertStringContainsString('target mismatch', $wrong->getErrorOutput());

        unlink($this->root.'/.env'); symlink($this->shared.'/missing', $this->root.'/.env');
        $broken = $this->guard(); $broken->run();
        $this->assertSame(1, $broken->getExitCode());
        $this->assertStringContainsString('broken', $broken->getErrorOutput());
    }

    public function test_wrong_owner_contract_and_nonwritable_target_fail_closed(): void
    {
        $contract = json_decode((string) file_get_contents($this->baseline), true, 512, JSON_THROW_ON_ERROR);
        $contract['runtime_group'] = 'not-the-owner';
        file_put_contents($this->baseline, json_encode($contract, JSON_THROW_ON_ERROR));
        $owner = $this->guard(); $owner->run();
        $this->assertSame(1, $owner->getExitCode());
        $this->assertStringContainsString('target owner', $owner->getErrorOutput());

        $contract['runtime_group'] = posix_getgrgid(posix_getegid())['name'];
        file_put_contents($this->baseline, json_encode($contract, JSON_THROW_ON_ERROR));
        chmod($this->shared.'/storage', 0555);
        $writable = $this->guard(); $writable->run();
        $this->assertSame(1, $writable->getExitCode());
        $this->assertStringContainsString('not -w', $writable->getErrorOutput());
        chmod($this->shared.'/storage', 0775);
    }

    public function test_release_guard_and_builder_use_the_exact_runtime_contract(): void
    {
        $guard = file_get_contents(base_path('scripts/production/verify_release_guard.sh'));
        $deploy = file_get_contents(base_path('scripts/production/deploy_release.sh'));
        $this->assertStringContainsString('verify_runtime_links.sh', $guard);
        $this->assertStringContainsString('verify_runtime_links.sh', $deploy);
        $this->assertStringContainsString('shared_public_storage_target', $deploy);
        $this->assertStringNotContainsString('readlink "$active_link/.env"', $deploy);
        $this->assertStringNotContainsString('readlink "$active_link/storage"', $deploy);
    }

    private function guard(): Process
    {
        return new Process(['bash', base_path('scripts/production/verify_runtime_links.sh'), $this->root, $this->baseline], null, [
            'JSON_PHP_BIN'=>PHP_BINARY, 'RUNTIME_GUARD_SKIP_ASSETS'=>'1',
        ]);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isLink() || $item->isFile()) unlink($item->getPathname());
            else rmdir($item->getPathname());
        }
        rmdir($path);
    }
}
