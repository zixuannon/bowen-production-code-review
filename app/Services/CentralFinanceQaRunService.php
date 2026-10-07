<?php

namespace App\Services;

use App\Models\CentralFinanceDataClassification;
use App\Models\CentralFinanceDataClassificationAudit;
use App\Models\CentralFinanceDocumentAudit;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceLedgerEntry;
use App\Models\CentralFinanceQaRun;
use App\Models\CentralFinanceQaRunRecord;
use App\Models\CentralFinanceStudentProfile;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceUnidentifiedDeposit;
use App\Models\CentralFinanceUser;
use App\Models\School;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Central run lifecycle, immutable subject membership and Finance write gate. */
final class CentralFinanceQaRunService
{
    public const TABLE = 'central_finance_qa_runs';
    public const RECORDS_TABLE = 'central_finance_qa_run_records';

    /** Types whose identities are tenant-local and must include tenant:<school_id>. */
    private const TENANT_TYPES = ['student', 'student_fee_assignment', 'student_fee_assignment_item', 'student_import_reference'];

    public function __construct(private readonly CentralFinanceQaSchoolIdentity $qaSchool) {}

    public function permanentQaSchoolId(): ?int
    {
        return $this->qaSchool->resolve()?->id;
    }

    public function isPermanentQaSchool(int $schoolId): bool
    {
        return $this->qaSchool->isSchool($schoolId);
    }

    /** The stored school classification is immutable for MMBOWEN01. */
    public function assertSchoolClassification(int $schoolId, string $classification): void
    {
        if ($this->isPermanentQaSchool($schoolId) && $classification !== CentralFinanceDataClassification::QA_TEST) {
            throw new AuthorizationException('Zixuan MMBOWEN01 is permanently classified as QA/Test.');
        }
    }

    /** A fail-closed isolation boundary even if legacy classification metadata is missing. */
    public function applyPermanentOfficialExclusion($query, ?string $schoolColumn): void
    {
        $schoolId = $this->permanentQaSchoolId();
        if (!$schoolId) {
            return;
        }
        if ($schoolColumn === null) {
            if ($query->getModel()->getTable() === 'schools') {
                $query->where($query->getModel()->qualifyColumn('id'), '<>', $schoolId);
            }
            return;
        }
        $qualifiedSchool = $query->getModel()->qualifyColumn($schoolColumn);
        // Unassigned Group cash is not a permanent QA School record. Its
        // visibility still requires the caller's explicit Group/account scope.
        $query->where(fn ($scope) => $scope->whereNull($qualifiedSchool)->orWhere($qualifiedSchool, '<>', $schoolId));
    }

    public function isQaSchoolClassificationFallback(string $subjectType, int $subjectId): bool
    {
        return $subjectType === 'school' && $this->isPermanentQaSchool($subjectId);
    }

    public function runsForSchool(int $schoolId, bool $includeArchived = true)
    {
        $query = CentralFinanceQaRun::on('mysql')->where('school_id', $schoolId)->orderByDesc('run_number');
        if (!$includeArchived) {
            $query->where('status', '!=', CentralFinanceQaRun::ARCHIVED);
        }
        return $query->get();
    }

    public function currentRun(int $schoolId, bool $lock = false): ?CentralFinanceQaRun
    {
        $this->assertRunSchema();
        $query = CentralFinanceQaRun::on('mysql')->where('school_id', $schoolId)
            ->whereIn('status', [CentralFinanceQaRun::PREPARING, CentralFinanceQaRun::ACTIVE]);
        if ($lock) {
            $query->lockForUpdate();
        }
        $runs = $query->get();
        if ($runs->count() > 1) {
            throw new RuntimeException('More than one unfinished QA Run exists for this School.');
        }
        return $runs->first();
    }

    public function create(CentralFinanceUser $actor, int $schoolId, string $label): CentralFinanceQaRun
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 191) {
            throw ValidationException::withMessages(['label' => ['Enter a QA Run label of at most 191 characters.']]);
        }
        $school = $this->assertPermanentQaSchool($schoolId);

        return DB::connection('mysql')->transaction(function () use ($actor, $school, $label): CentralFinanceQaRun {
            School::on('mysql')->whereKey($school->id)->lockForUpdate()->firstOrFail();
            $classification = CentralFinanceDataClassification::on('mysql')->where([
                'subject_scope' => 'central', 'subject_type' => 'school', 'subject_id' => $school->id,
            ])->lockForUpdate()->first();
            if ($classification?->classification === CentralFinanceDataClassification::ARCHIVED) {
                throw new AuthorizationException('Archived School identity cannot be enabled for QA Runs.');
            }
            if ($classification?->classification !== CentralFinanceDataClassification::QA_TEST) {
                app(CentralFinanceDataIsolationService::class)->classify(
                    $actor, (int) $school->id, 'school', (int) $school->id,
                    CentralFinanceDataClassification::QA_TEST,
                    'Permanently reserve trusted MMBOWEN01 identity for QA/Test Finance Runs.',
                );
            }
            if ($this->currentRun((int) $school->id, true)) {
                throw ValidationException::withMessages(['run' => ['Archive the current QA Run before creating another.']]);
            }
            $latest = CentralFinanceQaRun::on('mysql')->where('school_id', $school->id)->orderByDesc('run_number')->lockForUpdate()->first();
            if ($latest && $latest->status !== CentralFinanceQaRun::ARCHIVED) {
                throw ValidationException::withMessages(['run' => ['Complete and archive the previous QA Run before creating another.']]);
            }
            $number = (int) CentralFinanceQaRun::on('mysql')->where('school_id', $school->id)->max('run_number') + 1;
            $run = CentralFinanceQaRun::on('mysql')->create([
                'run_uuid' => (string) Str::uuid(), 'school_id' => $school->id,
                'run_number' => $number, 'label' => $label,
                'status' => CentralFinanceQaRun::PREPARING, 'created_by' => $actor->id,
            ]);
            $this->audit($actor, $run, 'created', null, ['status' => $run->status, 'run_number' => $number, 'label' => $label]);
            return $run;
        });
    }

    public function activate(CentralFinanceUser $actor, int $runId): CentralFinanceQaRun
    {
        return DB::connection('mysql')->transaction(function () use ($actor, $runId): CentralFinanceQaRun {
            $run = CentralFinanceQaRun::on('mysql')->lockForUpdate()->findOrFail($runId);
            $this->assertPermanentQaSchool((int) $run->school_id);
            if ($run->status !== CentralFinanceQaRun::PREPARING) {
                throw ValidationException::withMessages(['run' => ['Only a Preparing QA Run can be activated.']]);
            }
            $students = $run->records()->where('subject_type', 'student')->count();
            if ($students < 1) {
                throw ValidationException::withMessages(['run' => ['Import at least one fresh QA Student before activating this Run.']]);
            }
            $unlinkedReferences = $run->records()
                ->where('subject_type', 'student_import_reference')
                ->whereNull('subject_id')
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from(self::RECORDS_TABLE.' as linked_student')
                        ->whereColumn('linked_student.qa_run_id', 'central_finance_qa_run_records.qa_run_id')
                        ->whereColumn('linked_student.source_identity', 'central_finance_qa_run_records.source_identity')
                        ->where('linked_student.subject_type', 'student')
                        ->whereColumn('linked_student.subject_scope', 'central_finance_qa_run_records.subject_scope');
                })
                ->exists();
            if ($unlinkedReferences) {
                throw ValidationException::withMessages(['run' => ['Reconcile pending Student Import V2 rows before activating this Run.']]);
            }
            $before = ['status' => $run->status];
            $run->update(['status' => CentralFinanceQaRun::ACTIVE, 'activated_at' => now()]);
            $this->audit($actor, $run, 'activated', $before, ['status' => $run->status]);
            return $run->fresh();
        });
    }

    public function complete(CentralFinanceUser $actor, int $runId): CentralFinanceQaRun
    {
        return DB::connection('mysql')->transaction(function () use ($actor, $runId): CentralFinanceQaRun {
            $run = CentralFinanceQaRun::on('mysql')->lockForUpdate()->findOrFail($runId);
            $this->assertPermanentQaSchool((int) $run->school_id);
            if ($run->status !== CentralFinanceQaRun::ACTIVE) {
                throw ValidationException::withMessages(['run' => ['Only an Active QA Run can be completed.']]);
            }
            $summary = $run->records()->selectRaw('subject_type, COUNT(*) as records_count')->groupBy('subject_type')->pluck('records_count', 'subject_type')->all();
            $before = ['status' => $run->status];
            $run->update([
                'status' => CentralFinanceQaRun::COMPLETED,
                'completed_at' => now(), 'completed_by' => $actor->id,
                'completion_summary' => ['record_counts' => $summary, 'completed_at' => now()->toIso8601String()],
            ]);
            $this->audit($actor, $run, 'completed', $before, ['status' => $run->status, 'summary' => $summary]);
            return $run->fresh();
        });
    }

    public function archive(CentralFinanceUser $actor, int $runId, string $reason): CentralFinanceQaRun
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => ['An archive reason of at most 2000 characters is required.']]);
        }
        return DB::connection('mysql')->transaction(function () use ($actor, $runId, $reason): CentralFinanceQaRun {
            $run = CentralFinanceQaRun::on('mysql')->lockForUpdate()->findOrFail($runId);
            $this->assertPermanentQaSchool((int) $run->school_id);
            if ($run->status !== CentralFinanceQaRun::COMPLETED) {
                throw ValidationException::withMessages(['run' => ['Complete the QA Run before archiving it.']]);
            }
            $before = ['status' => $run->status];
            $run->update(['status' => CentralFinanceQaRun::ARCHIVED, 'archived_at' => now(), 'archived_by' => $actor->id, 'archive_reason' => $reason]);
            $this->audit($actor, $run, 'archived', $before, ['status' => $run->status, 'reason' => $reason]);
            return $run->fresh();
        });
    }

    /** Reserve a deterministic import identity before tenant Student creation. */
    public function reserveStudentImport(int $schoolId, string $importReference): ?CentralFinanceQaRun
    {
        if (!$this->isPermanentQaSchool($schoolId)) {
            return null;
        }
        $identity = $this->tenantSourceIdentity($schoolId, $importReference);
        return DB::connection('mysql')->transaction(function () use ($schoolId, $identity): CentralFinanceQaRun {
            $school = $this->assertPermanentQaSchool($schoolId);
            School::on('mysql')->whereKey($school->id)->lockForUpdate()->firstOrFail();
            $locked = $this->currentRun($schoolId, true);
            if (!$locked) {
                throw new AuthorizationException('Zixuan QA Student Import requires a Preparing QA Run.');
            }
            if ($locked->status !== CentralFinanceQaRun::PREPARING) {
                throw new AuthorizationException('QA Student provisioning has closed for this Run.');
            }
            $this->insertMembership($locked, $schoolId, 'tenant:'.$schoolId, 'student_import_reference', null, $identity, false);
            return $locked;
        });
    }

    /** Link committed V2 Student rows to their reserved Run identities; safe to retry. */
    public function linkImportedStudents(CentralFinanceUser $actor, int $schoolId, int $runId, array $students): void
    {
        if (!$this->isPermanentQaSchool($schoolId)) {
            return;
        }
        DB::connection('mysql')->transaction(function () use ($actor, $schoolId, $runId, $students): void {
            $run = CentralFinanceQaRun::on('mysql')->lockForUpdate()->findOrFail($runId);
            if ((int) $run->school_id !== $schoolId || $run->status !== CentralFinanceQaRun::PREPARING) {
                throw new AuthorizationException('Student provisioning is closed or belongs to another QA Run.');
            }
            foreach ($students as $student) {
                $studentId = (int) ($student['student_id'] ?? 0);
                $reference = (string) ($student['import_reference'] ?? '');
                if ($studentId < 1 || $reference === '') {
                    throw new RuntimeException('The Student Import V2 result is incomplete.');
                }
                $identity = $this->tenantSourceIdentity($schoolId, $reference);
                $reserved = CentralFinanceQaRunRecord::on('mysql')->where([
                    'qa_run_id' => $run->id, 'subject_scope' => 'tenant:'.$schoolId,
                    'subject_type' => 'student_import_reference', 'source_identity' => $identity,
                ])->exists();
                if (!$reserved) {
                    throw new AuthorizationException('Student Import Reference was not reserved by this QA Run.');
                }
                $this->insertMembership($run, $schoolId, 'tenant:'.$schoolId, 'student', $studentId, $identity, false);
                $this->classifyTenantStudent($actor, $schoolId, $studentId, 'Inherited from QA Run '.$run->run_number.' Student Import V2 provisioning.');
                // Profile synchronization can win the race and commit before
                // Student Import V2 links the new tenant identity. Reconcile
                // that already-created projection now so subsequent Finance
                // documents inherit this Run instead of becoming legacy rows.
                if (\Illuminate\Support\Facades\Schema::connection('mysql')->hasTable('central_finance_student_profiles')) {
                    $profile = CentralFinanceStudentProfile::on('mysql')->where([
                        'school_id' => $schoolId,
                        'tenant_student_id' => $studentId,
                    ])->first();
                    if ($profile !== null) {
                        $this->inheritTenantStudentProfile($schoolId, $studentId, (int) $profile->id);
                    }
                }
            }
        });
    }

    /** Recover a tenant commit whose central membership write did not complete. */
    public function reconcilePreparingRun(CentralFinanceUser $actor, int $runId): int
    {
        $run = CentralFinanceQaRun::on('mysql')->findOrFail($runId);
        if (!$this->isPermanentQaSchool((int) $run->school_id) || $run->status !== CentralFinanceQaRun::PREPARING) {
            throw new AuthorizationException('Only the permanent QA School\'s Preparing Run can be reconciled.');
        }
        $school = app(CentralFinanceQaSchoolIdentity::class)->resolve();
        $found = 0;
        app(CentralFinanceDataIsolationService::class)->withTrustedTenantRead((int) $school->id, 'student', function () use ($actor, $run, $school, &$found): void {
            $schema = DB::connection('school')->getSchemaBuilder();
            if (!$schema->hasTable('student_import_identities')) {
                return;
            }
            $reserved = CentralFinanceQaRunRecord::on('mysql')->where([
                'qa_run_id' => $run->id, 'subject_scope' => 'tenant:'.$school->id,
                'subject_type' => 'student_import_reference',
            ])->whereNotNull('source_identity')->pluck('source_identity')->flip();
            if ($reserved->isEmpty()) {
                return;
            }
            $identities = DB::connection('school')->table('student_import_identities')->get(['student_id', 'import_reference']);
            foreach ($identities as $identity) {
                $hash = $this->tenantSourceIdentity((int) $school->id, (string) $identity->import_reference);
                if (!$reserved->has($hash)) {
                    continue;
                }
                $this->linkImportedStudents($actor, (int) $school->id, (int) $run->id, [[
                    'student_id' => (int) $identity->student_id,
                    'import_reference' => (string) $identity->import_reference,
                ]]);
                $found++;
            }
        });
        return $found;
    }

    /** Propagate a member's immutable Run to a created central/tenant-backed record. */
    public function inheritCentral(int $schoolId, string $parentType, int $parentId, string $childType, int $childId, bool $allowClosed = false): ?CentralFinanceQaRun
    {
        return $this->inherit($schoolId, 'central', $parentType, $parentId, 'central', $childType, $childId, $allowClosed);
    }

    public function inheritTenant(int $schoolId, string $parentType, int $parentId, string $childType, int $childId, bool $allowClosed = false): ?CentralFinanceQaRun
    {
        return $this->inherit($schoolId, 'tenant:'.$schoolId, $parentType, $parentId, 'central', $childType, $childId, $allowClosed);
    }

    /** Resolve one Run for the complete set; QA records without membership are legacy read-only history. */
    public function lockActiveRunForCentralRecords(int $schoolId, string $subjectType, array $subjectIds): ?CentralFinanceQaRun
    {
        if (!$this->isPermanentQaSchool($schoolId)) {
            return null;
        }
        $this->assertRunSchema();
        $ids = array_values(array_unique(array_map('intval', $subjectIds)));
        if ($ids === []) {
            throw new AuthorizationException('A QA Run record is required for this Finance workflow.');
        }
        $records = CentralFinanceQaRunRecord::on('mysql')->where('subject_scope', 'central')
            ->where('subject_type', $subjectType)->whereIn('subject_id', $ids)->get()->keyBy('subject_id');
        if ($records->count() !== count($ids)) {
            throw new AuthorizationException('Historical QA Finance data without a Run remains read-only.');
        }
        $runIds = $records->pluck('qa_run_id')->unique();
        if ($runIds->count() !== 1) {
            throw new AuthorizationException('Finance records from different QA Runs cannot be combined.');
        }
        $run = CentralFinanceQaRun::on('mysql')->lockForUpdate()->findOrFail((int) $runIds->first());
        if ((int) $run->school_id !== $schoolId || $run->status !== CentralFinanceQaRun::ACTIVE) {
            throw new AuthorizationException('This QA Run is not Active; no Finance write is allowed.');
        }
        return $run;
    }

    public function lockActiveRunForCentralRecord(int $schoolId, string $subjectType, int $subjectId): ?CentralFinanceQaRun
    {
        return $this->lockActiveRunForCentralRecords($schoolId, $subjectType, [$subjectId]);
    }

    /**
     * The account's classification School identifies QA ownership only; it
     * never attributes unidentified physical cash to School revenue.
     */
    public function lockActiveRunForUnidentifiedCash(CentralFinanceFundAccount $account): ?CentralFinanceQaRun
    {
        $isolation = app(CentralFinanceDataIsolationService::class);
        if ($isolation->classification('fund_account', (int) $account->id) !== CentralFinanceDataClassification::QA_TEST) return null;
        $context = CentralFinanceDataClassification::on('mysql')->where([
            'subject_scope' => 'central', 'subject_type' => 'fund_account', 'subject_id' => $account->id,
        ])->lockForUpdate()->first();
        $schoolId = (int) ($context?->school_id ?? 0);
        $permanentQaSchoolId = $this->permanentQaSchoolId();
        if (!$schoolId && $permanentQaSchoolId) {
            throw new AuthorizationException('QA unidentified cash requires an unambiguous Fund Account classification context.');
        }
        if (!$permanentQaSchoolId || $schoolId !== $permanentQaSchoolId) return null;
        $this->assertUnidentifiedPostingTransaction();
        // The permanent School's classification() fallback deliberately stays
        // QA/Test, so inspect its explicit archive marker as well.
        if (CentralFinanceDataClassification::on('mysql')->where([
            'subject_scope' => 'central', 'subject_type' => 'school', 'subject_id' => $schoolId,
            'classification' => CentralFinanceDataClassification::ARCHIVED,
        ])->exists()) {
            throw new AuthorizationException('Archived QA School context cannot receive unidentified cash.');
        }
        $run = $this->currentRun($schoolId, true);
        if (!$run || $run->status !== CentralFinanceQaRun::ACTIVE) {
            throw new AuthorizationException('QA unidentified cash requires an Active QA Run.');
        }
        return $run;
    }

    /** Attach the original unknown-school facts to the already-locked QA Run. */
    public function registerUnidentifiedCash(CentralFinanceQaRun $run, CentralFinanceUnidentifiedDeposit $deposit, CentralFinanceLedgerEntry $ledger, CentralFinanceDocumentAudit $audit): void
    {
        $this->assertUnidentifiedPostingTransaction();
        $run = CentralFinanceQaRun::on('mysql')->lockForUpdate()->findOrFail($run->id);
        if (!$this->isPermanentQaSchool((int) $run->school_id) || $run->status !== CentralFinanceQaRun::ACTIVE
            || $ledger->school_id !== null || (int) $ledger->fund_account_id !== (int) $deposit->fund_account_id
            || $ledger->source_type !== 'central_unidentified_deposit' || $ledger->source_id !== $deposit->deposit_uuid
            || $audit->school_id !== null || $audit->document_type !== 'unidentified_deposit' || (int) $audit->document_id !== (int) $deposit->id
            || app(CentralFinanceDataIsolationService::class)->classification('unidentified_deposit', (int) $deposit->id) !== CentralFinanceDataClassification::QA_TEST) {
            throw new AuthorizationException('Unidentified cash QA membership must preserve its original active Run and unknown School.');
        }
        foreach ([['unidentified_deposit', $deposit->id], ['ledger', $ledger->id], ['audit', $audit->id]] as [$type, $id]) {
            $this->insertMembership($run, (int) $run->school_id, 'central', $type, (int) $id, null, false);
        }
    }

    /** Original QA cash can settle only a receivable from its same Active Run. */
    public function lockActiveRunForUnidentifiedAllocation(CentralFinanceUnidentifiedDeposit $deposit, CentralFinanceReceivable $receivable): ?CentralFinanceQaRun
    {
        $depositRun = $this->runForRecord('central', 'unidentified_deposit', (int) $deposit->id);
        if (!$depositRun && !$this->isPermanentQaSchool((int) $receivable->school_id)) return null;
        $this->assertUnidentifiedPostingTransaction();
        if (!$depositRun || !$this->isPermanentQaSchool((int) $depositRun->school_id)
            || (int) $depositRun->school_id !== (int) $receivable->school_id) {
            throw new AuthorizationException('Unidentified cash and its target must belong to the same QA Run.');
        }
        $this->lockActiveRunForCentralRecord((int) $depositRun->school_id, 'unidentified_deposit', (int) $deposit->id);
        $targetRun = $this->lockActiveRunForCentralRecord((int) $receivable->school_id, 'receivable', (int) $receivable->id);
        if ((int) $targetRun?->id !== (int) $depositRun->id) {
            throw new AuthorizationException('Unidentified cash cannot be reused across QA Runs.');
        }
        return $targetRun;
    }

    private function assertUnidentifiedPostingTransaction(): void
    {
        if (DB::connection('mysql')->transactionLevel() < 1) {
            throw new RuntimeException('Unidentified cash QA Run locking requires the Central posting transaction.');
        }
    }

    public function assertTenantStudentInActiveRun(int $schoolId, int $studentId): ?CentralFinanceQaRun
    {
        if (!$this->isPermanentQaSchool($schoolId)) {
            return null;
        }
        $this->assertRunSchema();
        $record = CentralFinanceQaRunRecord::on('mysql')->where([
            'subject_scope' => 'tenant:'.$schoolId, 'subject_type' => 'student', 'subject_id' => $studentId,
        ])->first();
        if (!$record) {
            throw new AuthorizationException('This Zixuan Student is historical or unassigned and cannot enter a Finance workflow.');
        }
        $run = CentralFinanceQaRun::on('mysql')->lockForUpdate()->findOrFail($record->qa_run_id);
        if ((int) $run->school_id !== $schoolId || $run->status !== CentralFinanceQaRun::ACTIVE) {
            throw new AuthorizationException('Student Fee Setup requires an Active QA Run.');
        }
        return $run;
    }

    /** Hold the Run row lock for the full tenant write so Complete cannot race it. */
    public function withActiveTenantStudentRun(int $schoolId, int $studentId, callable $callback): mixed
    {
        if (!$this->isPermanentQaSchool($schoolId)) {
            return $callback(null);
        }
        $this->assertRunSchema();

        return DB::connection('mysql')->transaction(function () use ($schoolId, $studentId, $callback): mixed {
            $membership = CentralFinanceQaRunRecord::on('mysql')->where([
                'subject_scope' => 'tenant:'.$schoolId, 'subject_type' => 'student', 'subject_id' => $studentId,
            ])->lockForUpdate()->first();
            if (!$membership) {
                throw new AuthorizationException('Historical Zixuan Students without a QA Run are read-only.');
            }
            $run = CentralFinanceQaRun::on('mysql')->lockForUpdate()->findOrFail($membership->qa_run_id);
            if ((int) $run->school_id !== $schoolId || $run->status !== CentralFinanceQaRun::ACTIVE) {
                throw new AuthorizationException('Finance writes require an Active QA Run.');
            }
            return $callback($run);
        });
    }

    /**
     * Fee snapshots, their classification and membership use one tenant PDO.
     * Locking the Run on a separate Central transaction would deadlock its FK
     * during membership insertion and could leave committed orphan snapshots.
     */
    public function withActiveTenantFeeAssignmentRun(int $schoolId, int $studentId, callable $callback): mixed
    {
        if (!app(CentralFinanceDataIsolationService::class)->isQaTestSchool($schoolId)) {
            return DB::connection('school')->transaction(fn () => $callback(null));
        }
        $central = $this->feeAssignmentCentralDatabase();
        return DB::connection('school')->transaction(function () use ($schoolId, $studentId, $callback, $central): mixed {
            $db = DB::connection('school');
            $school = $db->table($central.'.schools')->where('id', $schoolId)->where('installed', 1)
                ->where('status', 1)->whereNull('deleted_at')->lockForUpdate()->first();
            if (!$school || $school->database_name !== $db->getDatabaseName()
                || DB::getDefaultConnection() !== 'school'
                || config('database.connections.school.database') !== $db->getDatabaseName()) {
                throw new AuthorizationException('QA Fee assignment requires the trusted tenant connection.');
            }
            foreach ([['central', 'school', $schoolId], ['tenant:'.$schoolId, 'student', $studentId]] as [$scope, $type, $id]) {
                if ($db->table($central.'.'.CentralFinanceDataIsolationService::TABLE)->where([
                    'subject_scope' => $scope, 'subject_type' => $type, 'subject_id' => $id, 'school_id' => $schoolId,
                ])->lockForUpdate()->value('classification') !== CentralFinanceDataClassification::QA_TEST) {
                    throw new AuthorizationException('QA Fee Setup requires explicit active QA School and Student classification.');
                }
            }
            if (!$this->isPermanentQaSchool($schoolId)) return $callback(null);
            $this->assertRunSchema();
            $run = $this->tenantFeeStudentRun($central, $schoolId, $studentId);
            return $callback($run);
        });
    }

    /** Creation only: existing history must never be adopted into a fresh Run. */
    public function inheritCreatedTenantFeeRecord(int $schoolId, int $studentId, string $type, \Illuminate\Database\Eloquent\Model $record): void
    {
        if (!$this->isPermanentQaSchool($schoolId)) return;
        $tables = ['student_fee_assignment' => 'student_fee_assignments', 'student_fee_assignment_item' => 'student_fee_assignment_items'];
        $db = DB::connection('school');
        if (!isset($tables[$type]) || $record->getTable() !== $tables[$type] || !$record->wasRecentlyCreated
            || !$record->exists || $record->getConnection()->getPdo() !== $db->getPdo() || $db->transactionLevel() < 1) {
            throw new AuthorizationException('QA Run membership requires a newly created atomic Fee snapshot.');
        }
        $central = $this->feeAssignmentCentralDatabase();
        $run = $this->tenantFeeStudentRun($central, $schoolId, $studentId);
        $assignmentId = $type === 'student_fee_assignment' ? $record->id : $record->student_fee_assignment_id;
        if (!$db->table('student_fee_assignments')->where(['id' => $assignmentId, 'school_id' => $schoolId, 'student_id' => $studentId])->whereNull('deleted_at')->exists()
            || !$db->table($central.'.'.CentralFinanceDataIsolationService::TABLE)->where([
                'subject_scope' => 'tenant:'.$schoolId, 'subject_type' => $type, 'subject_id' => $record->id,
                'school_id' => $schoolId, 'classification' => CentralFinanceDataClassification::QA_TEST,
            ])->exists()) {
            throw new AuthorizationException('QA Fee snapshot ownership or classification is missing.');
        }
        if ($type === 'student_fee_assignment_item') {
            $this->assertTenantFeeRecordInStudentRun($schoolId, $studentId, 'student_fee_assignment', (int) $assignmentId);
        }
        $identity = ['subject_scope' => 'tenant:'.$schoolId, 'subject_type' => $type, 'subject_id' => $record->id];
        $existing = $db->table($central.'.'.self::RECORDS_TABLE)->where($identity)->lockForUpdate()->first();
        if ($existing) {
            if ((int) $existing->qa_run_id !== (int) $run->id || (int) $existing->school_id !== $schoolId) {
                throw new AuthorizationException('QA Run membership cannot be moved between Runs.');
            }
            return;
        }
        $db->table($central.'.'.self::RECORDS_TABLE)->insert($identity + [
            'qa_run_id' => $run->id, 'school_id' => $schoolId, 'created_at' => now(),
        ]);
    }

    public function assertTenantFeeRecordInStudentRun(int $schoolId, int $studentId, string $type, int $id): void
    {
        if (!$this->isPermanentQaSchool($schoolId)) return;
        $central = $this->feeAssignmentCentralDatabase();
        $run = $this->tenantFeeStudentRun($central, $schoolId, $studentId);
        if (!DB::connection('school')->table($central.'.'.self::RECORDS_TABLE)->where([
            'subject_scope' => 'tenant:'.$schoolId, 'subject_type' => $type, 'subject_id' => $id,
            'school_id' => $schoolId, 'qa_run_id' => $run->id,
        ])->exists()) {
            throw new AuthorizationException('QA Fee assignment has missing or different Run membership; historical records remain read-only.');
        }
    }

    private function tenantFeeStudentRun(string $central, int $schoolId, int $studentId): object
    {
        $db = DB::connection('school');
        if ($db->transactionLevel() < 1) throw new RuntimeException('QA Fee Run locking requires an active tenant transaction.');
        $membership = $db->table($central.'.'.self::RECORDS_TABLE)->where([
            'subject_scope' => 'tenant:'.$schoolId, 'subject_type' => 'student', 'subject_id' => $studentId, 'school_id' => $schoolId,
        ])->lockForUpdate()->first();
        $run = $membership ? $db->table($central.'.'.self::TABLE)->where('id', $membership->qa_run_id)->lockForUpdate()->first() : null;
        if (!$run || (int) $run->school_id !== $schoolId || $run->status !== CentralFinanceQaRun::ACTIVE) {
            throw new AuthorizationException('Student Fee Setup requires the Student own Active QA Run; historical Students remain read-only.');
        }
        return $run;
    }

    private function feeAssignmentCentralDatabase(): string
    {
        $db = DB::connection('school');
        $central = DB::connection('mysql');
        $name = (string) $central->getDatabaseName();
        if ($db->getDriverName() !== 'mysql' || !preg_match('/^[A-Za-z0-9_]+$/D', $name)) {
            throw new RuntimeException('Atomic QA Fee Run membership requires co-located MySQL databases.');
        }
        foreach (['driver', 'host', 'port', 'unix_socket'] as $key) {
            if ((string) $db->getConfig($key) !== (string) $central->getConfig($key)) {
                throw new RuntimeException('Atomic QA Fee Run membership requires co-located MySQL databases.');
            }
        }
        return $name;
    }

    /** Propagate an imported tenant Student identity to its central profile. */
    public function inheritTenantStudentProfile(int $schoolId, int $studentId, int $profileId): ?CentralFinanceQaRun
    {
        if (!$this->isPermanentQaSchool($schoolId)) return null;
        $run = $this->inherit($schoolId, 'tenant:'.$schoolId, 'student', $studentId, 'central', 'student_profile', $profileId, true);
        if (!$run || !\Illuminate\Support\Facades\Schema::connection('mysql')->hasTable('central_finance_receivables')) {
            return $run;
        }

        // Student Import V2 can commit the tenant Student and synchronize its
        // Finance projection before Central records the reserved Run identity.
        // Once the reservation is reconciled, adopt only Receivables already
        // tied to this newly linked profile; never sweep historical School QA
        // rows or move a record that already belongs to another Run.
        foreach (DB::connection('mysql')->table('central_finance_receivables')
            ->where('school_id', $schoolId)
            ->where('student_profile_id', $profileId)
            ->orderBy('id')->pluck('id') as $receivableId) {
            $this->inheritCentralDocument($schoolId, 'receivable', (int) $receivableId, true);
        }

        return $run;
    }

    /** Associate an immutable tenant Fee Assignment snapshot with its Student's Run. */
    public function inheritTenantStudentRecord(int $schoolId, int $studentId, string $childType, int $childId): ?CentralFinanceQaRun
    {
        if (!$this->isPermanentQaSchool($schoolId)) return null;
        $parent = CentralFinanceQaRunRecord::on('mysql')->where([
            'subject_scope' => 'tenant:'.$schoolId, 'subject_type' => 'student', 'subject_id' => $studentId,
        ])->first();
        if (!$parent) {
            if ($this->isPermanentQaSchool($schoolId)) {
                throw new AuthorizationException('Historical Zixuan Students cannot create Finance records without a QA Run.');
            }
            return null;
        }
        return DB::connection('mysql')->transaction(function () use ($schoolId, $parent, $childType, $childId): CentralFinanceQaRun {
            $run = CentralFinanceQaRun::on('mysql')->lockForUpdate()->findOrFail($parent->qa_run_id);
            if ((int) $run->school_id !== $schoolId || $run->status !== CentralFinanceQaRun::ACTIVE) {
                throw new AuthorizationException('A closed QA Run cannot accept new Fee Assignments.');
            }
            $this->insertMembership($run, $schoolId, 'tenant:'.$schoolId, $childType, $childId, null, false);
            $isolation = app(CentralFinanceDataIsolationService::class);
            if ($childType === 'student_fee_assignment'
                && $isolation->classification($childType, $childId, 'tenant:'.$schoolId) !== CentralFinanceDataClassification::QA_TEST) {
                $owner = CentralFinanceUser::on('mysql')->findOrFail($run->created_by);
                $isolation->classify($owner, $schoolId, $childType, $childId, CentralFinanceDataClassification::QA_TEST, 'Inherited from QA Run '.$run->run_number.'.');
            }
            return $run;
        });
    }

    /** Infer a central Finance document's parent and inherit its one Run. */
    public function inheritCentralDocument(int $schoolId, string $childType, int $childId, bool $allowClosed = false): ?CentralFinanceQaRun
    {
        $parents = match ($childType) {
            'receivable' => $this->centralIds('central_finance_receivables', $childId, ['student_profile_id'], $schoolId, 'student_profile'),
            'receivable_adjustment', 'promotion_application' => $this->centralIds($childType === 'receivable_adjustment' ? 'central_finance_receivable_adjustments' : 'central_finance_promotion_applications', $childId, ['receivable_id'], $schoolId, 'receivable'),
            'student_discount_request' => $this->centralIds('central_finance_student_discount_requests', $childId, ['student_profile_id'], $schoolId, 'student_profile'),
            'pending_collection' => $this->pendingCollectionParentIds($childId, $schoolId),
            'pending_collection_allocation' => $this->centralIds('central_finance_pending_collection_allocations', $childId, ['receivable_id'], $schoolId, 'receivable'),
            'payment' => $this->paymentParentIds($childId, $schoolId),
            'payment_allocation' => $this->centralIds('central_finance_payment_allocations', $childId, ['receivable_id'], $schoolId, 'receivable'),
            'receipt' => $this->centralIds('central_finance_receipts', $childId, ['payment_id'], $schoolId, 'payment'),
            'payment_refund' => $this->centralIds('central_finance_payment_refunds', $childId, ['payment_id'], $schoolId, 'payment'),
            'payment_reversal' => $this->centralIds('central_finance_payment_reversals', $childId, ['payment_id'], $schoolId, 'payment'),
            'collection_handover' => $this->handoverParentIds($childId, $schoolId),
            'collection_handover_item' => $this->centralIds('central_finance_collection_handover_items', $childId, ['pending_collection_id'], $schoolId, 'pending_collection'),
            'ledger' => $this->ledgerParentIds($childId, $schoolId),
            default => [],
        };
        if ($parents === []) {
            return null;
        }
        $runs = [];
        foreach ($parents as [$parentType, $parentId]) {
            $run = $this->runForRecord('central', $parentType, $parentId);
            if ($run) $runs[$run->id] = $run;
        }
        if ($runs === []) return null;
        if (count($runs) !== 1 || count($parents) !== count(array_filter($parents, fn ($parent) => $this->runForRecord('central', $parent[0], $parent[1]) !== null))) {
            throw new AuthorizationException('A Finance document cannot mix Run members or unassigned historical records.');
        }
        /** @var CentralFinanceQaRun $run */
        $run = reset($runs);
        return $this->inheritCentral($schoolId, $parents[0][0], $parents[0][1], $childType, $childId, $allowClosed);
    }

    public function inheritAuditForDocumentAudit(int $schoolId, string $documentType, int $documentId, int $auditId): void
    {
        $parentType = match ($documentType) {
            'central_payment' => 'payment',
            'pending_collection' => 'pending_collection',
            'central_receivable_adjustment' => 'receivable_adjustment',
            'central_payment_refund' => 'payment_refund',
            'central_payment_reversal' => 'payment_reversal',
            'collection_handover' => 'collection_handover',
            'student_discount_request' => 'student_discount_request',
            default => null,
        };
        if ($parentType === null || !$this->isPermanentQaSchool($schoolId) || !$this->runForRecord('central', $parentType, $documentId)) {
            return;
        }
        $this->inheritCentral($schoolId, $parentType, $documentId, 'audit', $auditId, true);
    }

    public function runForRecord(string $scope, string $type, int $id): ?CentralFinanceQaRun
    {
        if (!\Illuminate\Support\Facades\Schema::connection('mysql')->hasTable(self::RECORDS_TABLE)) {
            return null;
        }
        $record = CentralFinanceQaRunRecord::on('mysql')->where([
            'subject_scope' => $scope, 'subject_type' => $type, 'subject_id' => $id,
        ])->first();
        return $record ? CentralFinanceQaRun::on('mysql')->find($record->qa_run_id) : null;
    }

    private function inherit(int $schoolId, string $parentScope, string $parentType, int $parentId, string $childScope, string $childType, int $childId, bool $allowClosed): ?CentralFinanceQaRun
    {
        if (!\Illuminate\Support\Facades\Schema::connection('mysql')->hasTable(self::RECORDS_TABLE)) return null;
        $parent = CentralFinanceQaRunRecord::on('mysql')->where([
            'subject_scope' => $parentScope, 'subject_type' => $parentType, 'subject_id' => $parentId,
        ])->first();
        if (!$parent) {
            return null;
        }
        return DB::connection('mysql')->transaction(function () use ($schoolId, $parent, $childScope, $childType, $childId, $allowClosed): CentralFinanceQaRun {
            $run = CentralFinanceQaRun::on('mysql')->lockForUpdate()->findOrFail($parent->qa_run_id);
            if ((int) $run->school_id !== $schoolId || (!$allowClosed && $run->status !== CentralFinanceQaRun::ACTIVE)) {
                throw new AuthorizationException('The source QA Run is closed or belongs to another School.');
            }
            $this->insertMembership($run, $schoolId, $childScope, $childType, $childId, null, $allowClosed);
            if ($childScope === 'central' && app(CentralFinanceDataIsolationService::class)->supportsCentralSubject($childType)) {
                $isolation = app(CentralFinanceDataIsolationService::class);
                if ($isolation->classification($childType, $childId) !== CentralFinanceDataClassification::QA_TEST) {
                    $owner = CentralFinanceUser::on('mysql')->findOrFail($run->created_by);
                    $isolation->classify($owner, $schoolId, $childType, $childId, CentralFinanceDataClassification::QA_TEST, 'Inherited from QA Run '.$run->run_number.'.');
                }
            }
            return $run;
        });
    }

    private function insertMembership(CentralFinanceQaRun $run, int $schoolId, string $scope, string $type, ?int $id, ?string $sourceIdentity, bool $allowClosed): CentralFinanceQaRunRecord
    {
        if ($this->tenantType($type) && $scope !== 'tenant:'.$schoolId) {
            throw new AuthorizationException('Tenant QA Run membership must use the trusted School scope.');
        }
        if (!$this->tenantType($type) && $scope !== 'central') {
            throw new AuthorizationException('Central QA Run membership must use central scope.');
        }
        $existing = $id === null ? null : CentralFinanceQaRunRecord::on('mysql')->where('subject_scope', $scope)
            ->where('subject_type', $type)->where('subject_id', $id)->lockForUpdate()->first();
        if ($existing) {
            if ((int) $existing->qa_run_id !== (int) $run->id) {
                throw new AuthorizationException('QA Run membership is immutable and cannot be moved.');
            }
            return $existing;
        }
        if ($sourceIdentity !== null) {
            $reserved = CentralFinanceQaRunRecord::on('mysql')->where([
                'school_id' => $schoolId, 'subject_type' => $type, 'source_identity' => $sourceIdentity,
            ])->lockForUpdate()->first();
            if ($reserved) {
                if ((int) $reserved->qa_run_id !== (int) $run->id) {
                    throw new AuthorizationException('A Student Import Reference has already been reserved by another QA Run.');
                }
                return $reserved;
            }
        }
        if (!$allowClosed && !in_array($run->status, [CentralFinanceQaRun::PREPARING, CentralFinanceQaRun::ACTIVE], true)) {
            throw new AuthorizationException('A completed QA Run cannot accept new membership.');
        }
        return CentralFinanceQaRunRecord::on('mysql')->create([
            'qa_run_id' => $run->id, 'school_id' => $schoolId, 'subject_scope' => $scope,
            'subject_type' => $type, 'subject_id' => $id, 'source_identity' => $sourceIdentity,
        ]);
    }

    private function classifyTenantStudent(CentralFinanceUser $actor, int $schoolId, int $studentId, string $reason): void
    {
        $classification = CentralFinanceDataClassification::on('mysql')->where([
            'subject_scope' => 'tenant:'.$schoolId, 'subject_type' => 'student', 'subject_id' => $studentId,
        ])->lockForUpdate()->first();
        if ($classification?->classification === CentralFinanceDataClassification::QA_TEST) {
            return;
        }
        app(CentralFinanceDataIsolationService::class)->classify(
            $actor, $schoolId, 'student', $studentId, CentralFinanceDataClassification::QA_TEST, $reason,
        );
    }

    private function assertPermanentQaSchool(int $schoolId): School
    {
        $school = $this->qaSchool->resolve();
        if (!$school || (int) $school->id !== $schoolId) {
            throw new AuthorizationException('QA Run operations are limited to the trusted MMBOWEN01 School Registry identity.');
        }
        return $school;
    }

    private function tenantType(string $type): bool
    {
        return in_array($type, self::TENANT_TYPES, true) || in_array($type, ['staff', 'fee', 'fee_type', 'fee_item'], true);
    }

    private function tenantSourceIdentity(int $schoolId, string $identity): string
    {
        return hash('sha256', $schoolId.':'.trim($identity));
    }

    private function assertRunSchema(): void
    {
        if (!\Illuminate\Support\Facades\Schema::connection('mysql')->hasTable(self::TABLE)
            || !\Illuminate\Support\Facades\Schema::connection('mysql')->hasTable(self::RECORDS_TABLE)) {
            throw new AuthorizationException('Zixuan QA Run schema is not installed; Finance writes are disabled.');
        }
    }

    private function audit(CentralFinanceUser $actor, CentralFinanceQaRun $run, string $action, ?array $before, ?array $after): void
    {
        $audit = CentralFinanceDocumentAudit::on('mysql')->create([
            'school_id' => $run->school_id,
            'document_type' => 'central_finance_qa_run', 'document_id' => $run->id,
            'action' => $action, 'actor_id' => $actor->id,
            'reason' => 'QA Run lifecycle transition.',
            'before_values' => $before, 'after_values' => $after,
        ]);
        $this->insertMembership($run, (int) $run->school_id, 'central', 'audit', (int) $audit->id, null, true);
    }

    /** @return list<array{0:string,1:int}> */
    private function centralIds(string $table, int $id, array $columns, int $schoolId, string $parentType): array
    {
        if (!\Illuminate\Support\Facades\Schema::connection('mysql')->hasTable($table)) return [];
        $row = DB::connection('mysql')->table($table)->where('id', $id)->where('school_id', $schoolId)->first($columns);
        $parentId = $row?->{$columns[0]} ?? null;
        return $parentId ? [[$parentType, (int) $parentId]] : [];
    }

    /** @return list<array{0:string,1:int}> */
    private function pendingCollectionParentIds(int $id, int $schoolId): array
    {
        $parents = [];
        if (\Illuminate\Support\Facades\Schema::connection('mysql')->hasTable('central_finance_pending_collection_allocations')) {
            $parents = DB::connection('mysql')->table('central_finance_pending_collection_allocations')->where('pending_collection_id', $id)->where('school_id', $schoolId)->pluck('receivable_id')->map(fn ($parentId) => ['receivable', (int) $parentId])->all();
        }
        if ($parents === []) $parents = $this->centralIds('central_finance_pending_collections', $id, ['receivable_id'], $schoolId, 'receivable');
        return $parents;
    }

    /** @return list<array{0:string,1:int}> */
    private function paymentParentIds(int $id, int $schoolId): array
    {
        $parents = [];
        if (\Illuminate\Support\Facades\Schema::connection('mysql')->hasTable('central_finance_payment_allocations')) {
            $parents = DB::connection('mysql')->table('central_finance_payment_allocations')->where('payment_id', $id)->where('school_id', $schoolId)->pluck('receivable_id')->map(fn ($parentId) => ['receivable', (int) $parentId])->all();
        }
        if ($parents === []) $parents = $this->centralIds('central_finance_payments', $id, ['receivable_id'], $schoolId, 'receivable');
        return $parents;
    }

    /** @return list<array{0:string,1:int}> */
    private function ledgerParentIds(int $id, int $schoolId): array
    {
        if (!\Illuminate\Support\Facades\Schema::connection('mysql')->hasTable('central_finance_ledger_entries')) return [];
        $entry = DB::connection('mysql')->table('central_finance_ledger_entries')->where('id', $id)->where('school_id', $schoolId)->first(['source_type', 'source_id']);
        if (!$entry) return [];
        if ($entry->source_type === 'central_payment') {
            $paymentId = DB::connection('mysql')->table('central_finance_payments')->where('payment_uuid', $entry->source_id)->value('id');
            return $paymentId ? [['payment', (int) $paymentId]] : [];
        }
        if ($entry->source_type === 'central_payment_refund' && \Illuminate\Support\Facades\Schema::connection('mysql')->hasTable('central_finance_payment_refunds')) {
            $refundId = DB::connection('mysql')->table('central_finance_payment_refunds')->where('refund_uuid', $entry->source_id)->value('id');
            return $refundId ? [['payment_refund', (int) $refundId]] : [];
        }
        if ($entry->source_type === 'central_payment_reversal' && \Illuminate\Support\Facades\Schema::connection('mysql')->hasTable('central_finance_payment_reversals')) {
            $reversalId = DB::connection('mysql')->table('central_finance_payment_reversals')->where('reversal_uuid', $entry->source_id)->value('id');
            return $reversalId ? [['payment_reversal', (int) $reversalId]] : [];
        }
        return [];
    }

    /** @return list<array{0:string,1:int}> */
    private function handoverParentIds(int $id, int $schoolId): array
    {
        if (!\Illuminate\Support\Facades\Schema::connection('mysql')->hasTable('central_finance_collection_handover_items')) return [];
        return DB::connection('mysql')->table('central_finance_collection_handover_items as items')
            ->join('central_finance_collection_handover_batches as batches', 'batches.id', '=', 'items.handover_batch_id')
            ->where('items.handover_batch_id', $id)->where('batches.school_id', $schoolId)
            ->distinct()->pluck('items.pending_collection_id')->map(fn ($parentId) => ['pending_collection', (int) $parentId])->all();
    }
}
