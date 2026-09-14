<?php

namespace App\Services;

use App\Models\CentralFinanceDataClassification;
use App\Models\CentralFinanceDataClassificationAudit;
use App\Models\CentralFinanceUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function schemaAvailable(): bool
    {
        return Schema::connection('mysql')->hasTable(self::TABLE)
            && Schema::connection('mysql')->hasTable(self::AUDIT_TABLE);
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
                ->where('cf_direct_classification.subject_type', $subjectType)
                ->whereColumn('cf_direct_classification.subject_id', $qualifiedId)
                ->whereIn('cf_direct_classification.classification', [CentralFinanceDataClassification::QA_TEST, CentralFinanceDataClassification::ARCHIVED]);
        });

        $schoolColumn ??= $subject['school'];
        if ($subjectType !== 'school' && $schoolColumn !== null) {
            $qualifiedSchool = str_contains($schoolColumn, '.') ? $schoolColumn : $table.'.'.$schoolColumn;
            $query->whereNotExists(function (QueryBuilder $classification) use ($qualifiedSchool): void {
                $classification->selectRaw('1')->from(self::TABLE.' as cf_school_classification')
                    ->where('cf_school_classification.subject_type', 'school')
                    ->whereColumn('cf_school_classification.subject_id', $qualifiedSchool)
                    ->whereIn('cf_school_classification.classification', [CentralFinanceDataClassification::QA_TEST, CentralFinanceDataClassification::ARCHIVED]);
            });
        }

        return $query;
    }

    public function classification(string $subjectType, int $subjectId): string
    {
        if (!$this->schemaAvailable()) {
            return CentralFinanceDataClassification::PRODUCTION;
        }

        return CentralFinanceDataClassification::on('mysql')->where([
            'subject_type' => $subjectType, 'subject_id' => $subjectId,
        ])->value('classification') ?? CentralFinanceDataClassification::PRODUCTION;
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
        if ($schoolId === null && $subjectType === 'fund_account') {
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

    public function classify(CentralFinanceUser $actor, int $schoolId, string $subjectType, int $subjectId, string $classification, string $reason): CentralFinanceDataClassification
    {
        if (!isset(self::SUBJECTS[$subjectType]) || !in_array($classification, CentralFinanceDataClassification::VALUES, true)) {
            throw ValidationException::withMessages(['classification' => ['The data classification is invalid.']]);
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['A data classification reason is required.']]);
        }
        if (!$this->schemaAvailable()) {
            throw new RuntimeException('Central Finance data isolation schema is missing.');
        }

        $subject = self::SUBJECTS[$subjectType];
        $row = DB::connection('mysql')->table($subject['table'])->where('id', $subjectId)->first();
        if ($row === null) {
            throw ValidationException::withMessages(['subject' => ['The classified record does not exist.']]);
        }
        $rawSchoolId = $subject['school'] === null ? null : ($row->{$subject['school']} ?? null);
        $recordSchoolId = $subjectType === 'school'
            ? (int) $subjectId
            : ($rawSchoolId === null ? null : (int) $rawSchoolId);
        if ($recordSchoolId !== null && $recordSchoolId !== $schoolId) {
            throw new AuthorizationException('The classified record does not belong to the authorized School.');
        }
        if ($recordSchoolId === null && $subjectType !== 'school') {
            $groupColumn = $subjectType === 'fund_account' ? 'group_id' : ($subjectType === 'group_import_batch' ? 'finance_group_id' : null);
            $groupId = $groupColumn ? (int) ($row->{$groupColumn} ?? 0) : 0;
            $isGroupSchool = $groupId > 0 && DB::connection('mysql')->table('finance_group_schools')->where([
                'group_id' => $groupId, 'school_id' => $schoolId, 'status' => 'active',
            ])->exists();
            if (!$isGroupSchool) {
                throw new AuthorizationException('The classified record is not part of the authorized School group.');
            }
        }

        return DB::connection('mysql')->transaction(function () use ($actor, $schoolId, $subjectType, $subjectId, $classification, $reason): CentralFinanceDataClassification {
            $record = CentralFinanceDataClassification::on('mysql')->where([
                'subject_type' => $subjectType, 'subject_id' => $subjectId,
            ])->lockForUpdate()->first();
            $before = $record?->classification;
            if ($record === null) {
                $record = CentralFinanceDataClassification::on('mysql')->create([
                    'school_id' => $schoolId, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
                    'classification' => $classification, 'reason' => $reason, 'classified_by' => $actor->id,
                ]);
            } else {
                $record->update(['classification' => $classification, 'reason' => $reason, 'classified_by' => $actor->id]);
            }
            CentralFinanceDataClassificationAudit::on('mysql')->create([
                'classification_id' => $record->id, 'school_id' => $schoolId,
                'subject_type' => $subjectType, 'subject_id' => $subjectId,
                'before_classification' => $before, 'after_classification' => $classification,
                'reason' => $reason, 'actor_id' => $actor->id,
            ]);

            return $record->fresh();
        });
    }
}
