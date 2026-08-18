<?php

namespace App\Services;

use App\Models\FinanceGroup;
use App\Models\FinanceGroupHqAccount;
use App\Models\FinanceGroupTransfer;
use App\Models\FinanceGroupUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical cross-school funding source.
 *
 * The transaction is central-only: a confirmed record is projected into the
 * HQ and tenant account balances/ledgers. We never attempt a distributed DB
 * transaction or manufacture a tenant BankTransfer with an account from a
 * different tenant database.
 */
class FinanceGroupTransferService
{
    public const PURPOSES = [
        'HQ_FUNDING', 'SCHOOL_REMITTANCE', 'OPERATIONS', 'PROCUREMENT',
        'ACTIVITY', 'EMERGENCY', 'OTHER',
    ];

    public function __construct(private readonly FinanceGroupScopeService $scope)
    {
    }

    /** @param array<string,mixed> $data */
    public function request(FinanceGroupUser $requester, array $data): FinanceGroupTransfer
    {
        $group = FinanceGroup::query()->findOrFail($requester->group_id);
        $schoolId = (int) ($data['school_id'] ?? 0);
        $accountId = (int) ($data['tenant_bank_account_id'] ?? 0);
        $direction = (string) ($data['direction'] ?? '');
        $purpose = strtoupper((string) ($data['purpose'] ?? ''));
        $amount = (float) ($data['amount'] ?? 0);

        $this->assertActiveGroup($group);
        $this->assertDirection($direction);
        $this->assertPurpose($purpose);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => [__('Transfer amount must be greater than zero.')]]);
        }

        $tenantAccount = $this->scope->authorizeActiveTenantAccountForGroupUser($requester, $schoolId, $accountId);
        $hqAccountId = isset($data['hq_account_id']) ? (int) $data['hq_account_id'] : null;
        if ($hqAccountId) {
            $hq = $this->authorizedHqAccount($requester, $group, $hqAccountId);
            $this->assertCurrency($hq, $tenantAccount['currency']);
        }

        return FinanceGroupTransfer::query()->create([
            'group_id' => $group->id,
            'school_id' => $schoolId,
            'hq_account_id' => $hqAccountId ?: null,
            'tenant_bank_account_id' => $tenantAccount['id'],
            'direction' => $direction,
            'purpose' => $purpose,
            'amount' => $amount,
            'transfer_date' => $data['transfer_date'],
            'reference_no' => $this->nullableString($data['reference_no'] ?? null),
            'notes' => $this->nullableString($data['notes'] ?? null),
            'status' => FinanceGroupTransfer::STATUS_PENDING,
            'requested_by_group_user_id' => $requester->id,
            'requested_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $data */
    public function confirm(FinanceGroupUser $confirmer, int $transferId, array $data): FinanceGroupTransfer
    {
        if (!$this->scope->canConfirmGroupTransfers($confirmer)) {
            throw new AuthorizationException('Only Group Head Finance may confirm a Group funding transfer.');
        }

        return DB::connection('mysql')->transaction(function () use ($confirmer, $transferId, $data): FinanceGroupTransfer {
            $transfer = FinanceGroupTransfer::query()->lockForUpdate()->findOrFail($transferId);
            if ($transfer->group_id !== $confirmer->group_id || $transfer->status !== FinanceGroupTransfer::STATUS_PENDING) {
                throw new \DomainException('Only a pending transfer in this Group can be confirmed.');
            }

            $group = FinanceGroup::query()->findOrFail($transfer->group_id);
            $this->assertActiveGroup($group);
            $hqAccountId = (int) ($data['hq_account_id'] ?? $transfer->hq_account_id ?? 0);
            if ($hqAccountId <= 0) {
                throw ValidationException::withMessages(['hq_account_id' => [__('Head Finance must select an active HQ Fund Account before confirmation.')]]);
            }
            $authorizedHq = $this->authorizedHqAccount($confirmer, $group, $hqAccountId);
            $hq = FinanceGroupHqAccount::query()->lockForUpdate()->findOrFail($authorizedHq->id);

            // Assignment/scope can change while a request is pending. Recheck
            // the original requester against the tenant Fund Account now.
            $requester = FinanceGroupUser::query()->findOrFail($transfer->requested_by_group_user_id);
            $tenantAccount = $this->scope->authorizeActiveTenantAccountForGroupUser(
                $requester, (int) $transfer->school_id, (int) $transfer->tenant_bank_account_id,
            );
            $this->assertCurrency($hq, $tenantAccount['currency']);

            if ($transfer->direction === FinanceGroupTransfer::DIRECTION_HQ_TO_SCHOOL
                && !app(FinanceGroupHqAccountBalanceService::class)->hasSufficientBalance($hq, (float) $transfer->amount)) {
                throw ValidationException::withMessages(['amount' => [__('Insufficient balance in the selected HQ Fund Account.')]]);
            }
            if ($transfer->direction === FinanceGroupTransfer::DIRECTION_SCHOOL_TO_HQ
                && !$this->scope->tenantAccountHasSufficientBalanceForGroupUser(
                    $requester, (int) $transfer->school_id, (int) $transfer->tenant_bank_account_id, (float) $transfer->amount,
                )) {
                throw ValidationException::withMessages(['amount' => [__('Insufficient balance in the selected School Fund Account.')]]);
            }

            $transfer->update([
                'hq_account_id' => $hq->id,
                'status' => FinanceGroupTransfer::STATUS_CONFIRMED,
                'confirmed_by_group_user_id' => $confirmer->id,
                'confirmed_at' => now(),
            ]);

            return $transfer->fresh(['hqAccount', 'school']);
        });
    }

    public function reject(FinanceGroupUser $confirmer, int $transferId, string $reason): FinanceGroupTransfer
    {
        if (!$this->scope->canConfirmGroupTransfers($confirmer)) {
            throw new AuthorizationException('Only Group Head Finance may reject a Group funding transfer.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => [__('A rejection reason is required.')]]);
        }
        return $this->finishWithoutMovement($confirmer, $transferId, FinanceGroupTransfer::STATUS_REJECTED, 'rejected', $reason);
    }

    public function cancel(FinanceGroupUser $requester, int $transferId, string $reason): FinanceGroupTransfer
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => [__('A cancellation reason is required.')]]);
        }
        return DB::connection('mysql')->transaction(function () use ($requester, $transferId, $reason): FinanceGroupTransfer {
            $transfer = FinanceGroupTransfer::query()->lockForUpdate()->findOrFail($transferId);
            if ($transfer->group_id !== $requester->group_id || $transfer->requested_by_group_user_id !== $requester->id || $transfer->status !== FinanceGroupTransfer::STATUS_PENDING) {
                throw new AuthorizationException('Only the requester may cancel a pending Group funding transfer.');
            }
            $transfer->update([
                'status' => FinanceGroupTransfer::STATUS_CANCELLED,
                'cancelled_by_group_user_id' => $requester->id,
                'cancelled_at' => now(),
                'cancellation_reason' => trim($reason),
            ]);
            return $transfer->fresh();
        });
    }

    private function finishWithoutMovement(FinanceGroupUser $confirmer, int $transferId, string $status, string $prefix, string $reason): FinanceGroupTransfer
    {
        return DB::connection('mysql')->transaction(function () use ($confirmer, $transferId, $status, $prefix, $reason): FinanceGroupTransfer {
            $transfer = FinanceGroupTransfer::query()->lockForUpdate()->findOrFail($transferId);
            if ($transfer->group_id !== $confirmer->group_id || $transfer->status !== FinanceGroupTransfer::STATUS_PENDING) {
                throw new \DomainException('Only a pending transfer in this Group can be changed.');
            }
            $transfer->update([
                'status' => $status,
                "{$prefix}_by_group_user_id" => $confirmer->id,
                "{$prefix}_at" => now(),
                "{$prefix}_reason" => trim($reason),
            ]);
            return $transfer->fresh();
        });
    }

    private function authorizedHqAccount(FinanceGroupUser $user, FinanceGroup $group, int $accountId): FinanceGroupHqAccount
    {
        $account = FinanceGroupHqAccount::query()->active()->where('group_id', $group->id)->findOrFail($accountId);
        // An ordinary HQ Accountant may operate only an explicitly assigned
        // HQ Fund Account. Broad control remains a Head Finance-only power.
        if (!$this->scope->canControlHqAccounts($user) && !$account->authorizedGroupUsers()->whereKey($user->id)->exists()) {
            throw new AuthorizationException('This Group user is not authorized for the requested HQ Fund Account.');
        }
        return $account;
    }

    private function assertActiveGroup(FinanceGroup $group): void
    {
        if ($group->status !== 'active') {
            throw new AuthorizationException('Group Finance is not active.');
        }
    }

    private function assertDirection(string $direction): void
    {
        if (!in_array($direction, [FinanceGroupTransfer::DIRECTION_HQ_TO_SCHOOL, FinanceGroupTransfer::DIRECTION_SCHOOL_TO_HQ], true)) {
            throw ValidationException::withMessages(['direction' => [__('Unsupported Group transfer direction.')]]);
        }
    }

    private function assertPurpose(string $purpose): void
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw ValidationException::withMessages(['purpose' => [__('Unsupported Group transfer purpose.')]]);
        }
    }

    /** @param array{id:int,school_id:int,account_name:string,currency:string} $tenantAccount */
    private function assertCurrency(FinanceGroupHqAccount $hq, string $tenantCurrency): void
    {
        if ($hq->currency !== $tenantCurrency) {
            throw ValidationException::withMessages(['hq_account_id' => [__('HQ and School Fund Accounts must use the same currency.')]]);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
