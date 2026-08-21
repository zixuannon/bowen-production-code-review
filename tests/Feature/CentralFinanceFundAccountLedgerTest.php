<?php

namespace Tests\Feature;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceLedgerEntry;
use App\Models\CentralFinanceUser;
use App\Models\School;
use App\Models\User;
use App\Services\CentralFinanceFundAccountBalanceService;
use App\Services\CentralFinanceFundAccountScopeService;
use App\Services\CentralFinanceLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class CentralFinanceFundAccountLedgerTest extends TestCase
{
    private string $centralDatabase;
    private CentralFinanceUser $headFinance;
    private CentralFinanceUser $zixuanAccountant;
    private CentralFinanceFundAccount $hq;
    private CentralFinanceFundAccount $zixuan;
    private CentralFinanceFundAccount $timecity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centralDatabase = tempnam(sys_get_temp_dir(), 'central_finance_ledger_');
        Config::set('database.connections.mysql', ['driver' => 'sqlite', 'database' => $this->centralDatabase, 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        Schema::connection('mysql')->create('schools', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('database_name')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        (require database_path('migrations/2026_08_20_000005_create_central_finance_fund_accounts_and_ledger.php'))->up();

        DB::connection('mysql')->table('schools')->insert([
            ['id' => 1, 'name' => 'Zixuan QA', 'code' => 'CF_ZIXUAN', 'database_name' => 'central-test-a', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Timecity QA', 'code' => 'CF_TIMECITY', 'database_name' => 'central-test-b', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('mysql')->table('users')->insert([
            ['id' => 100, 'first_name' => 'Head', 'last_name' => 'Finance', 'email' => 'head@example.test', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 200, 'first_name' => 'Zixuan', 'last_name' => 'Accountant', 'email' => 'zixuan@example.test', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->headFinance = CentralFinanceUser::on('mysql')->findOrFail(100);
        $this->zixuanAccountant = CentralFinanceUser::on('mysql')->findOrFail(200);
        $this->hq = $this->account('CF-HQ-MMK', 'HQ Main Cash', CentralFinanceFundAccount::OWNER_HQ, null, 1000);
        $this->zixuan = $this->account('CF-ZIX-MMK', 'Zixuan Cash', CentralFinanceFundAccount::OWNER_SCHOOL, 1, 100);
        $this->timecity = $this->account('CF-TIM-MMK', 'Timecity Cash', CentralFinanceFundAccount::OWNER_SCHOOL, 2, 100);

        $this->grant($this->headFinance, $this->hq);
        $this->grant($this->headFinance, $this->zixuan);
        $this->grant($this->headFinance, $this->timecity);
        $this->grant($this->zixuanAccountant, $this->zixuan);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        @unlink($this->centralDatabase);
        parent::tearDown();
    }

    public function test_central_operating_entries_have_exact_money_and_operating_semantics(): void
    {
        $ledger = app(CentralFinanceLedgerService::class);
        $at = CarbonImmutable::parse('2026-08-20 09:00:00', 'Asia/Yangon');
        $ledger->recordOperatingIncome($this->zixuanAccountant, $this->zixuan, 1, 'student_payment', 'PAY-001', 50, $at, 'RCPT-001');
        $ledger->recordOperatingExpense($this->zixuanAccountant, $this->zixuan, 1, 'expense', 'EXP-001', 20, $at, 'EXP-REF-001');

        $balances = app(CentralFinanceFundAccountBalanceService::class);
        $this->assertSame(130.0, $balances->currentBalance($this->zixuan));
        $this->assertSame([
            'money_in' => 50.0, 'money_out' => 20.0,
            'operating_income' => 50.0, 'operating_expense' => 20.0,
            'operating_net' => 30.0,
        ], $balances->totalsForSchool(1));
        $this->assertSame(2, CentralFinanceLedgerEntry::on('mysql')->count());
        $this->assertSame(0, DB::connection('mysql')->table('central_finance_ledger_entries')->whereNull('school_id')->count());
    }

    public function test_hq_to_school_internal_transfer_is_once_per_account_and_neutral_to_operating_results(): void
    {
        $ledger = app(CentralFinanceLedgerService::class);
        $at = CarbonImmutable::parse('2026-08-20 10:00:00', 'Asia/Yangon');
        $first = $ledger->recordInternalTransfer($this->headFinance, $this->hq, $this->zixuan, 1, 'hq_funding', 'HQ-ZIX-001', 200, $at);
        $retry = $ledger->recordInternalTransfer($this->headFinance, $this->hq, $this->zixuan, 1, 'hq_funding', 'HQ-ZIX-001', 200, $at);

        $balances = app(CentralFinanceFundAccountBalanceService::class);
        $this->assertSame($first['source']->id, $retry['source']->id);
        $this->assertSame($first['destination']->id, $retry['destination']->id);
        $this->assertSame(800.0, $balances->currentBalance($this->hq));
        $this->assertSame(300.0, $balances->currentBalance($this->zixuan));
        $this->assertSame(2, CentralFinanceLedgerEntry::on('mysql')->where('source_type', 'hq_funding')->where('source_id', 'HQ-ZIX-001')->count());
        $this->assertSame([
            'money_in' => 200.0, 'money_out' => 200.0,
            'operating_income' => 0.0, 'operating_expense' => 0.0,
            'operating_net' => 0.0,
        ], $balances->totalsForSchool(1));
    }

    public function test_explicit_central_account_scope_and_school_attribution_reject_forged_or_cross_school_operations(): void
    {
        $ledger = app(CentralFinanceLedgerService::class);
        $at = CarbonImmutable::parse('2026-08-20 11:00:00', 'Asia/Yangon');

        $this->expectException(AuthorizationException::class);
        $ledger->recordOperatingIncome($this->zixuanAccountant, $this->timecity, 2, 'student_payment', 'PAY-UNAUTHORIZED', 10, $at);
    }

    public function test_scope_does_not_implicitly_grant_head_finance_and_append_only_ledger_rejects_mutation(): void
    {
        $unassignedHead = CentralFinanceUser::on('mysql')->create(['first_name' => 'Unassigned', 'last_name' => 'Head']);
        $scope = app(CentralFinanceFundAccountScopeService::class);
        $this->assertSame(['CF-ZIX-MMK'], $scope->visibleAccounts($this->zixuanAccountant)->orderBy('account_code')->pluck('account_code')->all());
        $this->expectException(AuthorizationException::class);
        $scope->assertCanOperate($unassignedHead, $this->hq);
    }

    public function test_ledger_entries_are_append_only_after_canonical_recording(): void
    {
        $entry = app(CentralFinanceLedgerService::class)->recordOperatingIncome(
            $this->zixuanAccountant, $this->zixuan, 1, 'student_payment', 'PAY-IMMUTABLE', 10,
            CarbonImmutable::parse('2026-08-20 13:00:00', 'Asia/Yangon')
        );
        $this->expectException(RuntimeException::class);
        $entry->update(['money_in' => 999]);
    }

    public function test_cross_school_and_inactive_accounts_are_rejected_without_ledger_writes(): void
    {
        $ledger = app(CentralFinanceLedgerService::class);
        $at = CarbonImmutable::parse('2026-08-20 12:00:00', 'Asia/Yangon');
        $before = CentralFinanceLedgerEntry::on('mysql')->count();

        try {
            $ledger->recordInternalTransfer($this->headFinance, $this->zixuan, $this->timecity, 1, 'internal_transfer', 'CROSS-001', 10, $at);
            $this->fail('Cross-School transfer attribution should be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame($before, CentralFinanceLedgerEntry::on('mysql')->count());
        }

        try {
            $ledger->recordInternalTransfer($this->headFinance, $this->hq, $this->zixuan, 1, 'hq_funding', 'INSUFFICIENT-001', 1001, $at);
            $this->fail('Insufficient source balance should be rejected.');
        } catch (RuntimeException) {
            $this->assertSame($before, CentralFinanceLedgerEntry::on('mysql')->count());
        }

        $this->timecity->update(['is_active' => false]);
        try {
            $ledger->recordOperatingIncome($this->headFinance, $this->timecity, 2, 'student_payment', 'PAY-INACTIVE', 10, $at);
            $this->fail('Inactive Fund Account should be rejected.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertSame($before, CentralFinanceLedgerEntry::on('mysql')->count());
        }
    }

    public function test_central_schema_is_additive_and_reversible_without_tenant_finance_tables(): void
    {
        $this->assertTrue(Schema::connection('mysql')->hasTable('central_finance_fund_accounts'));
        $this->assertTrue(Schema::connection('mysql')->hasTable('central_finance_fund_account_users'));
        $this->assertTrue(Schema::connection('mysql')->hasTable('central_finance_ledger_entries'));
        $this->assertFalse(Schema::connection('mysql')->hasTable('bank_accounts'));
        $this->assertFalse(Schema::connection('mysql')->hasTable('expenses'));

        (require database_path('migrations/2026_08_20_000005_create_central_finance_fund_accounts_and_ledger.php'))->down();
        $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_ledger_entries'));
        $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_fund_accounts'));
    }

    private function account(string $code, string $name, string $ownerType, ?int $schoolId, float $openingBalance): CentralFinanceFundAccount
    {
        return CentralFinanceFundAccount::on('mysql')->create([
            'account_uuid' => (string) Str::uuid(), 'group_id' => 1,
            'school_id' => $schoolId, 'owner_type' => $ownerType,
            'account_code' => $code, 'account_name' => $name,
            'currency' => 'MMK', 'opening_balance' => $openingBalance,
            'is_active' => true,
        ]);
    }

    private function grant(CentralFinanceUser $user, CentralFinanceFundAccount $account): void
    {
        DB::connection('mysql')->table('central_finance_fund_account_users')->insert([
            'fund_account_id' => $account->id, 'user_id' => $user->id,
            'can_view' => true, 'can_operate' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
