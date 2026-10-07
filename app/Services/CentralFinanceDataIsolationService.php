<?php

namespace App\Services;

use App\Models\CentralFinanceDataClassification;
use App\Models\CentralFinanceDataClassificationAudit;
use App\Models\CentralFinanceQaRunRecord;
use App\Models\CentralFinanceUser;
use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Canonical, name-independent visibility metadata for Central Finance data. */
final class CentralFinanceDataIsolationService
{
    public const TABLE = 'central_finance_data_classifications';
    public const AUDIT_TABLE = 'central_finance_data_classification_audits';

    /** @var array<string, array{table:string,school:?string}> */
    private const SUBJECTS = [
        'school' => ['table' => 'schools', 'school' => null],
        'central_user' => ['table' => 'users', 'school' => 'school_id'],
        'central_staff_identity' => ['table' => 'central_finance_school_staff_identities', 'school' => 'school_id'],
        'fund_account' => ['table' => 'central_finance_fund_accounts', 'school' => 'school_id'],
        'category' => ['table' => 'central_finance_categories', 'school' => 'school_id'],
        'student_profile' => ['table' => 'central_finance_student_profiles', 'school' => 'school_id'],
        'receivable' => ['table' => 'central_finance_receivables', 'school' => 'school_id'],
        'receivable_adjustment' => ['table' => 'central_finance_receivable_adjustments', 'school' => 'school_id'],
        'promotion' => ['table' => 'central_finance_promotions', 'school' => null],
        'student_discount_request' => ['table' => 'central_finance_student_discount_requests', 'school' => 'school_id'],
        'promotion_application' => ['table' => 'central_finance_promotion_applications', 'school' => 'school_id'],
        'pending_collection' => ['table' => 'central_finance_pending_collections', 'school' => 'school_id'],
        'pending_collection_allocation' => ['table' => 'central_finance_pending_collection_allocations', 'school' => 'school_id'],
        'collection_handover' => ['table' => 'central_finance_collection_handover_batches', 'school' => 'school_id'],
        'collection_handover_item' => ['table' => 'central_finance_collection_handover_items', 'school' => null],
        'fund_handover' => ['table' => 'central_finance_fund_handovers', 'school' => 'school_id'],
        'import_batch' => ['table' => 'central_finance_import_batches', 'school' => 'school_id'],
        'group_import_batch' => ['table' => 'central_finance_group_import_batches', 'school' => null],
        'payment' => ['table' => 'central_finance_payments', 'school' => 'school_id'],
        'payment_allocation' => ['table' => 'central_finance_payment_allocations', 'school' => 'school_id'],
        'payment_refund' => ['table' => 'central_finance_payment_refunds', 'school' => 'school_id'],
        'payment_reversal' => ['table' => 'central_finance_payment_reversals', 'school' => 'school_id'],
        'receipt' => ['table' => 'central_finance_receipts', 'school' => 'school_id'],
        'ledger' => ['table' => 'central_finance_ledger_entries', 'school' => 'school_id'],
        'expense' => ['table' => 'central_finance_expenses', 'school' => 'school_id'],
        'other_income' => ['table' => 'central_finance_other_incomes', 'school' => 'school_id'],
        'internal_transfer' => ['table' => 'central_finance_internal_transfers', 'school' => 'school_id'],
        'reimbursement' => ['table' => 'central_finance_reimbursement_requests', 'school' => 'school_id'],
        'hq_funding' => ['table' => 'central_finance_hq_funding_requests', 'school' => 'school_id'],
        'unidentified_deposit' => ['table' => 'central_finance_unidentified_deposits', 'school' => null],
        'unidentified_deposit_allocation' => ['table' => 'central_finance_unidentified_deposit_allocations', 'school' => 'school_id'],
    ];

    /** @var array<string, array{table:string,staff_relation?:bool}> */
    private const TENANT_SUBJECTS = [
        'student' => ['table' => 'students'],
        'student_fee_assignment' => ['table' => 'student_fee_assignments'],
        'student_fee_assignment_item' => ['table' => 'student_fee_assignment_items'],
        'staff' => ['table' => 'users', 'staff_relation' => true],
        'fee' => ['table' => 'fees'],
        'fee_type' => ['table' => 'fees_types'],
        'fee_item' => ['table' => 'fees_class_types'],
    ];

    public function schemaAvailable(): bool
    {
        $schema = Schema::connection('mysql');
        return $schema->hasTable(self::TABLE) && $schema->hasTable(self::AUDIT_TABLE)
            && $schema->hasColumns(self::TABLE, [
                'classification_uuid', 'school_id', 'subject_scope', 'subject_type', 'subject_id',
                'classification', 'reason', 'classified_by',
            ])
            && $schema->hasColumns(self::AUDIT_TABLE, [
                'audit_uuid', 'classification_id', 'school_id', 'subject_scope', 'subject_type',
                'subject_id', 'before_classification', 'after_classification', 'reason', 'actor_id',
            ]);
    }

    public function canIncludeQaTest(CentralFinanceUser $actor): bool
    {
        if ($actor->getRawOriginal('school_id') !== null) {
            return false;
        }
        $user = User::on('mysql')->find($actor->id);

        return $user !== null && ($user->hasRole('Head Finance') || $user->hasRole('Super Admin'));
    }

    public function includeQaTest(Request $request, CentralFinanceUser $actor): bool
    {
        if (!$request->boolean('include_qa_test')) {
            return false;
        }
        if (!$this->canIncludeQaTest($actor)) {
            throw new AuthorizationException('This identity cannot include QA/Test Finance data.');
        }

        return true;
    }

    /**
     * Exclude direct QA/Test or archived rows and rows inherited from a QA/Test
     * or archived School. Unclassified rows remain Production-compatible.
     */
    public function apply(Builder $query, string $subjectType, bool $includeQaTest = false, ?string $schoolColumn = null): Builder
    {
        $subject = self::SUBJECTS[$subjectType] ?? null;
        if ($subject === null) {
            throw new RuntimeException("Unsupported Central Finance classification subject: {$subjectType}");
        }
        if ($includeQaTest) {
            // Run membership is permanently outside Official aggregates even
            // when a manager explicitly reveals legacy QA/Test data. The QA
            // Run workspace reads members through its own scoped projections.
            if ($subjectType !== 'school' && Schema::connection('mysql')->hasTable(CentralFinanceQaRunService::RECORDS_TABLE)) {
                $qualifiedId = $query->getModel()->qualifyColumn($query->getModel()->getKeyName());
                $query->whereNotExists(function (QueryBuilder $membership) use ($subjectType, $qualifiedId): void {
                    $membership->selectRaw('1')->from(CentralFinanceQaRunService::RECORDS_TABLE.' as cf_qa_run_member')
                        ->where('cf_qa_run_member.subject_scope', 'central')
                        ->where('cf_qa_run_member.subject_type', $subjectType)
                        ->whereColumn('cf_qa_run_member.subject_id', $qualifiedId);
                });
            }
            if ($subjectType !== 'school') {
                $schoolColumn ??= $subject['school'];
                app(CentralFinanceQaRunService::class)->applyPermanentOfficialExclusion($query, $schoolColumn);
            }
            return $query;
        }
        if (!$this->schemaAvailable()) {
            if (app()->environment('production')) {
                throw new RuntimeException('Central Finance data isolation schema is missing.');
            }
            return $query;
        }

        $table = $query->getModel()->getTable();
        $qualifiedId = $query->getModel()->qualifyColumn($query->getModel()->getKeyName());
        $query->whereNotExists(function (QueryBuilder $classification) use ($subjectType, $qualifiedId): void {
            $classification->selectRaw('1')->from(self::TABLE.' as cf_direct_classification')
                ->where('cf_direct_classification.subject_scope', 'central')
                ->where('cf_direct_classification.subject_type', $subjectType)
                ->whereColumn('cf_direct_classification.subject_id', $qualifiedId)
                ->whereIn('cf_direct_classification.classification', [CentralFinanceDataClassification::QA_TEST, CentralFinanceDataClassification::ARCHIVED]);
        });

        $schoolColumn ??= $subject['school'];
        if ($subjectType !== 'school' && $schoolColumn !== null) {
            $qualifiedSchool = str_contains($schoolColumn, '.') ? $schoolColumn : $table.'.'.$schoolColumn;
            $query->whereNotExists(function (QueryBuilder $classification) use ($qualifiedSchool): void {
                $classification->selectRaw('1')->from(self::TABLE.' as cf_school_classification')
                    ->where('cf_school_classification.subject_scope', 'central')
                    ->where('cf_school_classification.subject_type', 'school')
                    ->whereColumn('cf_school_classification.subject_id', $qualifiedSchool)
                    ->whereIn('cf_school_classification.classification', [CentralFinanceDataClassification::QA_TEST, CentralFinanceDataClassification::ARCHIVED]);
            });
        }

        app(CentralFinanceQaRunService::class)->applyPermanentOfficialExclusion($query, $schoolColumn);

        return $query;
    }

    /**
     * Apply the non-optional visibility contract for a School-owned workflow.
     *
     * A permanent QA School may work only with its explicitly classified QA
     * records. A Production School keeps the normal Official-only filter.
     * Callers obtain $schoolId from a trusted operating context; this helper
     * deliberately accepts no request flag or user-controlled classification.
     */
    public function applySchoolWorkflow(Builder $query, string $subjectType, int $schoolId): Builder
    {
        if (!$this->isQaTestSchool($schoolId)) {
            return $this->apply($query, $subjectType);
        }

        return $this->applyExactClassification($query, $subjectType, 'central', CentralFinanceDataClassification::QA_TEST);
    }

    /**
     * Tenant metadata equivalent of applySchoolWorkflow(). It prevents a QA
     * School workflow from treating unclassified or Official tenant data as a
     * QA fixture merely because the School itself is QA/Test.
     */
    public function applyTenantMetadataForSchoolWorkflow(Builder|QueryBuilder $query, string $subjectType, int $schoolId, string $subjectColumn = 'id'): Builder|QueryBuilder
    {
        if (!$this->isQaTestSchool($schoolId)) {
            $this->applyTenantMetadata($query, $subjectType, $schoolId, false, $subjectColumn);
            if ($this->isFeeLifecycleSubject($subjectType) && $this->schemaAvailable()) {
                // Do not treat an unknown explicit classification as Official.
                $excluded = CentralFinanceDataClassification::on('mysql')
                    ->where('subject_scope', $this->tenantScope($schoolId))
                    ->where('subject_type', $subjectType)
                    ->where('classification', '<>', CentralFinanceDataClassification::PRODUCTION)
                    ->pluck('subject_id')->all();
                $query->whereNotIn($subjectColumn, $excluded);
            }
            return $query;
        }
        if (!isset(self::TENANT_SUBJECTS[$subjectType])) {
            throw new RuntimeException("Unsupported tenant classification subject: {$subjectType}");
        }
        if (!$this->schemaAvailable()) {
            if (app()->environment('production')) {
                throw new RuntimeException('Central Finance data isolation schema is missing.');
            }
            return $query;
        }

        $qaSubjectIds = CentralFinanceDataClassification::on('mysql')
            ->where('subject_scope', $this->tenantScope($schoolId))
            ->where('subject_type', $subjectType)
            ->where('classification', CentralFinanceDataClassification::QA_TEST)
            ->pluck('subject_id')
            ->map(static fn ($subjectId): int => (int) $subjectId)
            ->all();

        return $query->whereIn($subjectColumn, $qaSubjectIds);
    }

    public function classification(string $subjectType, int $subjectId, string $subjectScope = 'central'): string
    {
        if (app(CentralFinanceQaRunService::class)->isQaSchoolClassificationFallback($subjectType, $subjectId)) {
            return CentralFinanceDataClassification::QA_TEST;
        }
        if (!$this->schemaAvailable()) {
            if (app()->environment('production')) {
                throw new RuntimeException('Central Finance data isolation schema is missing.');
            }
            return CentralFinanceDataClassification::PRODUCTION;
        }

        return CentralFinanceDataClassification::on('mysql')->where([
            'subject_scope' => $subjectScope, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
        ])->value('classification') ?? CentralFinanceDataClassification::PRODUCTION;
    }

    public function tenantScope(int $schoolId): string
    {
        return 'tenant:'.$schoolId;
    }

    /**
     * Apply central classification metadata to a tenant-backed identity without
     * joining across databases. This is also safe for central projections which
     * carry a tenant-local foreign key such as tenant_student_id.
     */
    public function applyTenantMetadata(Builder|QueryBuilder $query, string $subjectType, int $schoolId, bool $includeQaTest = false, string $subjectColumn = 'id'): Builder|QueryBuilder
    {
        if (!isset(self::TENANT_SUBJECTS[$subjectType])) {
            throw new RuntimeException("Unsupported tenant classification subject: {$subjectType}");
        }
        if ($includeQaTest) {
            return $query;
        }
        if (!$this->schemaAvailable()) {
            if (app()->environment('production')) {
                throw new RuntimeException('Central Finance data isolation schema is missing.');
            }
            return $query;
        }
        if ($this->classification('school', $schoolId) !== CentralFinanceDataClassification::PRODUCTION) {
            return $query->whereRaw('1 = 0');
        }

        $excluded = CentralFinanceDataClassification::on('mysql')
            ->where('subject_scope', $this->tenantScope($schoolId))
            ->where('subject_type', $subjectType)
            ->whereIn('classification', [CentralFinanceDataClassification::QA_TEST, CentralFinanceDataClassification::ARCHIVED])
            ->pluck('subject_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($excluded !== []) {
            $query->whereNotIn($subjectColumn, $excluded);
        }

        return $query;
    }

    public function applyTenant(Builder|QueryBuilder $query, string $subjectType, int $schoolId, bool $includeQaTest = false, string $subjectColumn = 'id'): Builder|QueryBuilder
    {
        if (!$this->schemaAvailable() && !app()->environment('production')) {
            return $query;
        }
        $this->assertTrustedTenantConnection($schoolId, $subjectType);

        return $this->applyTenantMetadata($query, $subjectType, $schoolId, $includeQaTest, $subjectColumn);
    }

    /**
     * Apply the trusted-tenant guard and the canonical QA-School workflow
     * visibility rule together. A permanent QA School may list only its
     * explicitly classified QA/Test records; Official Schools keep the
     * Official-only tenant filter.
     */
    public function applyTenantForSchoolWorkflow(Builder|QueryBuilder $query, string $subjectType, int $schoolId, string $subjectColumn = 'id'): Builder|QueryBuilder
    {
        if (!$this->schemaAvailable() && !app()->environment('production')) {
            return $query;
        }
        $this->assertTrustedTenantConnection($schoolId, $subjectType);

        return $this->applyTenantMetadataForSchoolWorkflow($query, $subjectType, $schoolId, $subjectColumn);
    }

    /** Exclude classified central identities and tenant staff linked to them. */
    public function applyCentralStaffUsers(Builder $query, int $schoolId, bool $includeQaTest = false): Builder
    {
        $this->apply($query, 'central_user', $includeQaTest);
        if ($includeQaTest || !$this->schemaAvailable()
            || !Schema::connection('mysql')->hasTable('central_finance_school_staff_identities')) {
            return $query;
        }

        $identityIds = DB::connection('mysql')->table('central_finance_school_staff_identities')
            ->where('school_id', $schoolId)->pluck('id');
        if ($identityIds->isNotEmpty()) {
            $excludedIdentityIds = CentralFinanceDataClassification::on('mysql')
                ->where('subject_scope', 'central')->where('subject_type', 'central_staff_identity')
                ->whereIn('subject_id', $identityIds)
                ->whereIn('classification', [CentralFinanceDataClassification::QA_TEST, CentralFinanceDataClassification::ARCHIVED])
                ->pluck('subject_id');
            if ($excludedIdentityIds->isNotEmpty()) {
                $query->whereNotIn('users.id', DB::connection('mysql')->table('central_finance_school_staff_identities')
                    ->whereIn('id', $excludedIdentityIds)->pluck('central_user_id'));
            }
        }

        $excludedTenantUserIds = CentralFinanceDataClassification::on('mysql')
            ->where('subject_scope', $this->tenantScope($schoolId))->where('subject_type', 'staff')
            ->whereIn('classification', [CentralFinanceDataClassification::QA_TEST, CentralFinanceDataClassification::ARCHIVED])
            ->pluck('subject_id');
        if ($excludedTenantUserIds->isNotEmpty()) {
            $tenantUuids = $this->withTrustedTenantConnection($schoolId, 'staff', fn () => DB::connection('school')->table('users')
                ->whereIn('id', $excludedTenantUserIds)->pluck('central_finance_source_uuid')->filter()->values());
            $query->whereNotIn('users.id', DB::connection('mysql')->table('central_finance_school_staff_identities')
                ->where('school_id', $schoolId)->whereIn('tenant_user_uuid', $tenantUuids)
                ->pluck('central_user_id'));
        }

        return $query;
    }

    public function isTenantMetadataProduction(string $subjectType, int $schoolId, int $subjectId): bool
    {
        if (!isset(self::TENANT_SUBJECTS[$subjectType])) {
            throw new RuntimeException("Unsupported tenant classification subject: {$subjectType}");
        }
        if (!$this->schemaAvailable()) {
            if (app()->environment('production')) {
                throw new RuntimeException('Central Finance data isolation schema is missing.');
            }
            return true;
        }

        return $this->classification('school', $schoolId) === CentralFinanceDataClassification::PRODUCTION
            && $this->classification($subjectType, $subjectId, $this->tenantScope($schoolId)) === CentralFinanceDataClassification::PRODUCTION;
    }

    /**
     * A QA/Test School's own active master data may support an explicitly
     * authorised QA workflow. Archived data remains immutable everywhere.
     */
    public function isTenantMetadataWorkflowWritable(string $subjectType, int $schoolId, int $subjectId): bool
    {
        if (!isset(self::TENANT_SUBJECTS[$subjectType])) {
            throw new RuntimeException("Unsupported tenant classification subject: {$subjectType}");
        }
        if (!$this->schemaAvailable()) {
            if (app()->environment('production')) {
                throw new RuntimeException('Central Finance data isolation schema is missing.');
            }
            return true;
        }

        return $this->classification($subjectType, $subjectId, $this->tenantScope($schoolId)) !== CentralFinanceDataClassification::ARCHIVED
            && $this->classification('school', $schoolId) !== CentralFinanceDataClassification::ARCHIVED;
    }

    /**
     * Fee lifecycle uses a stricter contract than legacy metadata consumers:
     * QA Schools require an explicit QA record; unknown/archived/mismatched
     * classifications must never become selectable Fee Setup sources.
     * Existing unclassified Official records retain the canonical Production
     * default. This helper does not reclassify any historical record.
     */
    public function isTenantFeeMetadataWorkflowWritable(string $subjectType, int $schoolId, int $subjectId): bool
    {
        if (!$this->isFeeLifecycleSubject($subjectType)) {
            throw new RuntimeException('Unsupported Fee lifecycle classification subject.');
        }
        $schoolClass = $this->classification('school', $schoolId);
        if (!in_array($schoolClass, [CentralFinanceDataClassification::PRODUCTION, CentralFinanceDataClassification::QA_TEST], true)) {
            return false;
        }

        return $this->classification($subjectType, $subjectId, $this->tenantScope($schoolId)) === $schoolClass;
    }

    private function isFeeLifecycleSubject(string $subjectType): bool
    {
        return in_array($subjectType, ['fee', 'fee_type', 'fee_item', 'student_fee_assignment', 'student_fee_assignment_item'], true);
    }

    public function isTenantProduction(string $subjectType, int $schoolId, int $subjectId): bool
    {
        if (!$this->isTenantMetadataProduction($subjectType, $schoolId, $subjectId)) {
            return false;
        }
        $this->assertTrustedTenantConnection($schoolId, $subjectType);
        $subject = self::TENANT_SUBJECTS[$subjectType];
        $exists = DB::connection('school')->table($subject['table'])->where('id', $subjectId)->exists();
        if ($exists && ($subject['staff_relation'] ?? false)) {
            $exists = DB::connection('school')->table('staffs')->where('user_id', $subjectId)->exists();
        }

        return $exists;
    }

    public function assertTenantProduction(string $subjectType, int $schoolId, int $subjectId): void
    {
        if (!$this->isTenantProduction($subjectType, $schoolId, $subjectId)) {
            throw new AuthorizationException('QA/Test or archived tenant data is read-only and cannot be used by a Production workflow.');
        }
    }

    /**
     * QA/Test Schools remain excluded from Official views, but an explicitly
     * authorised QA workflow must be able to use its own active tenant data.
     * Archived records and archived Schools stay fail-closed.
     */
    public function assertTenantWorkflowWritable(string $subjectType, int $schoolId, int $subjectId): void
    {
        if (!isset(self::TENANT_SUBJECTS[$subjectType])) {
            throw new RuntimeException("Unsupported tenant classification subject: {$subjectType}");
        }
        if (!$this->schemaAvailable()) {
            if (app()->environment('production')) {
                throw new RuntimeException('Central Finance data isolation schema is missing.');
            }
            return;
        }

        $this->assertTrustedTenantConnection($schoolId, $subjectType);
        $subject = self::TENANT_SUBJECTS[$subjectType];
        $exists = DB::connection('school')->table($subject['table'])->where('id', $subjectId)->exists();
        if ($exists && ($subject['staff_relation'] ?? false)) {
            $exists = DB::connection('school')->table('staffs')->where('user_id', $subjectId)->exists();
        }
        if (!$exists
            || $this->classification($subjectType, $subjectId, $this->tenantScope($schoolId)) === CentralFinanceDataClassification::ARCHIVED
            || $this->classification('school', $schoolId) === CentralFinanceDataClassification::ARCHIVED) {
            throw new AuthorizationException('Archived or missing tenant data cannot be used by a workflow.');
        }
    }

    public function isProduction(string $subjectType, int $subjectId): bool
    {
        $subject = self::SUBJECTS[$subjectType] ?? null;
        if ($subject === null) {
            throw new RuntimeException("Unsupported Central Finance classification subject: {$subjectType}");
        }
        if (!$this->schemaAvailable()) {
            if (app()->environment('production')) {
                throw new RuntimeException('Central Finance data isolation schema is missing.');
            }
            return true;
        }
        $row = DB::connection('mysql')->table($subject['table'])->where('id', $subjectId)->first();
        if ($row === null) {
            return false;
        }
        if ($this->classification($subjectType, $subjectId) !== CentralFinanceDataClassification::PRODUCTION) {
            return false;
        }
        if ($subjectType === 'school' || $subject['school'] === null) {
            return true;
        }
        $schoolId = $row->{$subject['school']} ?? null;

        // HQ/shared Fund Accounts intentionally have no owning school. Their
        // usable School boundary is enforced by the allocation service, while
        // the classification itself remains global to the shared account.
        if ($schoolId === null && in_array($subjectType, ['fund_account', 'category', 'central_user', 'group_import_batch'], true)) {
            return true;
        }

        return $schoolId !== null
            && $this->classification('school', (int) $schoolId) === CentralFinanceDataClassification::PRODUCTION;
    }

    public function assertProduction(string $subjectType, int $subjectId): void
    {
        if (!$this->isProduction($subjectType, $subjectId)) {
            throw new AuthorizationException('QA/Test or archived data is read-only and cannot be used by a Production workflow.');
        }
    }

    /** Whether a central record is active for an explicitly authorised workflow. */
    public function isWorkflowWritable(string $subjectType, int $subjectId): bool
    {
        try {
            $this->assertWorkflowWritable($subjectType, $subjectId);
            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    /**
     * QA/Test Schools are intentionally excluded from official reporting, but
     * their authorized School workflow may create isolated QA fixtures.  This
     * helper is deliberately narrower than assertProduction(): archived data
     * is still immutable and no caller gains any role or School scope.
     */
    public function assertWorkflowWritable(string $subjectType, int $subjectId): void
    {
        $subject = self::SUBJECTS[$subjectType] ?? null;
        if ($subject === null) {
            throw new RuntimeException("Unsupported Central Finance classification subject: {$subjectType}");
        }
        if (!$this->schemaAvailable()) {
            if (app()->environment('production')) {
                throw new RuntimeException('Central Finance data isolation schema is missing.');
            }
            return;
        }

        $row = DB::connection('mysql')->table($subject['table'])->where('id', $subjectId)->first();
        if ($row === null || $this->classification($subjectType, $subjectId) === CentralFinanceDataClassification::ARCHIVED) {
            throw new AuthorizationException('Archived or missing data cannot be used by a workflow.');
        }
        if ($subjectType === 'school') {
            return;
        }
        $schoolColumn = $subject['school'];
        $schoolId = $schoolColumn === null ? null : ($row->{$schoolColumn} ?? null);
        if ($schoolId === null) {
            throw new AuthorizationException('A workflow record must have an owning School.');
        }
        if ($this->classification('school', (int) $schoolId) === CentralFinanceDataClassification::ARCHIVED) {
            throw new AuthorizationException('Archived School data cannot be used by a workflow.');
        }
        if ($this->isQaTestSchool((int) $schoolId)
            && in_array($subjectType, ['receivable', 'receivable_adjustment', 'promotion_application', 'student_discount_request', 'pending_collection', 'pending_collection_allocation', 'payment', 'payment_allocation', 'payment_refund', 'payment_reversal', 'collection_handover', 'collection_handover_item'], true)) {
            app(CentralFinanceQaRunService::class)->lockActiveRunForCentralRecord((int) $schoolId, $subjectType, $subjectId);
        }
    }

    public function isQaTestSchool(int $schoolId): bool
    {
        return $this->classification('school', $schoolId) === CentralFinanceDataClassification::QA_TEST;
    }

    public function supportsCentralSubject(string $subjectType): bool
    {
        return isset(self::SUBJECTS[$subjectType]);
    }

    /**
     * A QA School can only select an explicitly QA/Test Fund Account; an
     * Official School can only select an Official account. Allocation, active
     * state, currency and actor scope are still enforced by their canonical
     * services before this classification boundary is reached.
     */
    public function assertFundAccountMatchesSchoolWorkflow(int $schoolId, int $fundAccountId): void
    {
        $classification = $this->classification('fund_account', $fundAccountId);
        if ($this->isQaTestSchool($schoolId)) {
            if ($classification !== CentralFinanceDataClassification::QA_TEST) {
                throw new AuthorizationException('A QA/Test School may only use an explicitly QA/Test Fund Account.');
            }
            return;
        }

        if ($classification !== CentralFinanceDataClassification::PRODUCTION) {
            throw new AuthorizationException('An Official School may only use an Official Fund Account.');
        }
    }

    /**
     * New Central workflow records inherit QA/Test classification only from a
     * trusted QA School. Production records intentionally remain unclassified
     * (and therefore Production-compatible) to avoid broad data rewriting.
     */
    public function inheritWorkflowClassification(CentralFinanceUser $actor, int $schoolId, string $subjectType, int $subjectId): void
    {
        if (!$this->isQaTestSchool($schoolId)) {
            return;
        }

        $this->classify(
            $actor,
            $schoolId,
            $subjectType,
            $subjectId,
            CentralFinanceDataClassification::QA_TEST,
            'Inherited from the trusted QA/Test School workflow.',
        );
        $runs = app(CentralFinanceQaRunService::class);
        $runs->inheritCentralDocument($schoolId, $subjectType, $subjectId);
        $auditType = match ($subjectType) {
            'payment' => 'central_payment',
            'pending_collection' => 'pending_collection',
            'receivable_adjustment' => 'central_receivable_adjustment',
            'payment_refund' => 'central_payment_refund',
            'payment_reversal' => 'central_payment_reversal',
            'collection_handover' => 'collection_handover',
            'student_discount_request' => 'student_discount_request',
            default => null,
        };
        if ($auditType !== null && Schema::connection('mysql')->hasTable('central_finance_document_audits')) {
            foreach (DB::connection('mysql')->table('central_finance_document_audits')->where([
                'school_id' => $schoolId, 'document_type' => $auditType, 'document_id' => $subjectId,
            ])->pluck('id') as $auditId) {
                $runs->inheritAuditForDocumentAudit($schoolId, $auditType, $subjectId, (int) $auditId);
            }
        }
    }

    /** Group cash has no invented School: snapshot the authorized account's classification. */
    public function inheritUnidentifiedCashClassification(CentralFinanceUser $actor, \App\Models\CentralFinanceFundAccount $account, string $subjectType, int $subjectId): void
    {
        if (!in_array($subjectType, ['unidentified_deposit', 'ledger'], true) || !$this->schemaAvailable()) {
            throw new RuntimeException('Group cash classification schema or subject is invalid.');
        }
        app(CentralFinanceConfigurationAuthorizationService::class)->assertHeadFinanceCanConfigureGroup($actor, (int) $account->group_id);
        $row = DB::connection('mysql')->table(self::SUBJECTS[$subjectType]['table'])->where('id', $subjectId)->first();
        if (!$row || (int) $row->fund_account_id !== (int) $account->id || ($subjectType === 'ledger' && ($row->school_id !== null || $row->source_type !== 'central_unidentified_deposit'))) {
            throw new AuthorizationException('Group cash classification must match its original Fund Account.');
        }
        $classification = $this->classification('fund_account', (int) $account->id);
        if (!in_array($classification, [CentralFinanceDataClassification::PRODUCTION, CentralFinanceDataClassification::QA_TEST], true)) {
            throw new AuthorizationException('Archived accounts cannot receive unidentified money.');
        }
        $reason = 'Inherited from original Group Fund Account; School ownership is unknown.';
        $record = CentralFinanceDataClassification::on('mysql')->create([
            'school_id' => null, 'subject_scope' => 'central', 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'classification' => $classification, 'classified_by' => $actor->id, 'reason' => $reason,
        ]);
        CentralFinanceDataClassificationAudit::on('mysql')->create([
            'classification_id' => $record->id, 'school_id' => null, 'subject_scope' => 'central', 'subject_type' => $subjectType,
            'subject_id' => $subjectId, 'before_classification' => null, 'after_classification' => $classification,
            'reason' => $reason, 'actor_id' => $actor->id,
        ]);
    }

    public function classify(CentralFinanceUser $actor, int $schoolId, string $subjectType, int $subjectId, string $classification, string $reason): CentralFinanceDataClassification
    {
        if ((!isset(self::SUBJECTS[$subjectType]) && !isset(self::TENANT_SUBJECTS[$subjectType])) || !in_array($classification, CentralFinanceDataClassification::VALUES, true)) {
            throw ValidationException::withMessages(['classification' => ['The data classification is invalid.']]);
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['A data classification reason is required.']]);
        }
        if (!$this->schemaAvailable()) {
            throw new RuntimeException('Central Finance data isolation schema is missing.');
        }
        if ($subjectType === 'school') {
            app(CentralFinanceQaRunService::class)->assertSchoolClassification($subjectId, $classification);
        }
        $qaScope = isset(self::TENANT_SUBJECTS[$subjectType]) ? $this->tenantScope($schoolId) : 'central';
        if ($classification !== CentralFinanceDataClassification::QA_TEST
            && Schema::connection('mysql')->hasTable(CentralFinanceQaRunService::RECORDS_TABLE)
            && CentralFinanceQaRunRecord::on('mysql')->where('subject_scope', $qaScope)
                ->where('subject_type', $subjectType)->where('subject_id', $subjectId)->exists()) {
            throw new AuthorizationException('QA Run records remain classified as QA/Test for their entire lifetime.');
        }

        $tenantSubject = self::TENANT_SUBJECTS[$subjectType] ?? null;
        $subjectScope = $tenantSubject === null ? 'central' : $this->tenantScope($schoolId);
        $subject = self::SUBJECTS[$subjectType] ?? null;
        $row = $tenantSubject === null
            ? DB::connection('mysql')->table($subject['table'])->where('id', $subjectId)->first()
            : $this->withTrustedTenantConnection($schoolId, $subjectType, function () use ($tenantSubject, $subjectId) {
                $row = DB::connection('school')->table($tenantSubject['table'])->where('id', $subjectId)->first();
                if ($row !== null && ($tenantSubject['staff_relation'] ?? false)
                    && !DB::connection('school')->table('staffs')->where('user_id', $subjectId)->exists()) {
                    return null;
                }
                return $row;
            });
        if ($row === null) {
            throw ValidationException::withMessages(['subject' => ['The classified record does not exist.']]);
        }
        $rawSchoolId = $tenantSubject !== null || $subject['school'] === null ? null : ($row->{$subject['school']} ?? null);
        $recordSchoolId = $tenantSubject !== null ? $schoolId : ($subjectType === 'school'
            ? (int) $subjectId
            : ($rawSchoolId === null ? null : (int) $rawSchoolId));
        if ($subjectType === 'collection_handover_item') {
            $recordSchoolId = (int) DB::connection('mysql')->table('central_finance_collection_handover_batches')->where('id', $row->handover_batch_id)->value('school_id');
        }
        if ($recordSchoolId !== null && $recordSchoolId !== $schoolId) {
            throw new AuthorizationException('The classified record does not belong to the authorized School.');
        }
        if ($tenantSubject === null && $recordSchoolId === null && $subjectType !== 'school') {
            $groupColumn = in_array($subjectType, ['fund_account', 'category', 'promotion'], true) ? 'group_id' : ($subjectType === 'group_import_batch' ? 'finance_group_id' : null);
            $groupId = $groupColumn ? (int) ($row->{$groupColumn} ?? 0) : 0;
            $isGroupSchool = $subjectType === 'central_user'
                ? DB::connection('mysql')->table('central_finance_user_school_scopes')->where([
                    'user_id' => $subjectId, 'school_id' => $schoolId, 'can_view' => true,
                ])->exists()
                : ($groupId > 0 && DB::connection('mysql')->table('finance_group_schools')->where([
                'group_id' => $groupId, 'school_id' => $schoolId, 'status' => 'active',
                ])->exists());
            if (!$isGroupSchool) {
                throw new AuthorizationException('The classified record is not part of the authorized School group.');
            }
        }

        return DB::connection('mysql')->transaction(function () use ($actor, $schoolId, $subjectScope, $subjectType, $subjectId, $classification, $reason): CentralFinanceDataClassification {
            $record = CentralFinanceDataClassification::on('mysql')->where([
                'subject_scope' => $subjectScope, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            ])->lockForUpdate()->first();
            $before = $record?->classification;
            if ($record === null) {
                $record = new CentralFinanceDataClassification([
                    'school_id' => $schoolId, 'subject_scope' => $subjectScope, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
                ]);
            }
            $record->fill(['classification' => $classification, 'reason' => $reason, 'classified_by' => $actor->id]);
            // Current attribution must replace any inherited tenant actor. These
            // optional fields are server-owned and not mass assignable by callers.
            $record->forceFill($this->centralActorMetadata(self::TABLE))->save();
            $audit = new CentralFinanceDataClassificationAudit([
                'classification_id' => $record->id, 'school_id' => $schoolId,
                'subject_scope' => $subjectScope, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
                'before_classification' => $before, 'after_classification' => $classification,
                'reason' => $reason, 'actor_id' => $actor->id,
            ]);
            $audit->forceFill($this->centralActorMetadata(self::AUDIT_TABLE))->save();

            return $record->fresh();
        });
    }

    /** Preserve compatibility before and during the additive actor migration. */
    private function centralActorMetadata(string $table): array
    {
        $metadata = ['actor_scope' => 'central', 'actor_school_id' => null, 'actor_tenant_user_id' => null];
        if ($table === self::AUDIT_TABLE) {
            $metadata['action'] = 'classification_changed';
        }
        $schema = Schema::connection('mysql');

        return array_filter($metadata, static fn (string $column): bool => $schema->hasColumn($table, $column), ARRAY_FILTER_USE_KEY);
    }

    /** @param Builder<\Illuminate\Database\Eloquent\Model> $query */
    private function applyExactClassification(Builder $query, string $subjectType, string $subjectScope, string $classification): Builder
    {
        $subject = self::SUBJECTS[$subjectType] ?? null;
        if ($subject === null) {
            throw new RuntimeException("Unsupported Central Finance classification subject: {$subjectType}");
        }
        if (!$this->schemaAvailable()) {
            if (app()->environment('production')) {
                throw new RuntimeException('Central Finance data isolation schema is missing.');
            }
            return $query;
        }

        $qualifiedId = $query->getModel()->qualifyColumn($query->getModel()->getKeyName());

        return $query->whereExists(function (QueryBuilder $classificationQuery) use ($subjectScope, $subjectType, $classification, $qualifiedId): void {
            $classificationQuery->selectRaw('1')->from(self::TABLE.' as cf_workflow_classification')
                ->where('cf_workflow_classification.subject_scope', $subjectScope)
                ->where('cf_workflow_classification.subject_type', $subjectType)
                ->where('cf_workflow_classification.classification', $classification)
                ->whereColumn('cf_workflow_classification.subject_id', $qualifiedId);
        });
    }

    private function assertTrustedTenantConnection(int $schoolId, string $subjectType): School
    {
        $subject = self::TENANT_SUBJECTS[$subjectType] ?? null;
        if ($subject === null) {
            throw new RuntimeException("Unsupported tenant classification subject: {$subjectType}");
        }
        $school = School::on('mysql')->find($schoolId);
        if ($school === null || trim((string) $school->getRawOriginal('database_name')) === '') {
            throw new RuntimeException('The trusted School tenant registry is missing.');
        }
        $expected = (string) $school->getRawOriginal('database_name');
        $actual = (string) DB::connection('school')->getDatabaseName();
        if ($actual !== $expected) {
            throw new RuntimeException("Tenant database registry mismatch for School {$schoolId}.");
        }
        if (!Schema::connection('school')->hasTable($subject['table'])
            || (($subject['staff_relation'] ?? false) && !Schema::connection('school')->hasTable('staffs'))) {
            throw new RuntimeException("Tenant classification schema is incomplete for {$subjectType}.");
        }

        return $school;
    }

    /** Run a narrowly scoped read against the trusted registry tenant. */
    public function withTrustedTenantRead(int $schoolId, string $subjectType, callable $callback): mixed
    {
        return $this->withTrustedTenantConnection($schoolId, $subjectType, $callback);
    }

    private function withTrustedTenantConnection(int $schoolId, string $subjectType, callable $callback): mixed
    {
        $school = School::on('mysql')->find($schoolId);
        if ($school === null || trim((string) $school->getRawOriginal('database_name')) === '') {
            throw new RuntimeException('The trusted School tenant registry is missing.');
        }
        $original = Config::get('database.connections.school');
        $database = (string) $school->getRawOriginal('database_name');
        Config::set('database.connections.school', array_merge($original, ['database' => $database]));
        DB::purge('school');
        try {
            $this->assertTrustedTenantConnection($schoolId, $subjectType);
            return $callback();
        } finally {
            DB::disconnect('school');
            Config::set('database.connections.school', $original);
            DB::purge('school');
        }
    }
}
