<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ImmutableReleaseTrustBoundaryTest extends TestCase
{
    private string $root;
    private string $repo;
    private string $releaseRoot;
    private string $activeLink;
    private string $contractSha = '1111111111111111111111111111111111111111';
    private string $branch = 'codex/qa-run-release-test';
    private string $activeSha;
    private string $priorSha;
    private string $candidateASha;
    private string $candidateBSha;
    private string $activeRelease;
    private string $releaseA;
    private string $releaseB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/qa-run-release-boundary-'.bin2hex(random_bytes(8));
        $this->repo = $this->root.'/repo';
        $this->releaseRoot = $this->root.'/releases';
        $this->activeLink = $this->root.'/active';
        mkdir($this->repo, 0775, true);
        mkdir($this->releaseRoot, 0775, true);

        $this->git($this->root, ['init', $this->repo]);
        $this->git($this->repo, ['config', 'user.name', 'QA Release Test']);
        $this->git($this->repo, ['config', 'user.email', 'qa-release@example.invalid']);
        file_put_contents($this->repo.'/source.txt', "baseline\n");
        $this->commit('baseline');
        $this->activeSha = $this->gitHead();
        file_put_contents($this->repo.'/source.txt', "prior\n");
        $this->commit('prior qa candidate');
        $this->priorSha = $this->gitHead();
        file_put_contents($this->repo.'/source.txt', "candidate-a\n");
        $this->commit('candidate a');
        $this->candidateASha = $this->gitHead();
        file_put_contents($this->repo.'/source.txt', "candidate-b\n");
        $this->commit('candidate b');
        $this->candidateBSha = $this->gitHead();

        $this->git($this->repo, ['update-ref', 'refs/remotes/origin/'.$this->branch, $this->candidateASha]);
        $this->activeRelease = $this->makeRelease($this->activeSha, 'eschool-rc-active');
        $this->releaseA = $this->makeRelease($this->candidateASha, 'eschool-rc-test-a');
        $this->releaseB = $this->makeRelease($this->candidateBSha, 'eschool-rc-test-b');
        symlink($this->activeRelease, $this->activeLink);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
        parent::tearDown();
    }

    public function test_two_synthetic_releases_pass_deployment_git_verification_without_runtime_git(): void
    {
        $this->assertVerifierPasses($this->releaseA, $this->activeSha);
        $this->runtimeCheck($this->releaseA, 'staged', 0, $this->candidateASha);

        $this->git($this->repo, ['update-ref', 'refs/remotes/origin/'.$this->branch, $this->candidateBSha]);
        $this->assertVerifierPasses($this->releaseB, $this->candidateASha);
        $this->runtimeCheck($this->releaseB, 'staged', 0, $this->candidateBSha);

        $runtimeVerifier = file_get_contents(base_path('scripts/production/verify_runtime_release.sh'));
        $deploymentVerifier = file_get_contents(base_path('scripts/production/verify_qa_run_release_identity.sh'));
        $migrationWrapper = file_get_contents(base_path('scripts/production/run_guarded_qa_run_migration.sh'));
        $migrationCommand = file_get_contents(base_path('app/Console/Commands/MigrateCentralFinanceQaRuns.php'));
        $this->assertStringNotContainsString('git ', $runtimeVerifier);
        $this->assertStringNotContainsString('safe.directory', $deploymentVerifier.$migrationWrapper);
        $this->assertStringNotContainsString('merge-base', $runtimeVerifier.$migrationCommand);
        $this->assertStringNotContainsString('new Process(', $migrationCommand);
        $this->assertStringContainsString('QA_RUN_VERIFIED_RELEASE_SHA', $migrationCommand);
    }

    public function test_deployment_git_verifier_denies_wrong_sha_manifest_marker_symlink_and_ancestry(): void
    {
        $headFile = $this->git($this->repo, ['rev-parse', '--git-path', 'worktrees/'.basename($this->releaseA).'/HEAD']);
        $gitHead = str_starts_with($headFile, '/') ? $headFile : $this->repo.'/'.$headFile;
        $originalHead = file_get_contents($gitHead);
        try {
            file_put_contents($gitHead, $this->candidateBSha."\n");
            $this->assertVerifierDenies($this->releaseA, $this->priorSha, 'Git HEAD, manifest SHA, and release marker disagree');
        } finally {
            file_put_contents($gitHead, $originalHead);
        }

        file_put_contents($this->releaseA.'/source.txt', "tampered\n");
        $this->assertVerifierDenies($this->releaseA, $this->priorSha, 'tracked application files differ');
        file_put_contents($this->releaseA.'/source.txt', "candidate-a\n");

        $this->git($this->repo, ['update-ref', 'refs/remotes/origin/'.$this->branch, $this->candidateBSha]);
        $this->assertVerifierDenies($this->releaseA, $this->priorSha, 'remote SHA does not match staged candidate');
        $this->git($this->repo, ['update-ref', 'refs/remotes/origin/'.$this->branch, $this->candidateASha]);

        $manifestPath = $this->releaseA.'/.release-manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['commit_sha'] = $this->candidateBSha;
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->assertVerifierDenies($this->releaseA, $this->priorSha, 'candidate marker/manifest identity mismatch');
        $manifest['commit_sha'] = $this->candidateASha;
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

        $markerPath = $this->releaseA.'/.release-commit';
        file_put_contents($markerPath, $this->candidateBSha."\n");
        $this->assertVerifierDenies($this->releaseA, $this->priorSha, 'candidate marker/manifest identity mismatch');
        file_put_contents($markerPath, $this->candidateASha."\n");

        $this->assertVerifierDenies($this->activeRelease, $this->priorSha, 'candidate is already active');
        $releaseAlias = $this->root.'/release-alias';
        symlink($this->releaseA, $releaseAlias);
        $this->assertVerifierDenies($releaseAlias, $this->priorSha, 'candidate release must be a real directory');

        $tree = $this->git($this->repo, ['rev-parse', $this->candidateBSha.'^{tree}']);
        $orphan = $this->git($this->repo, ['commit-tree', $tree, '-m', 'unrelated candidate']);
        $unrelated = $this->makeRelease($orphan, 'eschool-rc-unrelated');
        $this->git($this->repo, ['update-ref', 'refs/remotes/origin/'.$this->branch, $orphan]);
        $this->assertVerifierDenies($unrelated, $this->priorSha, 'candidate does not descend from active Production');
    }

    public function test_runtime_verifier_denies_wrong_symlink_and_unreadable_application_file(): void
    {
        $this->runtimeCheck($this->releaseA, 'staged', 0);

        $wrongSymlink = new Process([
            'bash', base_path('scripts/production/verify_runtime_release.sh'), $this->releaseA, 'active', $this->activeLink, $this->candidateASha,
        ], null, ['PHP_BIN'=>PHP_BINARY, 'RELEASES_ROOT'=>$this->releaseRoot, 'SKIP_ARTISAN_BOOT'=>'1']);
        $wrongSymlink->run();
        $this->assertSame(1, $wrongSymlink->getExitCode());
        $this->assertStringContainsString('active symlink does not target', $wrongSymlink->getErrorOutput());

        $source = $this->releaseA.'/app/RuntimeReadable.php';
        chmod($source, 0000);
        try {
            $unreadable = $this->runtimeCheck($this->releaseA, 'staged', 1);
            $this->assertStringContainsString('application file has no read permission bits', $unreadable->getErrorOutput());
        } finally {
            chmod($source, 0644);
        }
    }

    public function test_qa_run_migration_wrapper_prompts_before_deployment_or_database_checks(): void
    {
        $wrapper = file_get_contents(base_path('scripts/production/run_guarded_qa_run_migration.sh'));
        $prompt = strpos($wrapper, 'IFS= read -r answer');
        $gitVerification = strpos($wrapper, '"$identity_verifier"');
        $runtime = strpos($wrapper, 'finance:qa-runs-migrate');
        $this->assertNotFalse($prompt);
        $this->assertNotFalse($gitVerification);
        $this->assertNotFalse($runtime);
        $this->assertLessThan($gitVerification, $prompt);
        $this->assertLessThan($runtime, $gitVerification);

        $fakeBin = $this->root.'/fake-bin';
        mkdir($fakeBin);
        file_put_contents($fakeBin.'/id', "#!/bin/sh\nprintf '0\\n'\n");
        chmod($fakeBin.'/id', 0755);
        $cancelled = new Process([
            'bash', base_path('scripts/production/run_guarded_qa_run_migration.sh'),
            $this->root.'/missing-release', '--execute',
        ], null, ['PATH'=>$fakeBin.':'.getenv('PATH')]);
        $cancelled->setInput("NO\n");
        $cancelled->run();
        $this->assertSame(1, $cancelled->getExitCode());
        $this->assertStringContainsString('Command cancelled.', $cancelled->getErrorOutput());
        $this->assertStringNotContainsString('candidate release', $cancelled->getErrorOutput());
    }

    private function assertVerifierPasses(string $release, string $priorSha): void
    {
        $process = $this->runIdentityVerifier($release, $priorSha);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('QA_RUN_RELEASE_IDENTITY_PASS:', $process->getOutput());
    }

    private function assertVerifierDenies(string $release, string $priorSha, string $reason): void
    {
        $process = $this->runIdentityVerifier($release, $priorSha);
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString($reason, $process->getErrorOutput());
    }

    private function runIdentityVerifier(string $release, string $priorSha): Process
    {
        $process = new Process([
            'bash', base_path('scripts/production/verify_qa_run_release_identity.sh'),
            $release, $this->activeLink, $this->activeSha, $priorSha, $this->contractSha, $this->branch,
        ], null, ['PHP_BIN'=>PHP_BINARY, 'RELEASES_ROOT'=>$this->releaseRoot]);
        $process->run();
        return $process;
    }

    private function runtimeCheck(string $release, string $mode, int $expectedExit, ?string $expectedSha = null): Process
    {
        $process = new Process([
            'bash', base_path('scripts/production/verify_runtime_release.sh'), $release, $mode, $this->activeLink, $expectedSha ?? $this->candidateASha,
        ], null, ['PHP_BIN'=>PHP_BINARY, 'RELEASES_ROOT'=>$this->releaseRoot, 'SKIP_ARTISAN_BOOT'=>'1']);
        $process->run();
        $this->assertSame($expectedExit, $process->getExitCode(), $process->getErrorOutput());
        return $process;
    }

    private function makeRelease(string $sha, string $name): string
    {
        $path = $this->releaseRoot.'/'.$name;
        $this->git($this->repo, ['worktree', 'add', '--detach', $path, $sha]);
        foreach (['app', 'bootstrap/cache', 'config', 'database', 'routes', 'resources', 'vendor'] as $directory) {
            if (!is_dir($path.'/'.$directory)) mkdir($path.'/'.$directory, 0775, true);
        }
        foreach (['artisan', 'bootstrap/app.php', 'config/app.php', 'database/example.php', 'routes/web.php', 'resources/example.php', 'vendor/autoload.php', 'app/RuntimeReadable.php'] as $file) {
            if (!is_file($path.'/'.$file)) file_put_contents($path.'/'.$file, "<?php // fixture\n");
        }
        chmod($path.'/bootstrap/cache', 0775);
        file_put_contents($path.'/.release-commit', $sha."\n");
        file_put_contents($path.'/.release-manifest.json', json_encode([
            'commit_sha'=>$sha, 'release_name'=>$name, 'baseline_sha'=>$this->contractSha, 'github_ref'=>$this->branch,
        ], JSON_THROW_ON_ERROR));
        return $path;
    }

    private function commit(string $message): void
    {
        $this->git($this->repo, ['add', 'source.txt']);
        $this->git($this->repo, ['commit', '-m', $message]);
    }

    private function gitHead(): string
    {
        return $this->git($this->repo, ['rev-parse', 'HEAD']);
    }

    private function git(string $cwd, array $arguments): string
    {
        $process = new Process(array_merge(['git', '-C', $cwd], $arguments));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        return trim($process->getOutput());
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
