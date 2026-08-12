<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Deliberately narrow production runner: it cannot discover or run other migrations. */
class FinanceP2P3MigrationSafety extends Command
{
    public const TENANTS = ['eschool_saas_1_demo', 'eschool_saas_15_zixuan', 'eschool_saas_17_bahan', 'eschool_saas_19_timecitys', 'eschool_saas_20_', 'eschool_saas_21_', 'eschool_saas_31_zixuanyang', 'eschool_saas_32_'];
    public const MIGRATIONS = ['2026_08_11_000001_create_bank_account_user_table', '2026_08_12_000001_create_fund_handovers_table'];
    protected $signature = 'finance:p2-p3-migration-safety
        {--tenant=* : Exact approved tenant database name(s); defaults to all eight}
        {--execute : Apply only the two fixed migrations}
        {--rollback-batch= : Roll back one exact P2/P3-only batch before P2/P3 activity exists}';
    protected $description = 'Verify or safely apply only Finance P2/P3 tenant migrations';

    public function handle(): int
    {
        if ($this->option('execute') && $this->option('rollback-batch')) return $this->fail('Choose execute or rollback, not both.');
        $tenants = $this->option('tenant') ?: self::TENANTS;
        if (array_diff($tenants, self::TENANTS) || count($tenants) !== count(array_unique($tenants))) return $this->fail('Tenant selection must contain unique names from the fixed approved allowlist only.');
        foreach ($tenants as $tenant) {
            if (!$this->connect($tenant) || !$this->baseSchema()) return self::FAILURE;
            $state = $this->state();
            if ($state === 'partial') return $this->fail("[$tenant] partial P2/P3 migration state; stop and forward-fix.");
            $this->line("[$tenant] P2/P3 state: $state");
            if ($this->option('rollback-batch')) {
                if (!$this->rollback((int) $this->option('rollback-batch'), $tenant)) return self::FAILURE;
                continue;
            }
            // Verification-only mode intentionally accepts a clean `none`
            // state before the approved cutover, and confirms `both` only
            // after a completed cutover. A partial state is always fatal.
            if (!$this->option('execute')) {
                if ($state === 'both' && !$this->schemaComplete()) return $this->fail("[$tenant] P2/P3 schema verification failed.");
                continue;
            }
            if ($this->option('execute') && $state === 'none') {
                $code = Artisan::call('migrate', ['--database' => 'school', '--path' => $this->paths(), '--realpath' => true, '--force' => true]);
                $this->output->write(Artisan::output());
                if ($code !== self::SUCCESS) return $this->fail("[$tenant] targeted P2/P3 migration failed; stop before next tenant.");
            }
            if ($this->state() !== 'both' || !$this->schemaComplete()) return $this->fail("[$tenant] P2/P3 schema verification failed.");
            $batch = DB::connection('school')->table('migrations')->whereIn('migration', self::MIGRATIONS)->pluck('batch')->unique();
            if ($batch->count() !== 1) return $this->fail("[$tenant] P2/P3 migrations are not one isolated batch.");
            $this->info("[$tenant] verified P2/P3-only batch {$batch->first()}.");
        }
        return self::SUCCESS;
    }

    public static function classify(array $applied): string
    {
        $count = count(array_intersect(self::MIGRATIONS, $applied));
        return $count === 0 ? 'none' : ($count === count(self::MIGRATIONS) ? 'both' : 'partial');
    }
    public static function validTenantSelection(array $tenants): bool
    {
        return $tenants !== [] && !array_diff($tenants, self::TENANTS) && count($tenants) === count(array_unique($tenants));
    }
    public static function paths(): array
    {
        return array_map(fn ($migration) => database_path("migrations/schools/$migration.php"), self::MIGRATIONS);
    }
    private function connect(string $tenant): bool
    {
        try { Config::set('database.connections.school.database', $tenant); DB::purge('school'); DB::connection('school')->getPdo(); return true; }
        catch (\Throwable $e) { return $this->fail("[$tenant] tenant connection failed."); }
    }
    private function baseSchema(): bool
    {
        foreach (['migrations', 'users', 'bank_accounts', 'bank_transfers'] as $table) if (!Schema::connection('school')->hasTable($table)) return $this->fail("Required base table missing: $table");
        return true;
    }
    private function state(): string { return self::classify(DB::connection('school')->table('migrations')->whereIn('migration', self::MIGRATIONS)->pluck('migration')->all()); }
    private function schemaComplete(): bool
    {
        $schema = Schema::connection('school');
        if (!$schema->hasTable('bank_account_user') || !$schema->hasTable('fund_handovers')) return false;
        $pivot = DB::connection('school')->select("SHOW INDEX FROM bank_account_user WHERE Key_name = 'bank_account_user_bank_account_id_user_id_unique'");
        $foreign = DB::connection('school')->select("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fund_handovers' AND REFERENCED_TABLE_NAME IS NOT NULL");
        return count($pivot) === 2 && count($foreign) >= 5;
    }
    private function rollback(int $batch, string $tenant): bool
    {
        $actual = DB::connection('school')->table('migrations')->where('batch', $batch)->orderBy('migration')->pluck('migration')->all(); $expected = self::MIGRATIONS; sort($expected);
        if ($batch < 1 || $actual !== $expected) return $this->fail("[$tenant] rollback batch is not exactly P2/P3; refused.");
        if (DB::connection('school')->table('bank_account_user')->exists() || DB::connection('school')->table('fund_handovers')->exists()) return $this->fail("[$tenant] P2/P3 activity exists; schema rollback is unsafe. Forward-fix only.");
        $code = Artisan::call('migrate:rollback', ['--database' => 'school', '--path' => self::paths(), '--realpath' => true, '--batch' => $batch, '--force' => true]); $this->output->write(Artisan::output());
        return ($code === self::SUCCESS && $this->state() === 'none')
            ? true
            : $this->fail("[$tenant] rollback did not complete.");
    }
    private function fail(string $message): int { $this->error($message); return self::FAILURE; }
}
