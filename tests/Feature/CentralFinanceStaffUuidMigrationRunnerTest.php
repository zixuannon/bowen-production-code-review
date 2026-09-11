<?php

namespace Tests\Feature;

use App\Console\Commands\MigrateCentralFinanceStaffUuid;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CentralFinanceStaffUuidMigrationRunnerTest extends TestCase
{
    /** @var array<string, mixed> */ private array $mysql;
    /** @var array<string, mixed> */ private array $school;
    /** @var array<string, string> */ private array $trusted;
    /** @var list<string> */ private array $files = [];
    private string $central;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mysql = config('database.connections.mysql'); $this->school = config('database.connections.school');
        $this->central = $this->database();
        $this->trusted = ['MMBOWEN01'=>$this->database(),'SCH202616'=>$this->database(),'SCH202619'=>$this->database()];
        $sqlite = static fn (string $database): array => ['driver'=>'sqlite','database'=>$database,'prefix'=>'','foreign_key_constraints'=>true];
        Config::set('database.connections.mysql', $sqlite($this->central)); Config::set('database.connections.school', $sqlite($this->trusted['MMBOWEN01']));
        Config::set('finance_release.central_finance_staff_uuid_tenants', $this->trusted); Config::set('finance_release.central_finance_staff_uuid_canary', 'MMBOWEN01');
        DB::purge('mysql'); DB::purge('school'); DB::setDefaultConnection('mysql');
        Schema::connection('mysql')->create('schools', function ($table): void {
            $table->increments('id');
            $table->string('code')->unique();
            $table->string('database_name')->unique();
            $table->timestamp('deleted_at')->nullable();
        });
        foreach ($this->trusted as $code => $database) {
            DB::connection('mysql')->table('schools')->insert(['code'=>$code,'database_name'=>$database]);
            Config::set('database.connections.school.database', $database); DB::purge('school');
            Schema::connection('school')->create('migrations', function ($table): void {
                $table->increments('id');
                $table->string('migration');
                $table->integer('batch');
            });
            Schema::connection('school')->create('users', fn ($table) => $table->increments('id')->unique());
        }
        Config::set('database.connections.school.database', $this->trusted['MMBOWEN01']); DB::purge('school');
    }

    protected function tearDown(): void
    {
        DB::purge('school'); DB::purge('mysql'); Config::set('database.connections.mysql',$this->mysql); Config::set('database.connections.school',$this->school);
        Config::set('finance_release.central_finance_staff_uuid_tenants',null); Config::set('finance_release.central_finance_staff_uuid_canary',null); DB::setDefaultConnection('mysql');
        foreach ($this->files as $file) @unlink($file); parent::tearDown();
    }

    public function test_fixed_production_allowlist_excludes_demo_and_has_only_the_uuid_migration(): void
    {
        $this->assertSame(['MMBOWEN01','SCH202616','SCH202619','SCH202620','SCH202621','SCH202631','SCH202632'], array_keys(MigrateCentralFinanceStaffUuid::PRODUCTION_TENANTS));
        $this->assertArrayNotHasKey('SCH20261', MigrateCentralFinanceStaffUuid::PRODUCTION_TENANTS);
        $this->assertSame('2026_08_24_000003_add_central_finance_source_uuid_to_users_table', MigrateCentralFinanceStaffUuid::MIGRATION);
        $source = file_get_contents(app_path('Console/Commands/MigrateCentralFinanceStaffUuid.php'));
        $this->assertStringContainsString("database_path('migrations/schools/' . self::MIGRATION . '.php')", $source);
        $this->assertStringNotContainsString('migrate:school', $source);
        $this->assertStringNotContainsString('migrate:fresh', $source);
    }

    public function test_default_is_zero_write_and_database_names_demo_and_unknown_codes_are_rejected(): void
    {
        $this->artisan('finance:migrate-central-finance-staff-uuid')->assertExitCode(0);
        foreach ($this->trusted as $database) $this->assertFalse($this->column($database));
        $this->artisan('finance:migrate-central-finance-staff-uuid',['--tenant'=>[$this->trusted['MMBOWEN01']]])->assertExitCode(1);
        $this->artisan('finance:migrate-central-finance-staff-uuid',['--tenant'=>['SCH20261']])->assertExitCode(1);
        $this->artisan('finance:migrate-central-finance-staff-uuid',['--tenant'=>['UNKNOWN']])->assertExitCode(1);
    }

    public function test_remaining_tenants_are_refused_before_the_canary_then_execute_only_after_schema_verified_canary(): void
    {
        $this->assertNotSame($this->trusted['MMBOWEN01'], $this->trusted['SCH202616']);
        $this->assertFalse($this->column($this->trusted['MMBOWEN01']));
        $this->assertSame([], $this->migrations($this->trusted['MMBOWEN01']));
        $this->artisan('finance:migrate-central-finance-staff-uuid',['--tenant'=>['SCH202616','SCH202619'],'--execute'=>true])->assertExitCode(1);
        $this->assertFalse($this->column($this->trusted['SCH202616']));

        $this->artisan('finance:migrate-central-finance-staff-uuid',['--tenant'=>['MMBOWEN01'],'--execute'=>true])->assertExitCode(0);
        $this->assertTrue($this->column($this->trusted['MMBOWEN01']));
        $this->assertSame([MigrateCentralFinanceStaffUuid::MIGRATION], $this->migrations($this->trusted['MMBOWEN01']));

        $this->artisan('finance:migrate-central-finance-staff-uuid',['--tenant'=>['SCH202616','SCH202619'],'--execute'=>true])->assertExitCode(0);
        foreach (['SCH202616','SCH202619'] as $code) {
            $this->assertTrue($this->column($this->trusted[$code]));
            $this->assertSame([MigrateCentralFinanceStaffUuid::MIGRATION], $this->migrations($this->trusted[$code]));
        }
    }

    public function test_partial_or_unexpected_schema_state_stops_without_writes(): void
    {
        $this->on($this->trusted['MMBOWEN01'], fn () => Schema::connection('school')->table('users', fn ($table) => $table->uuid('central_finance_source_uuid')->nullable()));
        $this->artisan('finance:migrate-central-finance-staff-uuid',['--tenant'=>['MMBOWEN01']])->assertExitCode(1);
        $this->assertSame([], $this->migrations($this->trusted['MMBOWEN01']));
    }

    public function test_remaining_tenants_are_all_preflighted_before_any_post_canary_write(): void
    {
        $this->artisan('finance:migrate-central-finance-staff-uuid',['--tenant'=>['MMBOWEN01'],'--execute'=>true])->assertExitCode(0);
        $this->on($this->trusted['SCH202619'], fn () => Schema::connection('school')->table('users', fn ($table) => $table->uuid('central_finance_source_uuid')->nullable()));

        $this->artisan('finance:migrate-central-finance-staff-uuid',['--tenant'=>['SCH202616','SCH202619'],'--execute'=>true])->assertExitCode(1);

        $this->assertFalse($this->column($this->trusted['SCH202616']));
        $this->assertSame([], $this->migrations($this->trusted['SCH202616']));
    }

    private function database(): string { $file=tempnam(sys_get_temp_dir(),'central-staff-uuid-'); $this->files[]=$file; return $file; }
    private function on(string $database, callable $callback): mixed { Config::set('database.connections.school.database',$database); DB::purge('school'); return $callback(); }
    private function column(string $database): bool { return $this->on($database, fn (): bool => Schema::connection('school')->hasColumn('users','central_finance_source_uuid')); }
    /** @return list<string> */ private function migrations(string $database): array { return $this->on($database, fn (): array => DB::connection('school')->table('migrations')->where('migration',MigrateCentralFinanceStaffUuid::MIGRATION)->pluck('migration')->all()); }
}
