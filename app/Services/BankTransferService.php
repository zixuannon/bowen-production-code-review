<?php

namespace App\Services;

use App\Models\BankTransfer;
use App\Models\FundHandover;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Executes an immediate internal Fund Account transfer.
 *
 * FundHandover confirmation remains the only way a pending custody handover
 * becomes a completed transfer. This service never creates income or expense
 * records: BankTransfer is the sole accounting source for an internal move.
 */
class BankTransferService
{
    public function __construct(
        private readonly FinanceAccountAccessService $accounts,
        private readonly FundAccountBalanceService $balances,
    ) {
    }

    public function create(User $actor, array $data): BankTransfer
    {
        $fromAccountId = (int) $data['from_account_id'];
        $toAccountId = (int) $data['to_account_id'];

        if ($fromAccountId === $toAccountId) {
            throw ValidationException::withMessages([
                'to_account_id' => [__('The destination Fund Account must be different from the source Fund Account.')],
            ]);
        }

        // Both account lookups enforce current-school ownership and the
        // Cashier pivot boundary before any database transaction begins.
        $fromAccount = $this->accounts->authorizeActive($actor, $fromAccountId);
        $toAccount = $this->accounts->authorizeActive($actor, $toAccountId);

        if ($fromAccount->currency !== $toAccount->currency) {
            throw ValidationException::withMessages([
                'to_account_id' => [__('Cannot transfer between accounts with different currencies.')],
            ]);
        }

        return DB::transaction(function () use ($actor, $data, $fromAccount, $toAccount) {
            $amount = (float) $data['amount'];
            if (!$this->balances->hasSufficientBalance($fromAccount, $amount)) {
                throw ValidationException::withMessages([
                    'amount' => [__('Insufficient balance in source account. Current balance: :balance', [
                        'balance' => number_format($this->balances->currentBalance($fromAccount), 2),
                    ])],
                ]);
            }

            return BankTransfer::create([
                'school_id' => $actor->school_id,
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'amount' => $amount,
                'transfer_date' => $data['transfer_date'],
                'reference_no' => $data['reference_no'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'completed',
                'created_by' => $actor->id,
            ]);
        });
    }

    public function cancel(User $actor, BankTransfer $transfer): void
    {
        if ($transfer->status !== 'completed') {
            throw ValidationException::withMessages([
                'transfer' => [__('Only completed transfers can be cancelled.')],
            ]);
        }

        if (FundHandover::where('bank_transfer_id', $transfer->id)
            ->where('status', FundHandover::STATUS_CONFIRMED)
            ->exists()) {
            throw new HttpException(422, 'A confirmed fund handover is immutable and cannot be cancelled as an immediate transfer.');
        }

        if (!$this->accounts->canAccessAccount($actor, $transfer->from_account)
            || !$this->accounts->canAccessAccount($actor, $transfer->to_account)) {
            throw new AccessDeniedHttpException('You are not authorized to cancel this bank transfer.');
        }

        DB::transaction(function () use ($transfer) {
            $transfer->update(['status' => 'cancelled']);
            $transfer->delete();
        });
    }
}
