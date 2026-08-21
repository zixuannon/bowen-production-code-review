<?php

namespace Tests\Feature;

use App\Models\School;
use App\Services\CentralFinanceGateASchoolScope;
use App\Services\CentralFinanceLegacyCutoverService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CentralFinanceLegacyCutoverTest extends TestCase
{
    private array $mysql; private array $school; private string $central; private string $a; private string $b;
    protected function setUp(): void
    {
        parent::setUp(); $this->mysql=config('database.connections.mysql'); $this->school=config('database.connections.school');
        $this->central=tempnam(sys_get_temp_dir(),'cf_cutover_c_'); $this->a=tempnam(sys_get_temp_dir(),'cf_cutover_a_'); $this->b=tempnam(sys_get_temp_dir(),'cf_cutover_b_');
        Config::set('database.connections.mysql',$this->sqlite($this->central)); Config::set('database.connections.school',$this->sqlite($this->a)); DB::purge('mysql'); DB::purge('school');
        Schema::connection('mysql')->create('schools', function ($t): void { $t->increments('id'); $t->string('code'); $t->string('database_name'); $t->boolean('installed'); $t->string('status')->nullable(); $t->timestamp('deleted_at')->nullable(); });
        Schema::connection('mysql')->create('central_finance_legacy_migration_records', function ($t): void { $t->increments('id'); $t->string('migration_key')->unique(); $t->unsignedInteger('school_id'); $t->string('source_type'); $t->string('source_id'); $t->string('source_hash'); $t->string('status'); $t->string('central_type')->nullable(); $t->unsignedInteger('central_id')->nullable(); $t->text('metadata')->nullable(); $t->timestamp('migrated_at')->nullable(); $t->timestamps(); });
        foreach ([$this->a,$this->b] as $file) $this->tenant($file);
        DB::connection('mysql')->table('schools')->insert([
            ['id'=>1,'code'=>'SCH202615','database_name'=>$this->a,'installed'=>true,'status'=>'active'],
            ['id'=>2,'code'=>'SCH202616','database_name'=>$this->b,'installed'=>true,'status'=>'active'],
            ['id'=>3,'code'=>'SCH202619','database_name'=>$this->a,'installed'=>true,'status'=>'active'],
            ['id'=>4,'code'=>'SCH202620','database_name'=>$this->a,'installed'=>true,'status'=>'active'],
            ['id'=>5,'code'=>'SCH202621','database_name'=>$this->a,'installed'=>true,'status'=>'active'],
            ['id'=>6,'code'=>'SCH202631','database_name'=>$this->a,'installed'=>true,'status'=>'active'],
            ['id'=>7,'code'=>'SCH202632','database_name'=>$this->a,'installed'=>true,'status'=>'active'],
            ['id'=>8,'code'=>'SCH20261','database_name'=>$this->a,'installed'=>true,'status'=>'inactive'],
        ]);
        $this->seedTenant($this->a, 1); $this->seedTenant($this->b, 2);
    }
    protected function tearDown(): void { DB::purge('mysql'); DB::purge('school'); Config::set('database.connections.mysql',$this->mysql); Config::set('database.connections.school',$this->school); @unlink($this->central);@unlink($this->a);@unlink($this->b); parent::tearDown(); }
    public function test_two_school_dry_run_and_reconciliation_are_read_only(): void
    {
        $service=app(CentralFinanceLegacyCutoverService::class); $a=School::on('mysql')->findOrFail(1); $b=School::on('mysql')->findOrFail(2);
        $before=DB::connection('mysql')->table('central_finance_legacy_migration_records')->count(); $planA=$service->dryRun($a); $planB=$service->dryRun($b);
        $this->assertSame($before,DB::connection('mysql')->table('central_finance_legacy_migration_records')->count());
        $this->assertCount(6,$planA['sources']); $this->assertNotSame($planA['hash'],$planB['hash']);
        $first=$service->reconcile($a); $again=$service->reconcile($a);
        $this->assertSame($first, $again);
        $this->assertSame($first['expected'], count($first['missing']));
        $this->assertSame($before,DB::connection('mysql')->table('central_finance_legacy_migration_records')->count());
    }
    public function test_registry_database_is_trusted_and_production_style_database_is_rejected(): void
    {
        $school=School::on('mysql')->findOrFail(1); $school->database_name=$this->b; $plan=app(CentralFinanceLegacyCutoverService::class)->dryRun($school);
        $this->assertSame(1,$plan['school']['id']);
        DB::connection('mysql')->table('schools')->where('id',1)->update(['database_name'=>'eschool_saas_unsafe']);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class); app(CentralFinanceLegacyCutoverService::class)->dryRun(School::on('mysql')->findOrFail(1));
    }
    public function test_gate_a_scope_is_fixed_to_active_codes_and_never_demo_or_arbitrary_school(): void
    {
        $scope = app(CentralFinanceGateASchoolScope::class);
        $this->assertSame(['SCH202615', 'SCH202616'], $scope->resolve(['SCH202615', 'SCH202616'])->pluck('code')->all());
        $this->assertSame(CentralFinanceGateASchoolScope::ACTIVE_CODES, $scope->resolve()->pluck('code')->all());
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $scope->resolve(['SCH20261']);
    }

    public function test_read_only_command_has_no_execute_switch_and_never_writes_manifest_rows(): void
    {
        $before = DB::connection('mysql')->table('central_finance_legacy_migration_records')->count();
        $this->artisan('central-finance:legacy-cutover', ['--school-code' => ['SCH202615', 'SCH202616']])
            ->assertSuccessful();
        $this->assertSame($before, DB::connection('mysql')->table('central_finance_legacy_migration_records')->count());
        $command = Artisan::all()['central-finance:legacy-cutover'];
        $this->assertFalse($command->getDefinition()->hasOption('execute'));
    }
    private function tenant(string $file): void { Config::set('database.connections.school.database',$file); DB::purge('school'); foreach(['bank_accounts','fees_paids','expenses','other_incomes','bank_transfers','fund_handovers'] as $table) Schema::connection('school')->create($table,function ($t): void { $t->increments('id'); $t->string('status')->nullable(); $t->timestamp('deleted_at')->nullable(); }); }
    private function seedTenant(string $file,int $base): void { Config::set('database.connections.school.database',$file); DB::purge('school'); foreach(['bank_accounts','fees_paids','expenses','other_incomes','bank_transfers','fund_handovers'] as $n=>$table) DB::connection('school')->table($table)->insert(['id'=>$base,'status'=>$n==='fund_handovers'?'pending':'completed']); }
    private function sqlite(string $database): array { return ['driver'=>'sqlite','database'=>$database,'prefix'=>'','foreign_key_constraints'=>true]; }
}
