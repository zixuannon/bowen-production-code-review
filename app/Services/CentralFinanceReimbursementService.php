<?php

namespace App\Services;

use App\Models\CentralFinanceCategory;
use App\Models\CentralFinanceExpense;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceReimbursementRequest;
use App\Models\CentralFinanceUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CentralFinanceReimbursementService
{
    public function __construct(
        private readonly CentralFinanceSchoolScopeService $schools,
        private readonly CentralFinanceOperatingDocumentService $documents,
        private readonly CentralFinanceDocumentAuditService $audits,
    ) {}

    public function submit(CentralFinanceUser $actor, int $schoolId, int $categoryId, float $amount, string $currency, string $idempotencyReference, string $reason, ?string $referenceNo = null, ?string $description = null): CentralFinanceReimbursementRequest
    {
        app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed($schoolId);
        $currency = strtoupper(trim($currency));
        $referenceNo = $referenceNo === null ? null : trim($referenceNo);
        if ($amount <= 0 || !is_finite($amount) || !preg_match('/^[A-Z]{3}$/', $currency)
            || !preg_match('/^[A-Za-z0-9_.:-]{2,100}$/', $idempotencyReference) || trim($reason) === ''
            || ($referenceNo !== null && $referenceNo !== '' && !preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $referenceNo))) {
            throw new InvalidArgumentException('Central reimbursement input is invalid.');
        }

        return DB::connection('mysql')->transaction(function () use ($actor, $schoolId, $categoryId, $amount, $currency, $idempotencyReference, $reason, $referenceNo, $description): CentralFinanceReimbursementRequest {
            $this->schools->assertCanOperate($actor, $schoolId);
            CentralFinanceCategory::on('mysql')->where([
                'id' => $categoryId, 'school_id' => $schoolId,
                'type' => CentralFinanceCategory::EXPENSE, 'is_active' => true,
            ])->firstOrFail();
            $key = hash('sha256', 'reimbursement|'.$schoolId.'|'.$idempotencyReference);
            $existing = CentralFinanceReimbursementRequest::on('mysql')->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }
            if ($referenceNo && CentralFinanceReimbursementRequest::on('mysql')->where([
                'school_id' => $schoolId, 'reference_no' => $referenceNo,
            ])->exists()) {
                throw new InvalidArgumentException('This reimbursement reference is already reserved for this School.');
            }
            $request = CentralFinanceReimbursementRequest::on('mysql')->create([
                'school_id' => $schoolId, 'category_id' => $categoryId,
                'idempotency_key' => $key, 'reference_no' => $referenceNo ?: null,
                'currency' => $currency, 'amount' => $amount, 'description' => $description, 'submission_reason' => trim($reason),
                'status' => CentralFinanceReimbursementRequest::PENDING,
                'requested_by' => $actor->id,
            ]);
            $this->audits->record($actor, $request, 'reimbursement', 'submitted', trim($reason), null, $this->snapshot($request));

            return $request;
        });
    }

    public function reject(CentralFinanceUser $actor, int $requestId, string $reason, CarbonImmutable $occurredAt): CentralFinanceReimbursementRequest
    {
        return $this->resolvePending($actor, $requestId, CentralFinanceReimbursementRequest::REJECTED, $reason, $occurredAt, true);
    }

    public function withdraw(CentralFinanceUser $actor, int $requestId, string $reason, CarbonImmutable $occurredAt): CentralFinanceReimbursementRequest
    {
        return $this->resolvePending($actor, $requestId, CentralFinanceReimbursementRequest::WITHDRAWN, $reason, $occurredAt, false);
    }

    public function cancel(CentralFinanceUser $actor, int $requestId, string $reason, CarbonImmutable $occurredAt): CentralFinanceReimbursementRequest
    {
        return $this->resolvePending($actor, $requestId, CentralFinanceReimbursementRequest::CANCELLED, $reason, $occurredAt, true);
    }

    public function approve(CentralFinanceUser $actor, int $requestId, CentralFinanceFundAccount $account, string $paymentMethod, CarbonImmutable $approvedAt, string $reason): CentralFinanceExpense
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('An approval reason is required.');
        }

        return DB::connection('mysql')->transaction(function () use ($actor, $requestId, $account, $paymentMethod, $approvedAt, $reason): CentralFinanceExpense {
            $request = CentralFinanceReimbursementRequest::on('mysql')->lockForUpdate()->findOrFail($requestId);
            $this->schools->assertCanApproveReimbursements($actor, $request->school_id);
            app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed((int) $request->school_id);
            if ($request->status === CentralFinanceReimbursementRequest::APPROVED) {
                return CentralFinanceExpense::on('mysql')->findOrFail($request->expense_id);
            }
            if ($request->status !== CentralFinanceReimbursementRequest::PENDING) {
                throw new InvalidArgumentException('Only a pending reimbursement can be approved.');
            }
            $before = $this->snapshot($request);
            $expense = $this->documents->createExpense(
                $actor, $request->school_id, $request->category_id, $account,
                (float) $request->amount, $paymentMethod, $approvedAt,
                'reimbursement:'.$request->request_uuid,
                $request->reference_no, $request->description,
            );
            $request->update([
                'status' => CentralFinanceReimbursementRequest::APPROVED,
                'approved_by' => $actor->id, 'approved_at' => $approvedAt,
                'approval_reason' => trim($reason), 'expense_id' => $expense->id,
            ]);
            $this->audits->record($actor, $request, 'reimbursement', 'approved', trim($reason), $before, $this->snapshot($request));

            return $expense;
        });
    }

    /** @return array<string,mixed> */
    private function snapshot(CentralFinanceReimbursementRequest $request): array
    {
        return $request->only(['category_id', 'reference_no', 'currency', 'amount', 'description', 'submission_reason', 'status', 'expense_id', 'approved_by', 'approved_at', 'approval_reason', 'rejected_by', 'rejected_at', 'rejection_reason', 'withdrawn_by', 'withdrawn_at', 'withdrawal_reason', 'cancelled_by', 'cancelled_at', 'cancellation_reason']);
    }

    private function resolvePending(CentralFinanceUser $actor, int $requestId, string $state, string $reason, CarbonImmutable $occurredAt, bool $requiresApproval): CentralFinanceReimbursementRequest
    {
        if (trim($reason) === '') throw new InvalidArgumentException('A workflow reason is required.');
        return DB::connection('mysql')->transaction(function () use ($actor, $requestId, $state, $reason, $occurredAt, $requiresApproval): CentralFinanceReimbursementRequest {
            $request = CentralFinanceReimbursementRequest::on('mysql')->lockForUpdate()->findOrFail($requestId);
            app(CentralFinanceSchoolCutoverService::class)->assertCentralWritesAllowed((int) $request->school_id);
            if ($requiresApproval) $this->schools->assertCanApproveReimbursements($actor, $request->school_id);
            elseif ((int) $request->requested_by !== (int) $actor->id) throw new \Illuminate\Auth\Access\AuthorizationException('Only the requester can withdraw this reimbursement.');
            if ($request->status === $state) return $request;
            if ($request->status !== CentralFinanceReimbursementRequest::PENDING) throw new InvalidArgumentException('Only a pending reimbursement can be updated.');
            $before = $this->snapshot($request);
            $values = ['status' => $state];
            if ($state === CentralFinanceReimbursementRequest::REJECTED) $values += ['rejected_by' => $actor->id, 'rejected_at' => $occurredAt, 'rejection_reason' => trim($reason)];
            if ($state === CentralFinanceReimbursementRequest::WITHDRAWN) $values += ['withdrawn_by' => $actor->id, 'withdrawn_at' => $occurredAt, 'withdrawal_reason' => trim($reason)];
            if ($state === CentralFinanceReimbursementRequest::CANCELLED) $values += ['cancelled_by' => $actor->id, 'cancelled_at' => $occurredAt, 'cancellation_reason' => trim($reason)];
            $request->update($values);
            $this->audits->record($actor, $request, 'reimbursement', $state, trim($reason), $before, $this->snapshot($request));
            return $request;
        });
    }
}
