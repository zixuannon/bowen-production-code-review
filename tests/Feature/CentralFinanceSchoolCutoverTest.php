<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\CentralFinanceSchoolCutoverService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

final class CentralFinanceSchoolCutoverTest extends TestCase
{
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = tempnam(sys_get_temp_dir(), 'cf_cutover_');
        Config::set('database.connections.mysql', ['driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('mysql');
        Schema::connection('mysql')->create('schools', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->string('code'), $t->string('database_name')->nullable(), $t->softDeletes(), $t->timestamps()]);
        Schema::connection('mysql')->create('central_finance_ledger_entries', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('school_id'), $t->timestamps()]);
        (require database_path('migrations/2026_08_21_000005_create_central_finance_school_cutovers.php'))->up();
        DB::connection('mysql')->table('schools')->insert([
            ['id' => 1, 'name' => 'Zixuan', 'code' => 'SCH202615', 'database_name' => 'local_zixuan', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Timecity', 'code' => 'SCH202619', 'database_name' => 'local_timecity', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql'); @unlink($this->database); parent::tearDown();
    }

    public function test_zixuan_can_cut_over_while_timecity_remains_legacy_and_tenant_writes_are_isolated(): void
    {
        $cutovers = app(CentralFinanceSchoolCutoverService::class);
        $zixuan = School::on('mysql')->findOrFail(1);

        $cutovers->transition($zixuan, 'ready');
        $this->assertFalse($cutovers->allowsCentralWrites(1));
        $cutovers->assertTenantFinanceWritesAllowed($this->tenantActor(1));
        $this->assertSame('legacy', $cutovers->statusForSchool(2));
        $cutovers->transition($zixuan, 'central');

        $this->assertTrue($cutovers->allowsCentralWrites(1));
        $this->assertFalse($cutovers->allowsCentralWrites(2));
        $this->expectException(AuthorizationException::class);
        $cutovers->assertTenantFinanceWritesAllowed($this->tenantActor(1));
    }

    public function test_timecity_legacy_tenant_writes_remain_allowed_after_zixuan_cutover(): void
    {
        $cutovers = app(CentralFinanceSchoolCutoverService::class);
        $school = School::on('mysql')->findOrFail(1);
        $cutovers->transition($school, 'ready');
        $cutovers->transition($school, 'central');
        $cutovers->assertTenantFinanceWritesAllowed($this->tenantActor(2));
        $this->assertSame('legacy', $cutovers->statusForSchool(2));
    }

    public function test_central_to_legacy_rollback_is_allowed_only_before_real_central_financial_activity(): void
    {
        $cutovers = app(CentralFinanceSchoolCutoverService::class);
        $school = School::on('mysql')->findOrFail(1);
        $cutovers->transition($school, 'ready');
        $cutovers->transition($school, 'central');
        $this->assertSame('legacy', $cutovers->transition($school, 'legacy')->status);

        $cutovers->transition($school, 'ready');
        $cutovers->transition($school, 'central');
        DB::connection('mysql')->table('central_finance_ledger_entries')->insert(['school_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(LogicException::class);
        $cutovers->transition($school, 'legacy');
    }

    public function test_cutover_migration_is_additive_and_reversible(): void
    {
        $migration = require database_path('migrations/2026_08_21_000005_create_central_finance_school_cutovers.php');
        $migration->down();
        $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_school_cutovers'));
        $migration->up();
        $this->assertTrue(Schema::connection('mysql')->hasTable('central_finance_school_cutovers'));
        $this->assertTrue(Schema::connection('mysql')->hasTable('schools'));
    }

    private function tenantActor(int $schoolId): User
    {
        $actor = new User();
        $actor->id = 900 + $schoolId;
        $actor->school_id = $schoolId;
        return $actor;
    }
}
