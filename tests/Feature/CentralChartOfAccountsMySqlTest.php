<?php

namespace Tests\Feature;

use App\Console\Commands\MigrateCentralChartOfAccounts;
use App\Services\CentralChartOfAccountsSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Opt-in local MySQL rehearsal. Never connects to an application database. */
final class CentralChartOfAccountsMySqlTest extends TestCase
{
    public function test_fresh_additive_migrations_preserve_legacy_rows_and_enforce_indexes_and_foreign_keys(): void
    {
        if (getenv('COA_MYSQL_REHEARSAL') !== '1') $this->markTestSkipped('Run COA_MYSQL_REHEARSAL=1 against local disposable MySQL.');
        $database='eschool_coa_disposable_'.bin2hex(random_bytes(6));
        $admin=new \PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            Config::set('database.connections.mysql',['driver'=>'mysql','host'=>'127.0.0.1','port'=>3306,'database'=>$database,'username'=>'root','password'=>'','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true]);
            DB::purge('mysql'); DB::setDefaultConnection('mysql');
            Schema::create('migrations', function (Blueprint $t): void { $t->id(); $t->string('migration'); $t->integer('batch'); });
            Schema::create('schools',fn (Blueprint $t)=>$t->id());
            Schema::create('finance_groups',fn (Blueprint $t)=>$t->id());
            Schema::create('central_finance_user_school_scopes',fn (Blueprint $t)=>$t->boolean('can_operate'));
            (require database_path('migrations/2026_08_21_000002_create_central_finance_operating_documents.php'))->up();
            (require database_path('migrations/2026_09_02_000002_add_category_codes_for_group_finance_import.php'))->up();
            Schema::create('central_finance_fund_accounts',function (Blueprint $t): void {
                $t->id(); $t->uuid('account_uuid'); $t->unsignedBigInteger('group_id');
                $t->unsignedBigInteger('school_id')->nullable(); $t->string('owner_type');
                $t->string('account_code'); $t->string('account_name'); $t->string('currency',3);
                $t->decimal('opening_balance',20,4);
            });
            DB::table('schools')->insert(['id'=>17]); DB::table('finance_groups')->insert(['id'=>1]);
            DB::table('central_finance_categories')->insert(['id'=>41,'category_uuid'=>'11111111-1111-4111-8111-111111111111','school_id'=>17,'type'=>'income','name'=>'Legacy tuition','category_code'=>'CATEGORY-LEGACY','is_active'=>true,'created_at'=>'2026-09-01 00:00:00','updated_at'=>'2026-09-01 00:00:00']);
            $before=(array)DB::table('central_finance_categories')->first();
            DB::table('central_finance_fund_accounts')->insert([
                'account_uuid'=>'33333333-3333-4333-8333-333333333333','group_id'=>1,'school_id'=>null,
                'owner_type'=>'hq','account_code'=>'B-0001','account_name'=>'Disposable account','currency'=>'MMK','opening_balance'=>'123.4500',
            ]);
            $accountBefore=(array)DB::table('central_finance_fund_accounts')->first();
            $schemaBefore=[Schema::getColumns('central_finance_categories'),Schema::getColumns('central_finance_fund_accounts'),Schema::getTables()];
            $this->artisan('finance:migrate-central-chart-of-accounts')->assertExitCode(0);
            $this->assertSame($schemaBefore,[Schema::getColumns('central_finance_categories'),Schema::getColumns('central_finance_fund_accounts'),Schema::getTables()]);
            $this->assertSame(0,DB::table('migrations')->count());
            $this->assertSame($before,(array)DB::table('central_finance_categories')->first());
            $this->assertSame($accountBefore,(array)DB::table('central_finance_fund_accounts')->first());
            $this->artisan('finance:migrate-central-chart-of-accounts',['--execute'=>true])->assertExitCode(0);
            $this->assertSame(MigrateCentralChartOfAccounts::MIGRATIONS,DB::table('migrations')->orderBy('id')->pluck('migration')->all());
            $registryBefore=DB::table('migrations')->orderBy('id')->get()->toJson();
            $completeBefore=[Schema::getColumns('central_finance_categories'),Schema::getColumns('central_finance_fund_accounts'),Schema::getIndexes('central_finance_categories')];
            $this->artisan('finance:migrate-central-chart-of-accounts',['--execute'=>true])->assertExitCode(0);
            $this->assertSame($registryBefore,DB::table('migrations')->orderBy('id')->get()->toJson());
            $this->assertSame($completeBefore,[Schema::getColumns('central_finance_categories'),Schema::getColumns('central_finance_fund_accounts'),Schema::getIndexes('central_finance_categories')]);
            CentralChartOfAccountsSchema::assertComplete();
            $after=(array)DB::table('central_finance_categories')->first(); unset($after['group_id']);
            $this->assertSame(hash('sha256',json_encode($before)),hash('sha256',json_encode($after)));
            $this->assertTrue(Schema::hasColumn('central_finance_fund_accounts','owner_holder'));
            $accountAfter=(array)DB::table('central_finance_fund_accounts')->first(); unset($accountAfter['owner_holder']);
            $this->assertSame($accountBefore,$accountAfter);
            DB::table('central_finance_categories')->insert(['id'=>42,'category_uuid'=>'22222222-2222-4222-8222-222222222222','school_id'=>null,'group_id'=>1,'type'=>'asset','name'=>'Cash control','category_code'=>'0101','is_active'=>true]);
            $this->assertSame('0101',DB::table('central_finance_categories')->where('id',42)->value('category_code'));
            DB::table('central_finance_category_school_allocations')->insert(['category_id'=>42,'school_id'=>17,'is_active'=>true]);
            try { DB::table('central_finance_category_school_allocations')->insert(['category_id'=>42,'school_id'=>17,'is_active'=>true]); $this->fail('Duplicate allocation accepted'); } catch (\Illuminate\Database\QueryException $e) { $this->assertSame('23000',$e->errorInfo[0]); }
            try { DB::table('central_finance_category_school_allocations')->insert(['category_id'=>42,'school_id'=>99,'is_active'=>true]); $this->fail('Orphan allocation accepted'); } catch (\Illuminate\Database\QueryException $e) { $this->assertSame('23000',$e->errorInfo[0]); }
            Schema::table('central_finance_categories',fn (Blueprint $t)=>$t->index('group_id','coa_test_group_fk_index'));
            Schema::table('central_finance_categories',fn (Blueprint $t)=>$t->dropUnique('coa_group_code_unique'));
            try { CentralChartOfAccountsSchema::assertComplete(); $this->fail('Partial unique index accepted'); } catch (\RuntimeException $e) { $this->assertStringContainsString('unique constraint',$e->getMessage()); }
            $partialBefore=[Schema::getIndexes('central_finance_categories'),DB::table('central_finance_categories')->orderBy('id')->get()->toJson()];
            $this->artisan('finance:migrate-central-chart-of-accounts',['--execute'=>true])->assertExitCode(1);
            $this->assertSame($partialBefore,[Schema::getIndexes('central_finance_categories'),DB::table('central_finance_categories')->orderBy('id')->get()->toJson()]);
            $this->assertSame($registryBefore,DB::table('migrations')->orderBy('id')->get()->toJson());
        } finally {
            DB::purge('mysql');
            if (preg_match('/^eschool_coa_disposable_[a-f0-9]{12}$/',$database)) $admin->exec('DROP DATABASE `'.$database.'`');
        }
    }
}
