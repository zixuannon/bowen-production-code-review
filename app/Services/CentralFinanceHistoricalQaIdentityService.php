<?php

namespace App\Services;

use App\Models\CentralFinanceDocumentAudit;
use App\Models\CentralFinanceUser;
use App\Models\School;
use App\Support\CentralFinanceDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Explicit maintenance seam, never an HTTP fallback for missing bank evidence. */
final class CentralFinanceHistoricalQaIdentityService
{
    public const ACTION = 'historical_qa_identity_reconciled';
    public const CONFIRMATION = 'historical_qa_simulated_collection';
    public const PRE_RUN_CUTOFF = '2026-10-05 00:00:00';
    private const REASON = 'Operator-confirmed historical QA simulation; migration compatibility for historical QA test transaction; no bank evidence claimed; no financial amount or physical cash movement changed.';
    private const IDS = ['payment_id', 'pending_id', 'allocation_id', 'receipt_id', 'ledger_id', 'receivable_id', 'student_profile_id', 'school_id', 'fund_account_id'];

    /** Read-only exact bounded scope. The evidence hash is required for execution. */
    public function preview(array $scope, bool $locked = false): array
    {
        $scope = $this->scope($scope);
        $db = DB::connection('mysql');
        $this->require(!$locked || $db->transactionLevel() > 0, 'Locked historical evidence requires an existing transaction.');
        $query = fn (string $table) => $locked ? $db->table($table)->lockForUpdate() : $db->table($table);
        $rows = [];
        foreach (['payment' => 'payments', 'pending' => 'pending_collections', 'allocation' => 'payment_allocations', 'receipt' => 'receipts', 'ledger' => 'ledger_entries', 'receivable' => 'receivables', 'student_profile' => 'student_profiles', 'fund_account' => 'fund_accounts'] as $key => $table) {
            $row = $query('central_finance_'.$table)->where('id', $scope[$key.'_id'])->first();
            $this->require($row !== null, 'The approved historical chain no longer exists.');
            $rows[$key] = $row;
        }
        ['payment' => $p, 'pending' => $pending, 'allocation' => $allocation, 'receipt' => $receipt, 'ledger' => $ledger, 'receivable' => $receivable, 'student_profile' => $profile, 'fund_account' => $account] = $rows;
        $school = app(CentralFinanceQaSchoolIdentity::class)->resolve();
        $this->require($school !== null && (int) $school->id === $scope['school_id'], 'Historical identity requires the trusted permanent QA School registry.');
        $currentSchool = $query('schools')->where('id', $scope['school_id'])->first();
        $this->require($currentSchool !== null && $currentSchool->code === $school->code && $currentSchool->database_name === $school->database_name
            && $currentSchool->deleted_at === null && (bool) $currentSchool->installed && in_array((string) $currentSchool->status, ['active', '1'], true), 'Current locked School registry differs.');
        $this->require(($p->request_hash ?? null) === null && ($p->unidentified_deposit_id ?? null) === null, 'Historical source was subsequently relinked or rewritten.');
        $this->require($p->payment_reference === null && $pending->payment_reference === null, 'Historical QA identity requires original references to remain exactly NULL.');
        $this->require(trim((string) ($p->note ?? '')) === '' && trim((string) ($pending->note ?? '')) === '', 'Unreviewed source notes require separate bank evidence investigation.');
        $this->require($p->payment_method === 'Bank Transfer' && $pending->payment_method === $p->payment_method && $account->account_type === 'bank', 'Only the approved historical bank-method simulation is eligible.');
        $this->require($account->deleted_at === null && (bool) $account->is_active && $account->status === 'active', 'Historical account must remain active.');
        $this->require((int) $p->fund_account_id === $scope['fund_account_id'] && (int) $pending->intended_fund_account_id === $scope['fund_account_id'] && (int) $ledger->fund_account_id === $scope['fund_account_id'], 'Historical Fund Account relationship changed.');
        foreach ([$p, $pending, $allocation, $receipt, $ledger, $receivable, $profile] as $row) $this->require((int) $row->school_id === $scope['school_id'], 'Cross-school historical chain denied.');
        foreach ([$p, $pending, $allocation, $account, $ledger, $receivable] as $row) $this->require($row->currency === $scope['currency'], 'Historical currency relationship changed.');
        foreach ([$p, $pending, $allocation] as $row) $this->require(CentralFinanceDecimal::compare((string) $row->amount, $scope['amount']) === 0, 'Historical amount changed.');
        $this->require($pending->status === 'confirmed' && (int) $pending->confirmed_payment_id === $p->id && (int) $pending->confirmed_by === (int) $p->received_by, 'Pending confirmation relationship is unproven.');
        $this->require((int) $allocation->payment_id === $p->id && (int) $receipt->payment_id === $p->id, 'Payment allocation or receipt relationship changed.');
        foreach ([$p, $pending, $allocation] as $row) $this->require((int) $row->receivable_id === $scope['receivable_id'], 'Only the approved single-receivable historical chain is supported.');
        foreach ([$pending, $allocation, $receivable] as $row) $this->require((int) $row->student_profile_id === $scope['student_profile_id'], 'Historical student relationship changed.');
        $this->require($query('central_finance_payment_allocations')->where('payment_id', $p->id)->get()->count() === 1
            && $query('central_finance_receipts')->where('payment_id', $p->id)->get()->count() === 1
            && $query('central_finance_pending_collections')->where('confirmed_payment_id', $p->id)->get()->count() === 1, 'Duplicate or ambiguous canonical source chain.');
        $key = hash('sha256', $scope['school_id'].'|'.$scope['receivable_id'].'|pending-collection:'.$pending->pending_collection_uuid);
        $this->require(hash_equals($key, (string) $p->idempotency_key), 'Pending-derived payment idempotency evidence does not match.');
        $this->require($query('central_finance_payments')->where('idempotency_key', $key)->get()->count() === 1
            && $query('central_finance_payments')->where('payment_uuid', $p->payment_uuid)->get()->count() === 1, 'Duplicate canonical Payment origin.');
        foreach ([$p->created_at, $p->paid_at, $pending->created_at, $pending->collected_at, $pending->confirmed_at, $receipt->created_at, $ledger->created_at] as $date) {
            $this->require(is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $date) && $date < self::PRE_RUN_CUTOFF, 'Historical source does not predate QA Run.');
        }
        $this->require($p->paid_at === $pending->collected_at && $ledger->occurred_at === $p->paid_at, 'Historical collection dates do not match.');
        $this->require($ledger->source_type === 'central_payment' && $ledger->source_id === $p->payment_uuid && $ledger->source_line === 'primary'
            && $ledger->transaction_type === 'operating_income' && $ledger->reference_no === $receipt->receipt_no
            && CentralFinanceDecimal::compare((string) $ledger->money_in, $scope['amount']) === 0
            && CentralFinanceDecimal::compare((string) $ledger->money_out, '0') === 0
            && CentralFinanceDecimal::compare((string) $ledger->operating_income, $scope['amount']) === 0, 'Canonical original money-in evidence is inconsistent.');
        $this->require($query('central_finance_ledger_entries')->where('source_id', $p->payment_uuid)->get()->count() === 1
            && $query('central_finance_ledger_entries')->where('reference_no', $receipt->receipt_no)->get()->count() === 1, 'Duplicate physical money-in or correction evidence found.');
        foreach (['central_finance_payment_refunds', 'central_finance_payment_reversals'] as $table) $this->require($query($table)->where('payment_id', $p->id)->get()->isEmpty(), 'Historical payment has corrections.');
        $classificationEvidence = [];
        foreach (['school' => $scope['school_id'], 'fund_account' => $account->id, 'student_profile' => $profile->id, 'receivable' => $receivable->id, 'pending_collection' => $pending->id, 'payment' => $p->id, 'receipt' => $receipt->id, 'ledger' => $ledger->id] as $type => $id) {
            $classificationEvidence[$type] = $this->classification($type, (int) $id, $scope['school_id'], $locked);
            $this->noRun($type, (int) $id, $locked);
        }
        // V2 backfilled historical allocation rows: derive QA from both proven
        // parents, rather than inventing a classification for the child.
        $this->noRun('payment_allocation', (int) $allocation->id, $locked);
        if ($query(CentralFinanceDataIsolationService::TABLE)->where(['subject_scope' => 'central', 'subject_type' => 'payment_allocation', 'subject_id' => $allocation->id])->get()->isNotEmpty()) $classificationEvidence['payment_allocation'] = $this->classification('payment_allocation', (int) $allocation->id, $scope['school_id'], $locked);
        $sourceAudits = [];
        foreach ([['pending_collection', $pending->id, 'submitted'], ['pending_collection', $pending->id, 'confirmed'], ['central_payment', $p->id, 'collected']] as [$type, $id, $action]) {
            $audits = $query('central_finance_document_audits')->where(['school_id' => $scope['school_id'], 'document_type' => $type, 'document_id' => $id, 'action' => $action])->get();
            $this->require($audits->count() === 1, 'Historical source audit chain is ambiguous or missing.');
            $audit = $audits->first();
            $this->require($audit->created_at < self::PRE_RUN_CUTOFF, 'Historical source audit postdates QA Run.');
            $after = json_decode((string) $audit->after_values, true, 512, JSON_THROW_ON_ERROR);
            $this->require(is_array($after), 'Historical source audit has no immutable source snapshot.');
            if ($action === 'submitted') {
                $this->require(($after['status'] ?? null) === 'submitted' && array_key_exists('payment_reference', $after) && $after['payment_reference'] === null
                    && (int) ($after['student_profile_id'] ?? 0) === $profile->id && (int) ($after['receivable_id'] ?? 0) === $receivable->id
                    && (int) ($after['intended_fund_account_id'] ?? 0) === $account->id && ($after['currency'] ?? null) === $scope['currency']
                    && ($after['payment_method'] ?? null) === 'Bank Transfer' && (int) ($after['submitted_by'] ?? 0) === (int) $audit->actor_id
                    && isset($after['amount']) && CentralFinanceDecimal::compare((string) $after['amount'], $scope['amount']) === 0, 'Submitted source snapshot differs from the approved chain.');
            } elseif ($action === 'confirmed') {
                $this->require(($after['status'] ?? null) === 'confirmed' && (int) ($after['confirmed_payment_id'] ?? 0) === $p->id
                    && (int) ($after['confirmed_by'] ?? 0) === (int) $p->received_by && (int) $audit->actor_id === (int) $p->received_by, 'Confirmed source snapshot differs from the approved chain.');
            } else {
                $afterReceivables = isset($after['receivable_ids']) ? $after['receivable_ids'] : [$after['receivable_id'] ?? null];
                $afterAmount = $after['allocation_total'] ?? $after['amount'] ?? null;
                $this->require((int) ($after['receipt_id'] ?? 0) === $receipt->id && (int) ($after['fund_account_id'] ?? 0) === $account->id
                    && $afterReceivables === [$scope['receivable_id']] && $afterAmount !== null
                    && CentralFinanceDecimal::compare((string) $afterAmount, $scope['amount']) === 0
                    && (int) $audit->actor_id === (int) $p->received_by, 'Collected source snapshot differs from the approved chain.');
            }
            $this->noRun('audit', (int) $audit->id, $locked);
            $sourceAudits[] = (array) $audit;
        }
        // Additive P0 columns cannot invalidate pre-migration evidence.
        $snapshot = [];
        foreach ($rows as $name => $row) {
            $snapshot[$name] = (array) $row;
            foreach (['request_hash', 'unidentified_deposit_id'] as $additive) unset($snapshot[$name][$additive]);
            ksort($snapshot[$name]);
        }
        $snapshot['classifications'] = $classificationEvidence;
        $snapshot['source_audits'] = $sourceAudits;
        $snapshot['school_registry'] = ['id' => $school->id, 'code' => $school->code, 'database_name' => $school->database_name];
        $value = 'HQA1:'.hash('sha256', json_encode(['historical_qa_v1', $school->id, $school->code, $p->id, $p->payment_uuid, $pending->id, $pending->pending_collection_uuid, $key], JSON_THROW_ON_ERROR));
        return ['scope' => $scope, 'evidence_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'identity' => [
            'identity_namespace' => 'historical_qa', 'normalized_identity' => $value,
            'identity_hash' => hash('sha256', "historical_qa\0".$value), 'manual_reason' => self::REASON,
        ], 'group_id' => (int) $account->group_id];
    }

    /** Call before financial freeze in a SERIALIZABLE maintenance transaction. */
    public function lockedPreview(array $scope): array
    {
        return $this->preview($scope, true);
    }

    /** Writes ONE append-only metadata row. No financial writer or QA Run hook. */
    public function reconcile(CentralFinanceUser $actor, array $scope, string $expectedEvidenceHash): CentralFinanceDocumentAudit
    {
        $scope = $this->scope($scope);
        $connection = DB::connection('mysql');
        if ($connection->getDriverName() === 'mysql') {
            $variable = str_contains((string) $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION), 'MariaDB') ? '@@tx_isolation' : '@@transaction_isolation';
            $isolation = $connection->selectOne('SELECT '.$variable.' AS isolation_level');
            $this->require(strtoupper((string) $isolation->isolation_level) === 'SERIALIZABLE', 'Historical reconciliation requires a SERIALIZABLE maintenance transaction.');
        }
        return DB::connection('mysql')->transaction(function () use ($actor, $scope, $expectedEvidenceHash): CentralFinanceDocumentAudit {
            $evidence = $this->lockedPreview($scope);
            $this->authorize($actor, $evidence, true);
            $this->require(hash_equals($evidence['evidence_hash'], $expectedEvidenceHash), 'Historical evidence changed since approval.');
            $existing = $this->auditRows($scope['payment_id']);
            if ($existing->isNotEmpty()) {
                $this->verifiedIdentityForPayment($scope['payment_id']);
                $audit = $existing->sole();
                $this->require((int) $audit->actor_id === (int) $actor->id && $this->same($audit->after_values, $evidence), 'Historical reconciliation retry content differs.');
                return $audit;
            }
            return CentralFinanceDocumentAudit::on('mysql')->create([
                'school_id' => $scope['school_id'], 'group_id' => $evidence['group_id'], 'document_type' => 'payment',
                'document_id' => $scope['payment_id'], 'action' => self::ACTION, 'actor_id' => $actor->id, 'reason' => self::REASON,
                'before_values' => ['payment_reference' => null, 'historical_qa_identity' => null, 'qa_run_id' => null], 'after_values' => $evidence,
            ]);
        });
    }

    /** Migration/preflight is read-only. No dedicated audit means NO exception. */
    public function verifiedIdentityForPayment(int $paymentId): ?array
    {
        if (!Schema::connection('mysql')->hasTable('central_finance_document_audits')) return null;
        $audits = $this->auditRows($paymentId);
        if ($audits->isEmpty()) return null;
        $this->require($audits->count() === 1, 'Duplicate historical identity audits require investigation.');
        $audit = $audits->first();
        $stored = $audit->after_values;
        $this->require(is_array($stored) && is_array($stored['scope'] ?? null) && ($stored['scope']['payment_id'] ?? null) === $paymentId, 'Foreign or malformed historical identity audit.');
        $current = $this->preview($stored['scope']);
        $this->require($this->same($stored, $current) && $audit->reason === self::REASON && (int) $audit->school_id === $current['scope']['school_id']
            && (int) $audit->group_id === $current['group_id']
            && $this->same($audit->before_values, ['payment_reference' => null, 'historical_qa_identity' => null, 'qa_run_id' => null]), 'Historical reconciliation evidence has changed or is corrupt.');
        $actor = CentralFinanceUser::on('mysql')->find($audit->actor_id);
        $this->require($actor !== null, 'Historical reconciliation actor is unavailable.');
        $this->authorize($actor, $current, false);
        return $current['identity'];
    }

    private function authorize(CentralFinanceUser $actor, array $evidence, bool $locked): void
    {
        $query = DB::connection('mysql')->table('users')->where('id', $actor->id);
        $current = ($locked ? $query->lockForUpdate() : $query)->first();
        $this->require($current !== null && $current->school_id === null && $current->deleted_at === null
            && (string) $current->status === '1', 'A real active Central Head Finance actor is required.');
        $authorization = app(CentralFinanceConfigurationAuthorizationService::class);
        $authorization->assertHeadFinanceCanConfigureSchool($actor, School::on('mysql')->findOrFail($evidence['scope']['school_id']));
        $authorization->assertHeadFinanceCanConfigureGroup($actor, $evidence['group_id']);
    }

    private function auditRows(int $paymentId): \Illuminate\Database\Eloquent\Collection
    {
        return CentralFinanceDocumentAudit::on('mysql')->where(['document_type' => 'payment', 'document_id' => $paymentId, 'action' => self::ACTION])->orderBy('id')->get();
    }

    private function classification(string $type, int $id, int $schoolId, bool $locked): array
    {
        $db = DB::connection('mysql');
        $records = $db->table(CentralFinanceDataIsolationService::TABLE)->where(['subject_scope' => 'central', 'subject_type' => $type, 'subject_id' => $id]);
        $record = ($locked ? $records->lockForUpdate() : $records)->first();
        $this->require($record !== null && $record->classification === 'qa_test'
            && ($type === 'fund_account' && $record->school_id === null || (int) $record->school_id === $schoolId), 'Explicit audited QA classification is required for every source.');
        $audits = $db->table(CentralFinanceDataIsolationService::AUDIT_TABLE)->where('classification_id', $record->id)->orderByDesc('id');
        $audit = ($locked ? $audits->lockForUpdate() : $audits)->first();
        $this->require($audit !== null && $audit->after_classification === 'qa_test' && $audit->subject_scope === 'central'
            && $audit->subject_type === $type && (int) $audit->subject_id === $id && $audit->school_id === $record->school_id
            && (int) $audit->actor_id === (int) $record->classified_by && $audit->reason === $record->reason, 'QA classification audit evidence is inconsistent.');
        return ['record' => (array) $record, 'audit' => (array) $audit];
    }

    private function noRun(string $type, int $id, bool $locked): void
    {
        $query = DB::connection('mysql')->table('central_finance_qa_run_records')->where(['subject_scope' => 'central', 'subject_type' => $type, 'subject_id' => $id]);
        $this->require(($locked ? $query->lockForUpdate() : $query)->get()->isEmpty(), 'QA Run records cannot become historical unassigned identities.');
    }

    private function scope(array $scope): array
    {
        $keys = array_merge(self::IDS, ['amount', 'currency', 'operator_confirmation']);
        $this->require(count($scope) === count($keys) && array_diff($keys, array_keys($scope)) === [], 'An exact bounded operator scope is required.');
        $normalized = [];
        foreach (self::IDS as $key) {
            $this->require(is_int($scope[$key]) && $scope[$key] > 0, 'Historical scope IDs must be explicit positive integers.');
            $normalized[$key] = $scope[$key];
        }
        $this->require(is_string($scope['amount']) && is_string($scope['currency']) && preg_match('/^[A-Z]{3}$/D', $scope['currency']) && $scope['operator_confirmation'] === self::CONFIRMATION, 'Explicit operator confirmation of simulated QA collection is required.');
        $normalized['amount'] = CentralFinanceDecimal::normalize($scope['amount']);
        $this->require(CentralFinanceDecimal::compare($normalized['amount'], '0') > 0, 'Historical amount must be positive.');
        return $normalized + ['currency' => $scope['currency'], 'operator_confirmation' => self::CONFIRMATION];
    }

    private function require(bool $condition, string $message): void
    {
        if (!$condition) throw new RuntimeException($message);
    }

    /** MySQL JSON object key order is not evidence; scalar types still are. */
    private function same(mixed $left, mixed $right): bool
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value);
            return array_map($canonical, $value);
        };
        return $canonical($left) === $canonical($right);
    }
}
