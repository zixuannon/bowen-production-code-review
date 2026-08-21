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

    public function submit(CentralFinanceUser $actor, int $schoolId, int $categoryId, float $amount, string $currency, string $idempotencyReference, ?string $referenceNo = null, ?string $description = null): CentralFinanceReimbursementRequest
    {
        $currency = strtoupper(trim($currency));
        $referenceNo = $referenceNo === null ? null : trim($referenceNo);
        if ($amount <= 0 || !is_finite($amount) || !preg_match('/^[A-Z]{3}$/', $currency)
            || !preg_match('/^[A-Za-z0-9_.:-]{2,100}$/', $idempotencyReference)
            || ($referenceNo !== null && $referenceNo !== '' && !preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $referenceNo))) {
            throw new InvalidArgumentException('Central reimbursement input is invalid.');
        }

        return DB::connection('mysql')->transaction(function () use ($actor, $schoolId, $categoryId, $amount, $currency, $idempotencyReference, $referenceNo, $description): CentralFinanceReimbursementRequest {
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
                'currency' => $currency, 'amount' => $amount, 'description' => $description,
                'status' => CentralFinanceReimbursementRequest::PENDING,
                'requested_by' => $actor->id,
            ]);
            $this->audits->record($actor, $request, 'reimbursement', 'submitted', null, null, $this->snapshot($request));

            return $request;
        });
    }

    public function approve(CentralFinanceUser $actor, int $requestId, CentralFinanceFundAccount $account, string $paymentMethod, CarbonImmutable $approvedAt, string $reason): CentralFinanceExpense
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('An approval reason is required.');
        }

        return DB::connection('mysql')->transaction(function () use ($actor, $requestId, $account, $paymentMethod, $approvedAt, $reason): CentralFinanceExpense {
            $request = CentralFinanceReimbursementRequest::on('mysql')->lockForUpdate()->findOrFail($requestId);
            $this->schools->assertCanApproveReimbursements($actor, $request->school_id);
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
        return $request->only(['category_id', 'reference_no', 'currency', 'amount', 'status', 'expense_id', 'approved_by', 'approved_at']);
    }
}
