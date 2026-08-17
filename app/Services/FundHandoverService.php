<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\FundHandover;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FundHandoverService
{
    public function __construct(
        private readonly FinanceAccountAccessService $access,
        private readonly FundAccountBalanceService $balances,
    ) {}

    public function recipientCandidates(User $actor): Builder
    {
        $role = $this->handoverRole($actor);
        $required = $role === 'head' ? 'Cashier' : 'Head Finance';

        return User::query()->where('school_id', $actor->school_id)->role($required);
    }

    /**
     * School Admin is an oversight role: it may inspect the school handover
     * register but it must never participate in custody operations.
     */
    public function canViewRegister(User $user): bool
    {
        return app(FinanceAuthorizationService::class)->can($user, 'finance-handover-view');
    }

    public function isParticipant(User $user): bool
    {
        return $user->hasAnyRole(['Head Finance', 'Cashier']);
    }

    public function assertCanViewRegister(User $user): void
    {
        if (!$this->canViewRegister($user)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('You are not authorized to view fund handovers.');
        }
    }

    public function assertParticipant(User $user): void
    {
        if (!$this->isParticipant($user)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Fund handovers require Head Finance or Accountant role.');
        }
    }

    public function assertCanCreate(User $user): void
    {
        app(FinanceAuthorizationService::class)->assert($user, 'finance-handover-create');
        $this->assertParticipant($user);
    }

    public function assertCanConfirm(User $user): void
    {
        app(FinanceAuthorizationService::class)->assert($user, 'finance-handover-confirm');
        $this->assertParticipant($user);
    }

    public function assertCanReject(User $user): void
    {
        app(FinanceAuthorizationService::class)->assert($user, 'finance-handover-reject');
        $this->assertParticipant($user);
    }

    public function assertCanCancel(User $user): void
    {
        app(FinanceAuthorizationService::class)->assert($user, 'finance-handover-cancel');
        $this->assertParticipant($user);
    }

    public function eligibleDestinationAccounts(User $sender, User $receiver): Builder
    {
        $this->assertValidParties($sender, $receiver);
        return $this->access->accessibleAccounts($receiver)->active();
    }

    public function create(User $sender, array $data): FundHandover
    {
        $receiver = User::whereKey($data['receiver_id'])->where('school_id', $sender->school_id)->firstOrFail();
        $this->assertValidParties($sender, $receiver);
        $from = $this->activeAccessibleAccount($sender, (int) $data['from_account_id']);
        $to = $this->activeDestinationAccount($sender, $receiver, (int) $data['to_account_id']);
        $this->assertCompatibleAccounts($from, $to);
        $this->assertSufficientBalance($from, (float) $data['amount']);

        return FundHandover::create([
            'school_id' => $sender->school_id,
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'amount' => $data['amount'],
            'handover_date' => $data['handover_date'],
            'reference_no' => $data['reference_no'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => FundHandover::STATUS_PENDING,
        ]);
    }

    public function confirm(User $actor, int $handoverId): FundHandover
    {
        return DB::transaction(function () use ($actor, $handoverId) {
            $handover = FundHandover::where('school_id', $actor->school_id)->lockForUpdate()->findOrFail($handoverId);
            if ($handover->status !== FundHandover::STATUS_PENDING) {
                throw new \DomainException('Only a pending handover can be confirmed.');
            }
            if ($handover->receiver_id !== $actor->id) {
                throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Only the designated receiver can confirm this handover.');
            }

            $sender = User::whereKey($handover->sender_id)->where('school_id', $actor->school_id)->firstOrFail();
            $this->assertValidParties($sender, $actor);
            $from = $this->activeAccessibleAccount($sender, $handover->from_account_id, true);
            $to = $this->activeDestinationAccount($sender, $actor, $handover->to_account_id, true);
            $this->assertCompatibleAccounts($from, $to);
            $this->assertSufficientBalance($from, (float) $handover->amount);

            $transfer = BankTransfer::create([
                'school_id' => $handover->school_id,
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => $handover->amount,
                'transfer_date' => $handover->handover_date,
                'reference_no' => $handover->reference_no,
                'notes' => trim(($handover->notes ? $handover->notes . ' | ' : '') . 'Confirmed fund handover #' . $handover->id),
                'status' => 'completed',
                'created_by' => $handover->sender_id,
            ]);

            $handover->update([
                'status' => FundHandover::STATUS_CONFIRMED,
                'bank_transfer_id' => $transfer->id,
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
            ]);

            return $handover->fresh(['bank_transfer']);
        });
    }

    public function reject(User $actor, int $handoverId, string $reason): FundHandover
    {
        return $this->finishWithoutTransfer($actor, $handoverId, 'receiver_id', FundHandover::STATUS_REJECTED, 'rejected', $reason);
    }

    public function cancel(User $actor, int $handoverId, string $reason): FundHandover
    {
        return $this->finishWithoutTransfer($actor, $handoverId, 'sender_id', FundHandover::STATUS_CANCELLED, 'cancelled', $reason);
    }

    private function finishWithoutTransfer(User $actor, int $id, string $actorColumn, string $status, string $prefix, string $reason): FundHandover
    {
        return DB::transaction(function () use ($actor, $id, $actorColumn, $status, $prefix, $reason) {
            $handover = FundHandover::where('school_id', $actor->school_id)->lockForUpdate()->findOrFail($id);
            if ($handover->status !== FundHandover::STATUS_PENDING) {
                throw new \DomainException('Only a pending handover can be changed.');
            }
            if ($handover->{$actorColumn} !== $actor->id) {
                throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('You are not authorized to change this handover.');
            }
            $reasonColumn = $prefix === 'rejected' ? 'rejection_reason' : 'cancellation_reason';
            $handover->update([
                'status' => $status,
                "{$prefix}_by" => $actor->id,
                "{$prefix}_at" => now(),
                $reasonColumn => $reason,
            ]);
            return $handover->fresh();
        });
    }

    private function handoverRole(User $user): string
    {
        if ($user->hasRole('Head Finance')) return 'head';
        if ($user->hasRole('Cashier')) return 'cashier';
        throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Fund handovers require Head Finance or Accountant role.');
    }

    private function assertValidParties(User $sender, User $receiver): void
    {
        if ($sender->school_id !== $receiver->school_id || $sender->id === $receiver->id) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Handover participants must be distinct users in the same school.');
        }
        if ($this->handoverRole($sender) === $this->handoverRole($receiver)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Fund handovers must be between Head Finance and Accountant; Accountant-to-Accountant is not allowed.');
        }
    }

    private function activeAccessibleAccount(User $user, int $accountId, bool $lock = false): BankAccount
    {
        $query = $this->access->accessibleAccounts($user)->active()->whereKey($accountId);
        if ($lock) $query->lockForUpdate();
        return $query->firstOrFail();
    }

    private function activeDestinationAccount(User $sender, User $receiver, int $accountId, bool $lock = false): BankAccount
    {
        $query = $this->eligibleDestinationAccounts($sender, $receiver)->whereKey($accountId);
        if ($lock) $query->lockForUpdate();
        return $query->firstOrFail();
    }

    private function assertCompatibleAccounts(BankAccount $from, BankAccount $to): void
    {
        if ($from->id === $to->id) throw new \InvalidArgumentException('Source and destination Fund Accounts must differ.');
        if ($from->currency !== $to->currency) throw new \InvalidArgumentException('Cannot hand over between Fund Accounts with different currencies.');
    }

    private function assertSufficientBalance(BankAccount $from, float $amount): void
    {
        if (!$this->balances->hasSufficientBalance($from, $amount)) {
            throw new \InvalidArgumentException('Insufficient balance in source Fund Account.');
        }
    }
}
