<?php

namespace Tests\Feature;

use App\Console\Commands\MigrateBahanTimecitySchoolCodes;
use App\Console\Commands\MigrateKindergartenSchoolCode;
use App\Models\User;
use App\Services\SchoolCodeService;
use App\Services\StudentImportV2Service;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class BahanTimecitySchoolCodeMigrationTest extends TestCase
{
    private array $originalConnection;
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = Config::get('database.connections.mysql');
        $this->database = tempnam(sys_get_temp_dir(), 'centralization-code-map-');
        Config::set('database.connections.mysql', [
            'driver' => 'sqlite', 'database' => $this->database, 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('mysql');

        Schema::connection('mysql')->create('migrations', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        Schema::connection('mysql')->create('schools', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('database_name')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::connection('mysql')->table('schools')->insert([
            ['id' => 15, 'name' => 'Zixuan', 'code' => 'SCH202615', 'database_name' => 'eschool_saas_15_zixuan', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 17, 'name' => 'Bahan', 'code' => 'SCH202616', 'database_name' => 'eschool_saas_17_bahan', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 19, 'name' => 'Timecity', 'code' => 'SCH202619', 'database_name' => 'eschool_saas_19_timecitys', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 20, 'name' => 'Kindergarten', 'code' => 'SCH202620', 'database_name' => 'eschool_saas_20_', 'created_at' => now(), 'updated_at' => now()],
        ]);
        (require database_path('migrations/2026_09_11_000001_finalize_school_code_identity.php'))->up();
        DB::connection('mysql')->table('migrations')->insert([
            'migration' => '2026_09_11_000001_finalize_school_code_identity', 'batch' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        Config::set('database.connections.mysql', $this->originalConnection);
        @unlink($this->database);
        parent::tearDown();
    }

    public function test_read_only_preflight_then_exact_runner_canonicalizes_both_schools(): void
    {
        $this->artisan('centralization:migrate-school-codes')
            ->expectsOutput('bahan_timecity_school_code_mapping=eligible')
            ->assertExitCode(0);
        $this->assertSame('SCH202616', $this->code(17));
        $this->assertSame('SCH202619', $this->code(19));

        $this->artisan('centralization:migrate-school-codes', ['--execute' => true])
            ->expectsOutput('bahan_timecity_school_code_mapping=eligible')
            ->assertExitCode(0);

        $this->assertSame('MMBOWEN02', $this->code(17));
        $this->assertSame('MMBOWEN03', $this->code(19));
        $this->assertNull(app(SchoolCodeService::class)->resolveCanonical('SCH202616'));
        $this->assertNull(app(SchoolCodeService::class)->resolveCanonical('SCH202619'));
        $this->assertSame(17, app(SchoolCodeService::class)->resolveCanonical('mmbowen02')?->id);
        $this->assertSame(19, app(SchoolCodeService::class)->resolveCanonical('MMBOWEN03')?->id);
        $this->assertSame(4, (int) DB::connection('mysql')->table('school_code_sequences')->where('prefix', 'MMBOWEN')->value('next_number'));
        $this->assertDatabaseHas('school_code_history', ['school_id' => 17, 'legacy_code' => 'SCH202616', 'canonical_code' => 'MMBOWEN02'], 'mysql');
        $this->assertDatabaseHas('school_code_history', ['school_id' => 19, 'legacy_code' => 'SCH202619', 'canonical_code' => 'MMBOWEN03'], 'mysql');
        $this->artisan('centralization:migrate-school-codes')
            ->expectsOutput('bahan_timecity_school_code_mapping=complete')
            ->assertExitCode(0);
    }

    public function test_wrong_mapping_fails_closed_with_no_school_or_history_write(): void
    {
        DB::connection('mysql')->table('schools')->where('id', 19)->update(['database_name' => 'wrong_timecity']);

        $this->artisan('centralization:migrate-school-codes', ['--execute' => true])
            ->expectsOutput('bahan_timecity_school_code_mapping=unexpected')
            ->assertExitCode(1);

        $this->assertSame('SCH202616', $this->code(17));
        $this->assertSame('SCH202619', $this->code(19));
        $this->assertSame(1, DB::connection('mysql')->table('school_code_history')->count());
        $this->assertDatabaseMissing('migrations', ['migration' => MigrateBahanTimecitySchoolCodes::CENTRAL_MIGRATION], 'mysql');
    }

    public function test_kindergarten_runner_is_exact_idempotent_and_refuses_a_code_collision(): void
    {
        (require database_path('migrations/'.MigrateBahanTimecitySchoolCodes::CENTRAL_MIGRATION.'.php'))->up();
        $this->artisan('centralization:migrate-kindergarten-school-code')
            ->expectsOutput('kindergarten_school_code_mapping=eligible')
            ->assertExitCode(0);
        $this->artisan('centralization:migrate-kindergarten-school-code', ['--execute' => true])
            ->expectsOutput('kindergarten_school_code_mapping=eligible')
            ->assertExitCode(0);

        $this->assertSame('MMBOWEN04', $this->code(20));
        $this->assertSame(20, app(SchoolCodeService::class)->resolveCanonical('mmbowen04')?->id);
        $this->assertNull(app(SchoolCodeService::class)->resolveCanonical('SCH202620'));
        $this->assertSame(5, (int) DB::connection('mysql')->table('school_code_sequences')->where('prefix', 'MMBOWEN')->value('next_number'));
        $this->assertDatabaseHas('school_code_history', ['school_id' => 20, 'legacy_code' => 'SCH202620', 'canonical_code' => 'MMBOWEN04'], 'mysql');
        $this->artisan('centralization:migrate-kindergarten-school-code')
            ->expectsOutput('kindergarten_school_code_mapping=complete')
            ->assertExitCode(0);

        $this->assertDatabaseHas('migrations', ['migration' => MigrateKindergartenSchoolCode::CENTRAL_MIGRATION], 'mysql');
    }

    public function test_partial_history_is_rejected_and_forward_rollback_is_refused(): void
    {
        DB::connection('mysql')->table('school_code_history')->insert([
            'school_id' => 17, 'legacy_code' => 'SCH202616', 'canonical_code' => 'MMBOWEN02',
            'change_reason' => 'partial fixture', 'changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->artisan('centralization:migrate-school-codes', ['--execute' => true])->assertExitCode(1);
        $this->assertSame('SCH202616', $this->code(17));
        $this->assertSame('SCH202619', $this->code(19));

        DB::connection('mysql')->table('school_code_history')->where('school_id', 17)->delete();
        $migration = require database_path('migrations/'.MigrateBahanTimecitySchoolCodes::CENTRAL_MIGRATION.'.php');
        $migration->up();
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    public function test_soft_deleted_canonical_code_conflict_fails_before_any_write(): void
    {
        DB::connection('mysql')->table('schools')->insert([
            'id' => 21,
            'name' => 'Deleted conflicting identity',
            'code' => 'MMBOWEN02',
            'database_name' => 'deleted_conflict',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);

        $this->artisan('centralization:migrate-school-codes', ['--execute' => true])
            ->expectsOutput('bahan_timecity_school_code_mapping=unexpected')
            ->assertExitCode(1);

        $this->assertSame('SCH202616', $this->code(17));
        $this->assertSame('SCH202619', $this->code(19));
        $this->assertSame(1, DB::connection('mysql')->table('school_code_history')->count());
        $this->assertDatabaseMissing('migrations', ['migration' => MigrateBahanTimecitySchoolCodes::CENTRAL_MIGRATION], 'mysql');
    }

    public function test_source_is_audited_exact_mapping_without_bulk_replace_or_finance_writes(): void
    {
        $source = file_get_contents(database_path('migrations/'.MigrateBahanTimecitySchoolCodes::CENTRAL_MIGRATION.'.php'));
        $this->assertStringContainsString('school_code_history', $source);
        $this->assertStringContainsString('change_reason', $source);
        $this->assertStringNotContainsString('REPLACE(', strtoupper($source));
        $this->assertStringNotContainsString('PAYMENT', strtoupper($source));
        $this->assertStringNotContainsString('RECEIPT', strtoupper($source));
        $this->assertStringNotContainsString('LEDGER', strtoupper($source));
    }

    public function test_student_import_v2_accepts_the_four_approved_canonical_schools(): void
    {
        (require database_path('migrations/'.MigrateBahanTimecitySchoolCodes::CENTRAL_MIGRATION.'.php'))->up();
        (require database_path('migrations/'.MigrateKindergartenSchoolCode::CENTRAL_MIGRATION.'.php'))->up();
        Config::set('student_import_v2.enabled_school_codes', ['MMBOWEN01', 'MMBOWEN02', 'MMBOWEN03', 'MMBOWEN04']);
        $service = app(StudentImportV2Service::class);

        foreach ([
            15 => 'eschool_saas_15_zixuan',
            17 => 'eschool_saas_17_bahan',
            19 => 'eschool_saas_19_timecitys',
            20 => 'eschool_saas_20_',
        ] as $schoolId => $database) {
            session(['school_database_name' => $database]);
            $actor = (new User())->forceFill(['school_id' => $schoolId]);
            $this->assertSame($schoolId, (int) $service->assertPilot($actor)->id);
        }

        DB::connection('mysql')->table('schools')->insert([
            'id' => 21,
            'name' => 'Unapproved School',
            'code' => 'MMBOWEN99',
            'database_name' => 'eschool_saas_20_unapproved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        session(['school_database_name' => 'eschool_saas_20_unapproved']);

        $this->expectException(AuthorizationException::class);
        $service->assertPilot((new User())->forceFill(['school_id' => 21]));
    }

    private function code(int $schoolId): string
    {
        return (string) DB::connection('mysql')->table('schools')->where('id', $schoolId)->value('code');
    }
}
