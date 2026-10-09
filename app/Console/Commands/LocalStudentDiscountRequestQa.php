<?php

namespace App\Console\Commands;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceUser;
use App\Services\CentralFinanceDataIsolationService;
use App\Models\CentralFinanceDataClassification;
use App\Models\FinanceGroup;
use App\Services\CentralFinanceSchoolStaffIdentityService;
use App\Support\LocalBowenQaGuard;
use App\Services\FinanceGroupScopeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/** Builds and clears only the disposable BOWEN_QA graph used by browser E2E. */
final class LocalStudentDiscountRequestQa extends Command
{
    private const ACCOUNT_CODE = 'BOWEN-QA-DISCOUNT-E2E-BANK';
    private const FRONT_DESK_EMAIL = 'qa_front_desk@bowen-qa.test';
    private const HEAD_FINANCE_EMAIL = 'qa_discount_hq@bowen-qa.test';
    private const STUDENT_SOURCE_UUID = 'e17b9cda-c389-40ff-b5fc-212f3a21e5bf';
    // This fixture must not reuse a Staff identity from another local Finance
    // rehearsal: student-specific Discount requests fail closed unless the
    // requester belongs to exactly one active Group for the target School.
    private const FRONT_DESK_SOURCE_UUID = 'f3256be4-d779-427d-9ae4-5b18ca4e0d9e';

    protected $signature = 'local:student-discount-request-qa {action : prepare, classify-receivables, verify, or cleanup}';

    protected $description = 'Prepare or remove only the disposable local BOWEN_QA student-discount browser fixture.';

    public function handle(): int
    {
        try {
            LocalBowenQaGuard::assertEnvironment(
                (string) app()->environment(),
                (string) config('app.url'),
                (string) config('database.connections.mysql.database'),
            );

            $action = (string) $this->argument('action');
            if ($action === 'prepare') {
                Artisan::call('local:bowen-qa', ['action' => 'reset']);
                if (Artisan::output() !== '' && str_contains(Artisan::output(), 'refused')) {
                    throw new RuntimeException(trim(Artisan::output()));
                }
                // LocalBowenQa seeds its role contract through raw fixture
                // queries.  Evict only the local Spatie permission cache so
                // the long-running disposable browser server cannot retain
                // an earlier role graph across a fixture reset.
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                $this->clearCentralGraph();
                $this->prepare();
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            } elseif ($action === 'verify') {
                $this->assertCompletedGraph();
            } elseif ($action === 'classify-receivables') {
                $this->classifyFixtureReceivables();
            } elseif ($action === 'cleanup') {
                $this->clearCentralGraph();
                $this->assertClean();
            } else {
                throw new RuntimeException('Action must be prepare, verify, or cleanup.');
            }

            $this->info('LOCAL STUDENT DISCOUNT REQUEST QA: '.$action.' complete.');
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('LOCAL STUDENT DISCOUNT REQUEST QA refused: '.$exception->getMessage());
            return self::FAILURE;
        }
    }

    private function prepare(): void
    {
        $central = DB::connection('mysql');
        $schoolId = $this->schoolId();
        // Keep this workflow's Head Finance principal isolated from the
        // separate GROUP_QA browser fixture so each test sees only its own
        // explicitly assigned Group.
        $central->table('users')->updateOrInsert(['email' => self::HEAD_FINANCE_EMAIL], [
            'first_name' => 'QA Discount', 'last_name' => 'Head Finance',
            'password' => Hash::make('local-only'), 'school_id' => null,
            'status' => 1, 'two_factor_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $head = CentralFinanceUser::on('mysql')->where('email', self::HEAD_FINANCE_EMAIL)->firstOrFail();
        // Production configuration authorization requires both the canonical
        // Head Finance role and explicit Group control.
        $headIdentity = \App\Models\User::on('mysql')->findOrFail($head->id);
        if (!$headIdentity->hasRole('Head Finance')) {
            $headIdentity->assignRole('Head Finance');
        }
        $isolation = app(CentralFinanceDataIsolationService::class);
        if ($isolation->schemaAvailable()) {
            $isolation->classify($head, $schoolId, 'school', $schoolId, CentralFinanceDataClassification::QA_TEST,
                'Disposable BOWEN_QA student Discount browser E2E School.');
        }
        $groups = app(FinanceGroupScopeService::class);
        $group = FinanceGroup::on('mysql')->where('code', 'BOWEN_QA')->first();
        if ($group === null) {
            $group = $groups->createGroup([
                'code' => 'BOWEN_QA', 'name' => 'BOWEN_QA Disposable Browser Group',
                'status' => 'active', 'reporting_currency' => 'MMK', 'fiscal_year_start_month' => 1,
            ]);
        }
        $groups->addSchool($group, $schoolId);
        $headGroupUser = $groups->addUser($group, $head->id);
        $groups->grantScope($headGroupUser, 'view_reports', 'GROUP');
        $groups->grantScope($headGroupUser, 'operate_finance', 'GROUP');
        // Approval of an immutable Discount request is a Group
        // configuration decision.  The disposable Head Finance fixture needs
        // the same explicit control-plane scope as the production workflow.
        $groups->grantScope($headGroupUser, 'manage_hq_accounts', 'GROUP');

        $central->table('central_finance_user_school_scopes')->updateOrInsert(
            ['user_id' => $head->id, 'school_id' => $schoolId],
            ['can_view' => true, 'can_operate' => true, 'can_submit_collections' => false,
                'can_create_student_specific_discounts' => false, 'can_approve_reimbursements' => true,
                'can_confirm_funding' => true, 'created_at' => now(), 'updated_at' => now()],
        );
        $central->table('central_finance_school_cutovers')->updateOrInsert(
            ['school_id' => $schoolId],
            [
                'status' => 'central', 'cutover_at' => now(),
                'receivable_sync_effective_at' => '2026-01-01 00:00:00',
                'created_at' => now(), 'updated_at' => now(),
            ],
        );

        Config::set('database.connections.school.database', LocalBowenQaGuard::TENANT_DATABASE);
        DB::purge('school');
        $tenantDb = DB::connection('school');
        $tenantDb->table('users')->where('email', self::FRONT_DESK_EMAIL)->update([
            'central_finance_source_uuid' => self::FRONT_DESK_SOURCE_UUID, 'updated_at' => now(),
        ]);
        $tenantDb->table('students')->where('id', 1)->update([
            'central_finance_source_uuid' => self::STUDENT_SOURCE_UUID, 'updated_at' => now(),
        ]);
        $student = $tenantDb->table('students as students')
            ->leftJoin('users as users', 'users.id', '=', 'students.user_id')
            ->leftJoin('classes as classes', 'classes.id', '=', 'students.class_id')
            ->where('students.id', 1)
            ->first([
                'students.id', 'students.class_id', 'students.class_section_id', 'students.admission_no',
                'students.central_finance_source_uuid', 'users.first_name', 'users.last_name',
                'classes.name as class_name',
            ]);
        if ($student === null || (string) $student->central_finance_source_uuid !== self::STUDENT_SOURCE_UUID) {
            throw new RuntimeException('The local BOWEN_QA Student fixture is missing.');
        }
        $feeTypeIds = $tenantDb->table('fees_types')->where('school_id', $schoolId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $feeIds = $tenantDb->table('fees')->where('school_id', $schoolId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $feeItemIds = $tenantDb->table('fees_class_types')->where('school_id', $schoolId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $isolation->classify($head, $schoolId, 'student', (int) $student->id, CentralFinanceDataClassification::QA_TEST,
            'Disposable BOWEN_QA student Discount browser E2E Student.');
        foreach ($feeTypeIds as $feeTypeId) {
            $isolation->classify($head, $schoolId, 'fee_type', $feeTypeId, CentralFinanceDataClassification::QA_TEST,
                'Disposable BOWEN_QA student Discount browser E2E Fee Type.');
        }
        foreach ($feeIds as $feeId) {
            $isolation->classify($head, $schoolId, 'fee', $feeId, CentralFinanceDataClassification::QA_TEST,
                'Disposable BOWEN_QA student Discount browser E2E Fee.');
        }
        foreach ($feeItemIds as $feeItemId) {
            $isolation->classify($head, $schoolId, 'fee_item', $feeItemId, CentralFinanceDataClassification::QA_TEST,
                'Disposable BOWEN_QA student Discount browser E2E Fee Item.');
        }
        $tenantDb = DB::connection('school');
        // LocalBowenQa intentionally owns only tenant fixtures.  The browser
        // workflow starts at Fee Setup, which requires the same Central
        // profile projection that a production tenant receives before any
        // Central Finance request can be created.
        $central->table('central_finance_student_profiles')->updateOrInsert(
            ['school_id' => $schoolId, 'tenant_student_id' => (int) $student->id],
            [
                'source_uuid' => self::STUDENT_SOURCE_UUID,
                'class_id' => $student->class_id ? (int) $student->class_id : null,
                'class_section_id' => $student->class_section_id ? (int) $student->class_section_id : null,
                'class_name' => $student->class_name,
                'section_name' => null,
                'admission_no' => $student->admission_no,
                'student_code' => $student->admission_no,
                'student_name' => trim((string) $student->first_name.' '.(string) $student->last_name),
                'enrollment_status' => 'active',
                'tenant_user_status' => 'active',
                'source_updated_at' => now(),
                'source_deleted_at' => null,
                'last_synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        $studentProfileId = (int) $central->table('central_finance_student_profiles')
            ->where(['school_id' => $schoolId, 'tenant_student_id' => (int) $student->id])->value('id');
        $isolation->classify($head, $schoolId, 'student_profile', $studentProfileId, CentralFinanceDataClassification::QA_TEST,
            'Disposable BOWEN_QA student Discount browser E2E Student Profile.');
        $tenantFrontDeskId = (int) $tenantDb->table('users')->where('email', self::FRONT_DESK_EMAIL)->value('id');
        if ($tenantFrontDeskId < 1) {
            throw new RuntimeException('The local BOWEN_QA Front Desk fixture is missing.');
        }
        if (!$tenantDb->table('staffs')->where('user_id', $tenantFrontDeskId)->exists()) {
            $tenantDb->table('staffs')->insert([
                'user_id' => $tenantFrontDeskId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(CentralFinanceSchoolStaffIdentityService::class)->grantSchoolFrontDesk(
            $group, $schoolId, $tenantFrontDeskId, 'Disposable local student Discount browser E2E.', $head->id,
        );

        $account = new CentralFinanceFundAccount([
            'account_uuid' => (string) Str::uuid(), 'group_id' => $group->id, 'school_id' => null,
            'owner_type' => CentralFinanceFundAccount::OWNER_HQ, 'account_code' => self::ACCOUNT_CODE,
            'account_name' => 'BOWEN QA Discount E2E Bank', 'account_type' => CentralFinanceFundAccount::TYPE_BANK,
            'bank_name' => 'LOCAL ONLY', 'currency' => 'MMK', 'opening_balance' => 0,
            'is_active' => true, 'status' => CentralFinanceFundAccount::STATUS_ACTIVE,
            'notes' => 'Disposable local student Discount browser E2E fixture only.',
        ]);
        $account->setConnection('mysql');
        $account->save();
        $central->table('central_finance_fund_account_school_allocations')->insert([
            'fund_account_id' => $account->id, 'school_id' => $schoolId, 'opening_allocation_amount' => 0,
            'effective_from' => '2026-01-01', 'effective_to' => null, 'status' => 'active', 'is_active' => true,
            'assigned_by' => $head->id, 'assignment_reason' => 'Disposable local student Discount browser E2E.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($isolation->schemaAvailable()) {
            $isolation->classify($head, $schoolId, 'fund_account', $account->id, CentralFinanceDataClassification::QA_TEST,
                'Disposable local student Discount E2E Bank account.');
        }
    }

    private function clearCentralGraph(): void
    {
        $central = DB::connection('mysql');
        $schoolId = $this->schoolId();
        $accountIds = $central->table('central_finance_fund_accounts')
            ->where('account_code', self::ACCOUNT_CODE)->pluck('id');
        $groupId = (int) $central->table('finance_groups')->where('code', 'BOWEN_QA')->value('id');

        $central->transaction(function () use ($central, $schoolId, $accountIds, $groupId): void {
            foreach ([
                'central_finance_pending_collection_allocations',
                'central_finance_payment_allocations',
                'central_finance_receipts',
                'central_finance_ledger_entries',
                'central_finance_document_audits',
                'central_finance_payments',
                'central_finance_pending_collections',
                'central_finance_promotion_applications',
                'central_finance_receivable_adjustments',
                'central_finance_receivables',
                'central_finance_student_discount_requests',
                'central_finance_receivable_sync_events',
                'central_finance_sync_events',
            ] as $table) {
                if (Schema::connection('mysql')->hasTable($table)
                    && Schema::connection('mysql')->hasColumn($table, 'school_id')) {
                    $central->table($table)->where('school_id', $schoolId)->delete();
                }
            }

            $promotionIds = $central->table('central_finance_promotions')
                ->where('group_id', $groupId)->where('scope', 'student_specific')->pluck('id');
            if ($promotionIds->isNotEmpty()) {
                foreach (['central_finance_promotion_fee_allocations', 'central_finance_promotion_school_allocations'] as $table) {
                    if (Schema::connection('mysql')->hasTable($table)) {
                        $central->table($table)->whereIn('promotion_id', $promotionIds)->delete();
                    }
                }
                $central->table('central_finance_promotions')->whereIn('id', $promotionIds)->delete();
            }

            if (Schema::connection('mysql')->hasTable('central_finance_student_profiles')) {
                $central->table('central_finance_student_profiles')->where('school_id', $schoolId)->delete();
            }
            if ($accountIds->isNotEmpty()) {
                // The browser rehearsal's confirmed collection records the
                // disposable account's bank identity even after the linked
                // business rows are cleared. Remove only identities belonging
                // to this fixture account before deleting that account; the
                // FK intentionally prevents broad fixture cleanup from
                // erasing identities for any other account.
                if (Schema::connection('mysql')->hasTable('central_finance_bank_transaction_identities')) {
                    $central->table('central_finance_bank_transaction_identities')
                        ->whereIn('fund_account_id', $accountIds)->delete();
                }
                foreach (['central_finance_fund_account_users', 'central_finance_fund_account_school_allocations'] as $table) {
                    if (Schema::connection('mysql')->hasTable($table)) {
                        $central->table($table)->whereIn('fund_account_id', $accountIds)->delete();
                    }
                }
                if (Schema::connection('mysql')->hasTable(CentralFinanceDataIsolationService::TABLE)) {
                    $classificationIds = $central->table(CentralFinanceDataIsolationService::TABLE)
                        ->where('subject_type', 'fund_account')->whereIn('subject_id', $accountIds)->pluck('id');
                    if ($classificationIds->isNotEmpty() && Schema::connection('mysql')->hasTable(CentralFinanceDataIsolationService::AUDIT_TABLE)) {
                        $central->table(CentralFinanceDataIsolationService::AUDIT_TABLE)->whereIn('classification_id', $classificationIds)->delete();
                    }
                    $central->table(CentralFinanceDataIsolationService::TABLE)->whereIn('id', $classificationIds)->delete();
                }
                $central->table('central_finance_fund_accounts')->whereIn('id', $accountIds)->delete();
            }

            // The local BOWEN_QA School classification is part of this
            // disposable fixture. Remove only its classification and matching
            // audit rows when the fixture is rebuilt or cleaned up.
            if (Schema::connection('mysql')->hasTable(CentralFinanceDataIsolationService::TABLE)) {
                $tenantClassificationIds = $central->table(CentralFinanceDataIsolationService::TABLE)
                    ->where('school_id', $schoolId)->where('subject_scope', 'tenant:'.$schoolId)
                    ->whereIn('subject_type', ['student', 'fee', 'fee_type', 'fee_item'])->pluck('id');
                if ($tenantClassificationIds->isNotEmpty()
                    && Schema::connection('mysql')->hasTable(CentralFinanceDataIsolationService::AUDIT_TABLE)) {
                    $central->table(CentralFinanceDataIsolationService::AUDIT_TABLE)->whereIn('classification_id', $tenantClassificationIds)->delete();
                }
                $central->table(CentralFinanceDataIsolationService::TABLE)->whereIn('id', $tenantClassificationIds)->delete();

                $schoolClassificationIds = $central->table(CentralFinanceDataIsolationService::TABLE)
                    ->where('subject_scope', 'central')->where('subject_type', 'school')->where('subject_id', $schoolId)->pluck('id');
                $profileClassificationIds = $central->table(CentralFinanceDataIsolationService::TABLE)
                    ->where('subject_scope', 'central')->where('subject_type', 'student_profile')->where('school_id', $schoolId)->pluck('id');
                $centralFixtureClassificationIds = $schoolClassificationIds->merge($profileClassificationIds);
                if ($centralFixtureClassificationIds->isNotEmpty()
                    && Schema::connection('mysql')->hasTable(CentralFinanceDataIsolationService::AUDIT_TABLE)) {
                    $central->table(CentralFinanceDataIsolationService::AUDIT_TABLE)->whereIn('classification_id', $centralFixtureClassificationIds)->delete();
                }
                $central->table(CentralFinanceDataIsolationService::TABLE)->whereIn('id', $centralFixtureClassificationIds)->delete();

                // Workflow documents (Discount requests, Receivables and
                // Pending Collections) also have central classifications that
                // are attributed to this disposable operator. Their business
                // rows were cleared above, so clear only this BOWEN_QA School's
                // remaining central classification/audit rows before removing
                // the dedicated local user referenced by classified_by.
                $remainingFixtureClassificationIds = $central->table(CentralFinanceDataIsolationService::TABLE)
                    ->where('subject_scope', 'central')->where('school_id', $schoolId)->pluck('id');
                if ($remainingFixtureClassificationIds->isNotEmpty()
                    && Schema::connection('mysql')->hasTable(CentralFinanceDataIsolationService::AUDIT_TABLE)) {
                    $central->table(CentralFinanceDataIsolationService::AUDIT_TABLE)
                        ->whereIn('classification_id', $remainingFixtureClassificationIds)->delete();
                }
                $central->table(CentralFinanceDataIsolationService::TABLE)
                    ->whereIn('id', $remainingFixtureClassificationIds)->delete();
            }

            if ($groupId > 0) {
                $groupUserIds = $central->table('finance_group_users')->where('group_id', $groupId)->pluck('id');
                if ($groupUserIds->isNotEmpty() && Schema::connection('mysql')->hasTable('finance_group_user_scopes')) {
                    $central->table('finance_group_user_scopes')->whereIn('group_user_id', $groupUserIds)->delete();
                }
                if ($groupUserIds->isNotEmpty() && Schema::connection('mysql')->hasTable('finance_group_user_tenant_identities')) {
                    $central->table('finance_group_user_tenant_identities')->whereIn('group_user_id', $groupUserIds)->delete();
                }
                $central->table('finance_group_users')->where('group_id', $groupId)->delete();
                $central->table('finance_group_schools')->where('group_id', $groupId)->delete();
                $central->table('finance_groups')->where('id', $groupId)->delete();
            }

            $staffIdentityUserIds = Schema::connection('mysql')->hasTable('central_finance_school_staff_identities')
                ? $central->table('central_finance_school_staff_identities')->where('school_id', $schoolId)->pluck('central_user_id')
                : collect();
            if ($staffIdentityUserIds->isNotEmpty()) {
                $central->table('central_finance_user_school_scopes')->where('school_id', $schoolId)
                    ->whereIn('user_id', $staffIdentityUserIds)->delete();
            }
            $central->table('central_finance_user_school_scopes')->where(['user_id' => CentralFinanceUser::on('mysql')->where('email', self::HEAD_FINANCE_EMAIL)->value('id'), 'school_id' => $schoolId])->delete();
            $headId = (int) CentralFinanceUser::on('mysql')->where('email', self::HEAD_FINANCE_EMAIL)->value('id');
            if ($headId > 0) {
                $central->table('model_has_roles')->where('model_id', $headId)->where('model_type', \App\Models\User::class)
                    ->whereIn('role_id', $central->table('roles')->where('name', 'Head Finance')->pluck('id'))->delete();
                $central->table('users')->where('id', $headId)->where('email', self::HEAD_FINANCE_EMAIL)->delete();
            }
            // BOWEN_QA owns this disposable School and its cutover row. Remove
            // that fixture state instead of leaving a synthetic School marked
            // as Central after the browser graph is cleaned up.
            $central->table('central_finance_school_cutovers')->where('school_id', $schoolId)->delete();
        });

        Config::set('database.connections.school.database', LocalBowenQaGuard::TENANT_DATABASE);
        DB::purge('school');
        DB::connection('school')->table('users')->where('email', self::FRONT_DESK_EMAIL)->update([
            'central_finance_source_uuid' => null, 'updated_at' => now(),
        ]);
        DB::connection('school')->table('students')->where('id', 1)->update([
            'central_finance_source_uuid' => null, 'updated_at' => now(),
        ]);
    }

    private function assertClean(): void
    {
        $central = DB::connection('mysql');
        $schoolId = $this->schoolId();
        foreach (['central_finance_pending_collections', 'central_finance_payments', 'central_finance_receipts',
            'central_finance_ledger_entries', 'central_finance_receivables', 'central_finance_student_discount_requests'] as $table) {
            if (Schema::connection('mysql')->hasTable($table) && $central->table($table)->where('school_id', $schoolId)->exists()) {
                throw new RuntimeException("Disposable BOWEN_QA data remains in {$table}.");
            }
        }
        if ($central->table('central_finance_fund_accounts')->where('account_code', self::ACCOUNT_CODE)->exists()) {
            throw new RuntimeException('Disposable BOWEN_QA Fund Account remains.');
        }
    }

    /** Mark only this synthetic, post-sync browser graph as QA after sync. */
    private function classifyFixtureReceivables(): void
    {
        $central = DB::connection('mysql');
        $schoolId = $this->schoolId();
        $profile = $central->table('central_finance_student_profiles')->where([
            'school_id' => $schoolId, 'source_uuid' => self::STUDENT_SOURCE_UUID,
        ])->first();
        if ($profile === null
            || app(CentralFinanceDataIsolationService::class)->classification('school', $schoolId) !== CentralFinanceDataClassification::QA_TEST
            || app(CentralFinanceDataIsolationService::class)->classification('student_profile', (int) $profile->id) !== CentralFinanceDataClassification::QA_TEST) {
            throw new RuntimeException('The disposable BOWEN_QA School and Student profile must be explicitly QA/Test before classifying Receivables.');
        }

        $receivables = $central->table('central_finance_receivables')->where([
            'school_id' => $schoolId, 'student_profile_id' => $profile->id,
            'source_type' => \App\Services\CentralFinanceReceivableSyncService::SOURCE_TYPE,
        ])->orderBy('id')->get();
        $amounts = $receivables->pluck('amount_due')->map(fn ($amount) => number_format((float) $amount, 2, '.', ''))->sort()->values()->all();
        if ($receivables->count() !== 2 || $amounts !== ['500.00', '900.00']
            || $receivables->contains(fn ($receivable) => $receivable->status !== 'open' || (float) $receivable->amount_paid !== 0.0)) {
            throw new RuntimeException('The local Discount E2E must create only its exact two outstanding synthetic Receivables before QA classification.');
        }

        $actor = CentralFinanceUser::on('mysql')->where('email', self::HEAD_FINANCE_EMAIL)->firstOrFail();
        $isolation = app(CentralFinanceDataIsolationService::class);
        foreach ($receivables as $receivable) {
            $existing = $central->table(CentralFinanceDataIsolationService::TABLE)->where([
                'subject_scope' => 'central', 'subject_type' => 'receivable', 'subject_id' => $receivable->id,
            ])->value('classification');
            if ($existing === CentralFinanceDataClassification::PRODUCTION) {
                throw new RuntimeException('A disposable QA Receivable already has an explicit Production classification.');
            }
            if ($existing === null) {
                $isolation->classify($actor, $schoolId, 'receivable', (int) $receivable->id,
                    CentralFinanceDataClassification::QA_TEST,
                    'Disposable BOWEN_QA browser E2E classification after synthetic Fee Assignment sync.');
            } elseif ($existing !== CentralFinanceDataClassification::QA_TEST) {
                throw new RuntimeException('The disposable QA Receivable has an unexpected classification.');
            }
        }
    }

    /** Read-only proof for the local browser E2E's immutable Finance effects. */
    private function assertCompletedGraph(): void
    {
        $central = DB::connection('mysql');
        $schoolId = $this->schoolId();
        $pending = $central->table('central_finance_pending_collections')
            ->where('school_id', $schoolId)->get();
        if ($pending->count() !== 1 || (string) $pending->sole()->status !== 'confirmed'
            || !(int) $pending->sole()->confirmed_payment_id) {
            throw new RuntimeException('The disposable Pending Collection was not confirmed exactly once.');
        }

        $pendingRow = $pending->sole();
        $allocationRows = $central->table('central_finance_pending_collection_allocations')
            ->where('pending_collection_id', $pendingRow->id)->get();
        if ($allocationRows->count() !== 2 || (float) $allocationRows->sum('amount') !== (float) $pendingRow->amount) {
            throw new RuntimeException('The disposable Pending Collection allocations are not exactly two immutable lines.');
        }

        $payment = $central->table('central_finance_payments')->where('id', $pendingRow->confirmed_payment_id)->first();
        if ($payment === null || (int) $payment->school_id !== $schoolId || (float) $payment->amount !== (float) $pendingRow->amount) {
            throw new RuntimeException('The canonical Payment does not match the confirmed Pending Collection.');
        }
        if ($central->table('central_finance_receipts')->where('payment_id', $payment->id)->count() !== 1
            || $central->table('central_finance_ledger_entries')->where(['source_type' => 'central_payment', 'source_id' => $payment->payment_uuid])->count() !== 1) {
            throw new RuntimeException('The confirmed Pending Collection did not produce exactly one Receipt and Ledger entry.');
        }
        if ($central->table('central_finance_payment_allocations')->where('payment_id', $payment->id)->count() !== 2
            || $central->table('central_finance_promotion_applications')->where('school_id', $schoolId)->count() !== 1
            || $central->table('central_finance_student_discount_requests')->where(['school_id' => $schoolId, 'status' => 'approved'])->count() !== 1) {
            throw new RuntimeException('The disposable Discount graph is incomplete or was not applied exactly once.');
        }
    }

    private function schoolId(): int
    {
        $schoolId = (int) DB::connection('mysql')->table('schools')->where('code', LocalBowenQaGuard::SCHOOL_CODE)->value('id');
        if ($schoolId < 1) {
            throw new RuntimeException('The local BOWEN_QA School is missing.');
        }

        return $schoolId;
    }
}
