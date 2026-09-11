<?php

namespace Tests\Feature;

use App\Console\Commands\FinanceP2P3MigrationSafety;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinanceP2P3MigrationSafetyTest extends TestCase
{
    private array $schoolConnection;

    /** @var list<string> */
    private array $tenantFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->schoolConnection = config('database.connections.school');
        Config::set('database.connections.school', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('school');
    }

    protected function tearDown(): void
    {
        DB::purge('school');
        Config::set('database.connections.school', $this->schoolConnection);
        foreach ($this->tenantFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_tenant_allowlist_rejects_unknown_and_duplicate_tenants(): void
    {
        $this->assertTrue(FinanceP2P3MigrationSafety::validTenantSelection(['eschool_saas_1_demo']));
        $this->assertFalse(FinanceP2P3MigrationSafety::validTenantSelection(['unknown']));
        $this->assertFalse(FinanceP2P3MigrationSafety::validTenantSelection(['eschool_saas_1_demo', 'eschool_saas_1_demo']));
    }
    public function test_migration_path_allowlist_is_exactly_the_two_p2_p3_files(): void
    {
        $this->assertSame(['2026_08_11_000001_create_bank_account_user_table', '2026_08_12_000001_create_fund_handovers_table'], FinanceP2P3MigrationSafety::MIGRATIONS);
        foreach (FinanceP2P3MigrationSafety::paths() as $path) $this->assertFileExists($path);
    }
    public function test_partial_and_already_applied_states_are_distinguished_without_unrelated_migrations(): void
    {
        $this->assertSame('none', FinanceP2P3MigrationSafety::classify([]));
        $this->assertSame('partial', FinanceP2P3MigrationSafety::classify([FinanceP2P3MigrationSafety::MIGRATIONS[0], 'unrelated_migration']));
        $this->assertSame('both', FinanceP2P3MigrationSafety::classify([FinanceP2P3MigrationSafety::MIGRATIONS[0], FinanceP2P3MigrationSafety::MIGRATIONS[1], 'unrelated_migration']));
    }

    public function test_clean_none_state_is_a_valid_verification_state_before_cutover(): void
    {
        $this->assertSame('none', FinanceP2P3MigrationSafety::classify([]));
    }

    public function test_valid_allowlisted_target_passes_without_writing_schema(): void
    {
        $tenant = FinanceP2P3MigrationSafety::TENANTS[0];
        $this->createTenantDatabase($tenant);

        $this->artisan('finance:p2-p3-migration-safety', ['--tenant' => [$tenant]])
            ->expectsOutputToContain("[$tenant] P2/P3 state: none")
            ->assertExitCode(0);

        $this->useTenant($tenant);
        $this->assertSame(0, DB::connection('school')->table('migrations')->count());
        $this->assertFalse(Schema::connection('school')->hasTable('bank_account_user'));
        $this->assertFalse(Schema::connection('school')->hasTable('fund_handovers'));
    }

    public function test_missing_tenant_is_non_zero_and_creates_no_database(): void
    {
        $tenant = FinanceP2P3MigrationSafety::TENANTS[0];
        $path = base_path($tenant);
        $this->assertFileDoesNotExist($path);

        $this->artisan('finance:p2-p3-migration-safety', ['--tenant' => [$tenant], '--execute' => true])
            ->expectsOutputToContain("[$tenant] tenant connection failed")
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($path);
    }

    public function test_later_target_validation_failure_blocks_all_schema_writes(): void
    {
        [$first, $second] = array_slice(FinanceP2P3MigrationSafety::TENANTS, 0, 2);
        $this->createTenantDatabase($first);
        $this->createTenantDatabase($second, false);

        $this->artisan('finance:p2-p3-migration-safety', [
            '--tenant' => [$first, $second],
            '--execute' => true,
        ])->expectsOutputToContain('required base table is missing')
            ->assertExitCode(1);

        $this->useTenant($first);
        $this->assertSame(0, DB::connection('school')->table('migrations')->count());
        $this->assertFalse(Schema::connection('school')->hasTable('bank_account_user'));
        $this->assertFalse(Schema::connection('school')->hasTable('fund_handovers'));

        $this->useTenant($second);
        $this->assertSame(0, DB::connection('school')->table('migrations')->count());
    }

    private function createTenantDatabase(string $tenant, bool $completeBase = true): void
    {
        $path = base_path($tenant);
        $this->assertFileDoesNotExist($path, 'Refusing to overwrite an existing tenant-shaped test file.');
        touch($path);
        $this->tenantFiles[] = $path;
        $this->useTenant($tenant);

        Schema::connection('school')->create('migrations', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        if (!$completeBase) {
            return;
        }
        Schema::connection('school')->create('users', fn (Blueprint $table) => $table->id());
        Schema::connection('school')->create('bank_accounts', fn (Blueprint $table) => $table->id());
        Schema::connection('school')->create('bank_transfers', fn (Blueprint $table) => $table->id());
    }

    private function useTenant(string $tenant): void
    {
        Config::set('database.connections.school.database', base_path($tenant));
        DB::purge('school');
    }
}
