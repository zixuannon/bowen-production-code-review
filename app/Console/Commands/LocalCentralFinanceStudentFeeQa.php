<?php

namespace App\Console\Commands;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinancePayment;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceStudentProfile;
use App\Models\CentralFinanceSyncEvent;
use App\Models\CentralFinanceUser;
use App\Models\School;
use App\Services\CentralFinanceFundAccountBalanceService;
use App\Services\CentralFinancePaymentService;
use App\Services\CentralFinanceReceivableSyncService;
use App\Services\CentralFinanceStudentProfileSyncService;
use App\Support\LocalQaTenantGuard;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Deterministic local-only acceptance fixture for the Central Student Fee
 * loop. It never removes any document outside its own fixed QA namespace.
 */
final class LocalCentralFinanceStudentFeeQa extends Command
{
    private const CENTRAL_DATABASE = 'eschool_zixuan_fresh_start';
    private const SCHOOL_CODE = 'GROUP_QA_SCHOOL_A';
    private const TENANT_DATABASE = 'eschool_local_group_qa_a';
    private const STUDENT_UUID = '2c0fb8ac-0f84-4364-a9dc-bef6d5db4ad8';
    private const PREFIX = 'CFQA_STUDENT_FEE_LOOP';
    private const ACCOUNT_CODE = 'CFQA_STUDENT_FEE_HQ_MMK';
    private const DUE = 500000.0;
    private const PARTIAL = 123456.0;

    protected $signature = 'local:central-finance-student-fee-qa {action : reset, run, or verify}';
    protected $description = 'Run the fixed local Central Finance Zixuan Student Fee loop without touching existing QA documents.';

    public function handle(): int
    {
        $previousDefault = DB::getDefaultConnection();
        $previousTenantDatabase = Config::get('database.connections.school.database');

        try {
            $this->assertLocalFixtureEnvironment();

            return match ($this->argument('action')) {
                'reset' => $this->reset(),
                'run' => $this->runLoop(),
                'verify' => $this->verify(),
                default => throw new LogicException('Action must be reset, run, or verify.'),
            };
        } catch (\Throwable $exception) {
            $this->error('CENTRAL_STUDENT_FEE_QA refused: '.$exception->getMessage());
            return self::FAILURE;
        } finally {
            DB::purge('school');
            Config::set('database.connections.school.database', $previousTenantDatabase);
            DB::setDefaultConnection($previousDefault);
        }
    }

    private function reset(): int
    {
        $this->clearOnlyOwnedFixture();
        $school = $this->trustedSchool();
        $now = CarbonImmutable::parse('2026-08-23 09:00:00', 'Asia/Yangon');

        $this->onTenant(function ($tenant) use ($school, $now): void {
            $medium = $tenant->table('mediums')->insertGetId([
                'name' => self::PREFIX.' Medium', 'school_id' => $school->id,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $section = $tenant->table('sections')->insertGetId([
                'name' => self::PREFIX.' Section', 'school_id' => $school->id,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $class = $tenant->table('classes')->insertGetId([
                'name' => self::PREFIX.' Class', 'medium_id' => $medium,
                'school_id' => $school->id, 'include_semesters' => false,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $classSection = $tenant->table('class_sections')->insertGetId([
                'class_id' => $class, 'section_id' => $section, 'medium_id' => $medium,
                'school_id' => $school->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $sessionYear = (int) $tenant->table('session_years')->where('school_id', $school->id)
                ->orderByDesc('default')->value('id');
            if ($sessionYear < 1) {
                throw new LogicException('The fixed local Zixuan fixture has no Session Year.');
            }
            $guardian = $tenant->table('users')->insertGetId([
                'first_name' => 'CFQA', 'last_name' => 'Guardian',
                'email' => 'cfqa.student-fee.guardian@local.test', 'password' => bcrypt('local-only'),
                'school_id' => $school->id, 'status' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $studentUser = $tenant->table('users')->insertGetId([
                'first_name' => 'CFQA', 'last_name' => 'Zixuan Student',
                'email' => 'cfqa.student-fee.student@local.test', 'password' => bcrypt('local-only'),
                'school_id' => $school->id, 'status' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $tenant->table('students')->insert([
                'user_id' => $studentUser, 'guardian_id' => $guardian, 'class_id' => $class,
                'class_section_id' => $classSection, 'session_year_id' => $sessionYear,
                'join_session_year_id' => $sessionYear, 'admission_no' => self::PREFIX.'-001',
                'admission_date' => $now->toDateString(), 'roll_number' => 1,
                'application_type' => 0, 'application_status' => 1, 'school_id' => $school->id,
                'central_finance_source_uuid' => self::STUDENT_UUID,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $fee = $tenant->table('fees')->insertGetId([
                'name' => self::PREFIX.' Tuition', 'currency' => 'MMK',
                'due_date' => '2026-09-01', 'due_charges' => 0, 'due_charges_amount' => 0,
                'class_id' => $class, 'session_year_id' => $sessionYear, 'school_id' => $school->id,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $feeType = $tenant->table('fees_types')->insertGetId([
                'name' => self::PREFIX.' Fee Type', 'description' => 'Local-only Central Fee loop',
                'school_id' => $school->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $tenant->table('fees_class_types')->insert([
                'fees_id' => $fee, 'fees_type_id' => $feeType, 'class_id' => $class,
                'amount' => self::DUE, 'optional' => false,
                'school_id' => $school->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
        });

        $studentId = (int) $this->onTenant(fn ($tenant): int => (int) $tenant->table('students')
            ->where('central_finance_source_uuid', self::STUDENT_UUID)->value('id'));
        $sync = app(CentralFinanceStudentProfileSyncService::class)->syncStudent($school, $studentId);
        if ($sync['result'] !== 'created') {
            throw new LogicException('The Central student profile fixture was not created canonically.');
        }
        $profile = CentralFinanceStudentProfile::on('mysql')->findOrFail($sync['profile_id']);
        $receivables = app(CentralFinanceReceivableSyncService::class)->syncProfile($profile);
        if (count($receivables) !== 1 || (float) $receivables[0]->amount_due !== self::DUE) {
            throw new LogicException('The fixed tenant fee assignment did not create exactly one Central receivable.');
        }

        $actor = $this->headFinance();
        $account = CentralFinanceFundAccount::on('mysql')->create([
            'group_id' => (int) DB::connection('mysql')->table('finance_groups')->where('code', 'GROUP_QA')->value('id'),
            'school_id' => null, 'owner_type' => CentralFinanceFundAccount::OWNER_HQ,
            'account_code' => self::ACCOUNT_CODE, 'account_name' => 'CFQA Student Fee HQ MMK',
            'currency' => 'MMK', 'opening_balance' => 0, 'is_active' => true,
        ]);
        DB::connection('mysql')->table('central_finance_fund_account_users')->insert([
            'fund_account_id' => $account->id, 'user_id' => $actor->id,
            'can_view' => true, 'can_operate' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->verify();
        $this->info('CENTRAL_STUDENT_FEE_QA reset: fresh tenant student, canonical profile, receivable, and HQ Fund Account.');
        return self::SUCCESS;
    }

    private function runLoop(): int
    {
        $this->verifyBeforeCollection();
        $actor = $this->headFinance();
        $receivable = $this->receivable();
        $account = $this->account();
        $payments = app(CentralFinancePaymentService::class);
        $at = CarbonImmutable::parse('2026-08-23 10:00:00', 'Asia/Yangon');
        $tenantBefore = $this->tenantSourceHash();

        $first = $payments->collect($actor, $receivable->id, $account, self::PARTIAL, 'Cash', $at, self::PREFIX.'-PARTIAL', self::PREFIX.'-P1');
        $retry = $payments->collect($actor, $receivable->id, $account, self::PARTIAL, 'Cash', $at, self::PREFIX.'-PARTIAL', self::PREFIX.'-P1');
        if ($first['payment']->id !== $retry['payment']->id) {
            throw new LogicException('A duplicate idempotency key created a second Central payment.');
        }
        $this->assertDuplicateReferenceRejected($payments, $actor, $receivable, $account, $at);
        $payments->collect($actor, $receivable->id, $account, self::DUE - self::PARTIAL, 'Cash', $at, self::PREFIX.'-FINAL', self::PREFIX.'-P2');

        if ($tenantBefore !== $this->tenantSourceHash()) {
            throw new LogicException('Central collection modified tenant Finance source data.');
        }
        $this->verify();
        $this->assertScopeRejections($payments, $receivable, $account, $at);
        $this->info('CENTRAL_STUDENT_FEE_QA run: partial and final Central payments/receipts/Ledger verified.');
        return self::SUCCESS;
    }

    private function verify(): int
    {
        $school = $this->trustedSchool();
        $profile = CentralFinanceStudentProfile::on('mysql')->where([
            'school_id' => $school->id, 'source_uuid' => self::STUDENT_UUID,
        ])->firstOrFail();
        $studentId = (int) $this->onTenant(fn ($tenant): int => (int) $tenant->table('students')
            ->where('central_finance_source_uuid', self::STUDENT_UUID)->value('id'));
        if ($studentId < 1 || (int) $profile->tenant_student_id !== $studentId) {
            throw new LogicException('Central Student Profile does not identify the fixed tenant student.');
        }
        $receivable = $this->receivable();
        $payments = CentralFinancePayment::on('mysql')->where('receivable_id', $receivable->id)->get();
        $ledger = DB::connection('mysql')->table('central_finance_ledger_entries')
            ->where('school_id', $school->id)->where('source_type', 'central_payment')
            ->whereIn('source_id', $payments->pluck('payment_uuid'))->get();
        $account = $this->account();
        $balance = app(CentralFinanceFundAccountBalanceService::class)->currentBalance($account);
        $paid = (float) $receivable->amount_paid;
        $expectedStatus = $paid === 0.0 ? CentralFinanceReceivable::OPEN
            : ($paid < self::DUE ? CentralFinanceReceivable::PARTIAL : CentralFinanceReceivable::PAID);
        if ((float) $receivable->amount_due !== self::DUE
            || $receivable->status !== $expectedStatus
            || (float) $balance !== $paid
            || $payments->count() !== $ledger->count()
            || (float) $ledger->sum('money_in') !== $paid
            || (float) $ledger->sum('operating_income') !== $paid
            || (float) $ledger->sum('operating_expense') !== 0.0
            || DB::connection('mysql')->table('central_finance_receipts')->whereIn('payment_id', $payments->pluck('id'))->count() !== $payments->count()) {
            throw new LogicException('Central Student Fee loop reconciliation failed.');
        }
        $this->info(sprintf(
            'CENTRAL_STUDENT_FEE_QA verified: student=%d profile=%d receivable=%d paid=%.0f outstanding=%.0f payments=%d.',
            $studentId, $profile->id, $receivable->id, $paid, self::DUE - $paid, $payments->count(),
        ));
        return self::SUCCESS;
    }

    private function assertDuplicateReferenceRejected(CentralFinancePaymentService $payments, CentralFinanceUser $actor, CentralFinanceReceivable $receivable, CentralFinanceFundAccount $account, CarbonImmutable $at): void
    {
        $count = CentralFinancePayment::on('mysql')->where('receivable_id', $receivable->id)->count();
        try {
            $payments->collect($actor, $receivable->id, $account, 1, 'Cash', $at, self::PREFIX.'-DUPLICATE-REF', self::PREFIX.'-P1');
            throw new LogicException('A duplicate Central payment reference was accepted.');
        } catch (InvalidArgumentException) {
            if (CentralFinancePayment::on('mysql')->where('receivable_id', $receivable->id)->count() !== $count) {
                throw new LogicException('Rejected duplicate payment reference changed Central Finance data.');
            }
        }
    }

    private function assertScopeRejections(CentralFinancePaymentService $payments, CentralFinanceReceivable $receivable, CentralFinanceFundAccount $account, CarbonImmutable $at): void
    {
        $before = CentralFinancePayment::on('mysql')->where('receivable_id', $receivable->id)->count();
        $schoolAccountant = CentralFinanceUser::on('mysql')->where('email', 'group_school_a@group-qa.test')->firstOrFail();
        try {
            $payments->collect($schoolAccountant, $receivable->id, $account, 1, 'Cash', $at, self::PREFIX.'-UNSCOPED', self::PREFIX.'-UNSCOPED');
            throw new LogicException('An actor without HQ Fund Account scope was accepted.');
        } catch (AuthorizationException) {
            if (CentralFinancePayment::on('mysql')->where('receivable_id', $receivable->id)->count() !== $before) {
                throw new LogicException('Rejected Fund Account scope request changed Central Finance data.');
            }
        }
    }

    private function verifyBeforeCollection(): void
    {
        $this->verify();
        if ((float) $this->receivable()->amount_paid !== 0.0) {
            throw new LogicException('Reset the fixed local Student Fee fixture before rerunning collection.');
        }
    }

    private function clearOnlyOwnedFixture(): void
    {
        $central = DB::connection('mysql');
        $profileIds = $central->table('central_finance_student_profiles')->where('source_uuid', self::STUDENT_UUID)->pluck('id');
        $receivableIds = $central->table('central_finance_receivables')->whereIn('student_profile_id', $profileIds)->pluck('id');
        $payments = $central->table('central_finance_payments')->whereIn('receivable_id', $receivableIds)->get(['id', 'payment_uuid']);
        $paymentIds = $payments->pluck('id');
        $paymentUuids = $payments->pluck('payment_uuid');

        $central->transaction(function () use ($central, $paymentIds, $paymentUuids, $receivableIds, $profileIds): void {
            $central->table('central_finance_receipts')->whereIn('payment_id', $paymentIds)->delete();
            $central->table('central_finance_ledger_entries')->where('source_type', 'central_payment')->whereIn('source_id', $paymentUuids)->delete();
            $central->table('central_finance_payments')->whereIn('id', $paymentIds)->delete();
            $central->table('central_finance_receivables')->whereIn('id', $receivableIds)->delete();
            CentralFinanceSyncEvent::on('mysql')->where('source_uuid', self::STUDENT_UUID)->delete();
            CentralFinanceStudentProfile::on('mysql')->whereIn('id', $profileIds)->delete();
            $account = CentralFinanceFundAccount::on('mysql')->where('account_code', self::ACCOUNT_CODE)->first();
            if ($account !== null) {
                $central->table('central_finance_fund_account_users')->where('fund_account_id', $account->id)->delete();
                $account->forceDelete();
            }
        });

        $this->onTenant(function ($tenant): void {
            $student = $tenant->table('students')->where('central_finance_source_uuid', self::STUDENT_UUID)->first();
            if ($student === null) {
                return;
            }
            $classId = (int) $student->class_id;
            $feeIds = $tenant->table('fees')->where('name', self::PREFIX.' Tuition')->pluck('id');
            $tenant->table('fees_class_types')->whereIn('fees_id', $feeIds)->delete();
            $tenant->table('fees_types')->where('name', self::PREFIX.' Fee Type')->delete();
            $tenant->table('fees')->whereIn('id', $feeIds)->delete();
            $tenant->table('students')->where('id', $student->id)->delete();
            $tenant->table('users')->whereIn('email', ['cfqa.student-fee.student@local.test', 'cfqa.student-fee.guardian@local.test'])->delete();
            $sectionIds = $tenant->table('class_sections')->where('class_id', $classId)->pluck('section_id');
            $mediumIds = $tenant->table('class_sections')->where('class_id', $classId)->pluck('medium_id');
            $tenant->table('class_sections')->where('class_id', $classId)->delete();
            $tenant->table('classes')->where('id', $classId)->delete();
            $tenant->table('sections')->whereIn('id', $sectionIds)->where('name', self::PREFIX.' Section')->delete();
            $tenant->table('mediums')->whereIn('id', $mediumIds)->where('name', self::PREFIX.' Medium')->delete();
        });
    }

    private function trustedSchool(): School
    {
        $school = School::on('mysql')->where('code', self::SCHOOL_CODE)->firstOrFail();
        if ((string) $school->getRawOriginal('database_name') !== self::TENANT_DATABASE) {
            throw new LogicException('The fixed local Zixuan QA School registry is not trusted.');
        }
        return $school;
    }

    private function headFinance(): CentralFinanceUser
    {
        return CentralFinanceUser::on('mysql')->where('email', 'group_hq@group-qa.test')->firstOrFail();
    }

    private function account(): CentralFinanceFundAccount
    {
        return CentralFinanceFundAccount::on('mysql')->where('account_code', self::ACCOUNT_CODE)->firstOrFail();
    }

    private function receivable(): CentralFinanceReceivable
    {
        $profileId = (int) CentralFinanceStudentProfile::on('mysql')
            ->where('source_uuid', self::STUDENT_UUID)->value('id');
        return CentralFinanceReceivable::on('mysql')->where('student_profile_id', $profileId)->firstOrFail();
    }

    private function tenantSourceHash(): string
    {
        return $this->onTenant(function ($tenant): string {
            $student = $tenant->table('students')->where('central_finance_source_uuid', self::STUDENT_UUID)->first();
            $rows = [
                $student,
                $tenant->table('fees')->where('name', self::PREFIX.' Tuition')->get(),
                $tenant->table('fees_class_types')->where('class_id', $student->class_id)->get(),
            ];
            return hash('sha256', serialize($rows));
        });
    }

    /** @template T @param callable(\Illuminate\Database\Connection):T $callback @return T */
    private function onTenant(callable $callback): mixed
    {
        $previous = Config::get('database.connections.school.database');
        try {
            Config::set('database.connections.school.database', self::TENANT_DATABASE);
            DB::purge('school');
            return $callback(DB::connection('school'));
        } finally {
            DB::purge('school');
            Config::set('database.connections.school.database', $previous);
        }
    }

    private function assertLocalFixtureEnvironment(): void
    {
        LocalQaTenantGuard::assertEnvironment((string) app()->environment(), (string) config('app.url'), (string) config('database.connections.mysql.database'), [
            self::SCHOOL_CODE => self::TENANT_DATABASE,
        ]);
        if ((string) config('database.connections.mysql.database') !== self::CENTRAL_DATABASE) {
            throw new LogicException('Central Student Fee QA requires its fixed isolated local database.');
        }
        foreach ([
            'central_finance_student_profiles', 'central_finance_sync_events', 'central_finance_receivables',
            'central_finance_payments', 'central_finance_receipts', 'central_finance_fund_accounts',
            'central_finance_fund_account_users', 'central_finance_ledger_entries',
        ] as $table) {
            if (!Schema::connection('mysql')->hasTable($table)) {
                throw new LogicException('The isolated Central Finance schema is incomplete.');
            }
        }
    }
}
