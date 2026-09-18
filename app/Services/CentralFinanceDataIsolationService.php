<?php

namespace App\Services;

use App\Models\CentralFinanceDataClassification;
use App\Models\CentralFinanceDataClassificationAudit;
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
        'pending_collection' => ['table' => 'central_finance_pending_collections', 'school' => 'school_id'],
        'collection_handover' => ['table' => 'central_finance_collection_handover_batches', 'school' => 'school_id'],
        'fund_handover' => ['table' => 'central_finance_fund_handovers', 'school' => 'school_id'],
        'import_batch' => ['table' => 'central_finance_import_batches', 'school' => 'school_id'],
        'group_import_batch' => ['table' => 'central_finance_group_import_batches', 'school' => null],
        'payment' => ['table' => 'central_finance_payments', 'school' => 'school_id'],
        'receipt' => ['table' => 'central_finance_receipts', 'school' => 'school_id'],
        'ledger' => ['table' => 'central_finance_ledger_entries', 'school' => 'school_id'],
        'expense' => ['table' => 'central_finance_expenses', 'school' => 'school_id'],
        'other_income' => ['table' => 'central_finance_other_incomes', 'school' => 'school_id'],
        'internal_transfer' => ['table' => 'central_finance_internal_transfers', 'school' => 'school_id'],
        'reimbursement' => ['table' => 'central_finance_reimbursement_requests', 'school' => 'school_id'],
        'hq_funding' => ['table' => 'central_finance_hq_funding_requests', 'school' => 'school_id'],
    ];

    /** @var array<string, array{table:string,staff_relation?:bool}> */
    private const TENANT_SUBJECTS = [
        'student' => ['table' => 'students'],
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
        if ($includeQaTest) {
            return $query;
        }
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

        return $query;
    }

    public function classification(string $subjectType, int $subjectId, string $subjectScope = 'central'): string
    {
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
    }

    public function isQaTestSchool(int $schoolId): bool
    {
        return $this->classification('school', $schoolId) === CentralFinanceDataClassification::QA_TEST;
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
        if ($recordSchoolId !== null && $recordSchoolId !== $schoolId) {
            throw new AuthorizationException('The classified record does not belong to the authorized School.');
        }
        if ($tenantSubject === null && $recordSchoolId === null && $subjectType !== 'school') {
            $groupColumn = in_array($subjectType, ['fund_account', 'category'], true) ? 'group_id' : ($subjectType === 'group_import_batch' ? 'finance_group_id' : null);
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
                $record = CentralFinanceDataClassification::on('mysql')->create([
                    'school_id' => $schoolId, 'subject_scope' => $subjectScope, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
                    'classification' => $classification, 'reason' => $reason, 'classified_by' => $actor->id,
                ]);
            } else {
                $record->update(['classification' => $classification, 'reason' => $reason, 'classified_by' => $actor->id]);
            }
            CentralFinanceDataClassificationAudit::on('mysql')->create([
                'classification_id' => $record->id, 'school_id' => $schoolId,
                'subject_scope' => $subjectScope, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
                'before_classification' => $before, 'after_classification' => $classification,
                'reason' => $reason, 'actor_id' => $actor->id,
            ]);

            return $record->fresh();
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
