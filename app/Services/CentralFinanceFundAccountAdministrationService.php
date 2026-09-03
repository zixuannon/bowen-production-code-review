<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceFundAccountSchoolAllocation;
use App\Models\CentralFinanceFundAccountOpeningBalanceAudit;
use App\Models\CentralFinanceDocumentAudit;
use App\Models\CentralFinanceUser;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Creates and controls Central Fund Accounts without producing a Ledger entry. */
final class CentralFinanceFundAccountAdministrationService
{
    public function __construct(private readonly CentralFinanceConfigurationAuthorizationService $authorization) {}

    /** @param array{account_code:string,account_name:string,currency:string,opening_balance:float|int|string,opening_balance_date:string,opening_reason:string,account_type?:string,bank_name?:?string,masked_account_identifier?:?string,custodian_user_id?:?int,notes?:?string} $attributes @param array<int,int> $assigneeIds */
    public function createSchoolAccount(CentralFinanceUser $actor, School $school, array $attributes, array $assigneeIds): CentralFinanceFundAccount
    {
        return $this->createAccount($actor, $school, $attributes, $assigneeIds, CentralFinanceFundAccount::OWNER_SCHOOL);
    }

    /** An HQ account is Central custody, but uses the current School only to resolve a trusted Group and explicit Head Finance authority. */
    public function createHqAccount(CentralFinanceUser $actor, School $school, array $attributes, array $assigneeIds): CentralFinanceFundAccount
    {
        return $this->createAccount($actor, $school, $attributes, $assigneeIds, CentralFinanceFundAccount::OWNER_HQ);
    }

    /** @param array<int,int> $assigneeIds */
    public function syncSchoolAssignments(CentralFinanceUser $actor, School $school, CentralFinanceFundAccount $requestedAccount, array $assigneeIds, string $reason): void
    {
        if (trim($reason) === '') throw ValidationException::withMessages(['reason' => [__('A Fund Account scope reason is required.')]]);
        DB::connection('mysql')->transaction(function () use ($actor, $school, $requestedAccount, $assigneeIds, $reason): void {
            $account = CentralFinanceFundAccount::on('mysql')->lockForUpdate()->findOrFail($requestedAccount->id);
            $groupUser = $this->groupUserForAccount($actor, $school, $account);
            $this->assertAccountInConfigurationScope($account, $school, (int) $groupUser->group_id);
            $before = $account->authorizedUsers()->pluck('users.id')->sort()->values()->all();
            $this->syncAssignmentsLocked($actor, $school, $account, $groupUser->group_id, $assigneeIds);
            $after = $account->authorizedUsers()->pluck('users.id')->sort()->values()->all();
            $this->audit($school, $account, $actor, 'scope_updated', trim($reason), ['authorized_user_ids' => $before], ['authorized_user_ids' => $after]);
        });
    }

    /**
     * Assign a physical School-owned account to additional Group Schools.
     * This is not a transfer/reallocation workflow: once a non-zero baseline
     * has financial history it cannot be redistributed through this surface.
     * Removing an assignment is an inactivation audit, never a deletion.
     *
     * @param array<int,array{school_id:int,opening_allocation_amount:float|int|string,is_active?:bool}> $allocations
     */
    public function syncSchoolAllocations(CentralFinanceUser $actor, School $school, CentralFinanceFundAccount $requestedAccount, array $allocations, string $reason): void
    {
        if (trim($reason) === '') throw ValidationException::withMessages(['reason' => [__('A School allocation reason is required.')]]);
        DB::connection('mysql')->transaction(function () use ($actor, $school, $requestedAccount, $allocations, $reason): void {
            $account = CentralFinanceFundAccount::on('mysql')->lockForUpdate()->findOrFail($requestedAccount->id);
            if ($account->owner_type !== CentralFinanceFundAccount::OWNER_SCHOOL) {
                throw new \Illuminate\Auth\Access\AuthorizationException('Only School-owned Fund Accounts can have School allocations.');
            }
            $groupUser = $this->authorization->assertHeadFinanceCanConfigureSchool($actor, $school);
            if ((int) $account->group_id !== (int) $groupUser->group_id) {
                throw new \Illuminate\Auth\Access\AuthorizationException('The Fund Account is outside the configured Finance Group.');
            }

            $allowedSchools = DB::connection('mysql')->table('finance_group_schools')
                ->where('group_id', $groupUser->group_id)->where('status', 'active')->pluck('school_id')->map(fn ($id) => (int) $id);
            $requested = collect($allocations)->mapWithKeys(function (array $row): array {
                $schoolId = (int) ($row['school_id'] ?? 0);
                if ($schoolId <= 0 || !is_numeric($row['opening_allocation_amount'] ?? null) || (float) $row['opening_allocation_amount'] < 0) {
                    throw ValidationException::withMessages(['allocations' => [__('Each School allocation requires a non-negative opening amount.')]]);
                }
                // HTML checkboxes omit an unchecked value entirely.  Treating
                // that omission as active would silently grant a School access
                // to this physical account, so absence must fail closed.
                return [$schoolId => ['amount' => round((float) $row['opening_allocation_amount'], 4), 'active' => (bool) ($row['is_active'] ?? false)]];
            });
            if ($requested->keys()->diff($allowedSchools)->isNotEmpty()) {
                throw new \Illuminate\Auth\Access\AuthorizationException('A requested School is outside the trusted Finance Group.');
            }

            $existing = CentralFinanceFundAccountSchoolAllocation::on('mysql')->where('fund_account_id', $account->id)->lockForUpdate()->get()->keyBy('school_id');
            $hasLedger = $account->ledgerEntries()->exists();
            $candidate = $existing->mapWithKeys(fn ($item) => [(int) $item->school_id => ['amount' => (float) $item->opening_allocation_amount, 'active' => (bool) $item->is_active && $item->status === CentralFinanceFundAccountSchoolAllocation::STATUS_ACTIVE]]);
            foreach ($requested as $schoolId => $row) {
                $current = $existing->get($schoolId);
                if ($hasLedger && $current !== null && abs((float) $current->opening_allocation_amount - $row['amount']) > 0.0001) {
                    throw ValidationException::withMessages(['allocations' => [__('Opening allocation changes after Ledger history require the formal School Fund Reallocation workflow.')]]);
                }
                if ($hasLedger && $current !== null && (float) $current->opening_allocation_amount > 0 && !$row['active']) {
                    throw ValidationException::withMessages(['allocations' => [__('A funded School allocation with Ledger history cannot be removed without formal reallocation.')]]);
                }
                $candidate[$schoolId] = $row;
            }
            if (!$candidate->has((int) $account->school_id)) {
                throw ValidationException::withMessages(['allocations' => [__('The legacy owner School allocation must remain present.')]]);
            }
            $total = $candidate->filter(fn ($row) => $row['active'])->sum('amount');
            if ($total > (float) $account->opening_balance + 0.0001) {
                throw ValidationException::withMessages(['allocations' => [__('Active School opening allocations cannot exceed the physical Fund Account opening balance.')]]);
            }

            foreach ($requested as $schoolId => $row) {
                $before = $existing->get($schoolId)?->only(['school_id', 'opening_allocation_amount', 'is_active', 'status']);
                $allocation = CentralFinanceFundAccountSchoolAllocation::on('mysql')->updateOrCreate(
                    ['fund_account_id' => $account->id, 'school_id' => $schoolId],
                    ['opening_allocation_amount' => $row['amount'], 'effective_from' => $existing->get($schoolId)?->effective_from?->toDateString() ?? now()->toDateString(),
                     'effective_to' => $row['active'] ? null : now()->toDateString(), 'status' => $row['active'] ? CentralFinanceFundAccountSchoolAllocation::STATUS_ACTIVE : CentralFinanceFundAccountSchoolAllocation::STATUS_INACTIVE,
                     'is_active' => $row['active'], 'assigned_by' => $actor->id, 'assignment_reason' => trim($reason)]
                );
                $this->audit($school, $account, $actor, $row['active'] ? 'school_allocation_saved' : 'school_allocation_revoked', trim($reason), $before ?? [], $allocation->only(['school_id', 'opening_allocation_amount', 'is_active', 'status']));
            }
        });
    }

    public function adjustOpeningBalance(CentralFinanceUser $actor, School $school, CentralFinanceFundAccount $requestedAccount, float $amount, string $effectiveDate, string $reason): void
    {
        if (abs($amount) < 0.0001 || trim($reason) === '') {
            throw ValidationException::withMessages(['amount' => [__('Opening balance adjustment and reason are required.')]]);
        }
        DB::connection('mysql')->transaction(function () use ($actor, $school, $requestedAccount, $amount, $effectiveDate, $reason): void {
            $account = CentralFinanceFundAccount::on('mysql')->lockForUpdate()->findOrFail($requestedAccount->id);
            $groupUser = $this->groupUserForAccount($actor, $school, $account);
            $this->assertAccountInConfigurationScope($account, $school, (int) $groupUser->group_id);
            $old = (float) $account->opening_balance;
            $new = $old + $amount;
            if ($new < 0) {
                throw ValidationException::withMessages(['amount' => [__('Opening balance cannot become negative.')]]);
            }
            $account->update(['opening_balance' => $new]);
            CentralFinanceFundAccountOpeningBalanceAudit::on('mysql')->create([
                'fund_account_id' => $account->id, 'change_type' => CentralFinanceFundAccountOpeningBalanceAudit::ADJUSTMENT,
                'old_opening_balance' => $old, 'new_opening_balance' => $new, 'effective_date' => $effectiveDate,
                'reason' => trim($reason), 'created_by' => $actor->id,
            ]);
        });
    }

    /** Metadata never changes a balance or creates a Ledger entry. */
    public function updateMasterData(CentralFinanceUser $actor, School $school, CentralFinanceFundAccount $requestedAccount, array $attributes): void
    {
        DB::connection('mysql')->transaction(function () use ($actor, $school, $requestedAccount, $attributes): void {
            $account = CentralFinanceFundAccount::on('mysql')->lockForUpdate()->findOrFail($requestedAccount->id);
            $groupUser = $this->groupUserForAccount($actor, $school, $account);
            $this->assertAccountInConfigurationScope($account, $school, (int) $groupUser->group_id);
            $this->assertCustodian($account, $school, $attributes['custodian_user_id'] ?? null);
            $before = $account->only(['account_name', 'account_type', 'bank_name', 'masked_account_identifier', 'custodian_user_id', 'notes']);
            $account->fill([
                'account_name' => trim((string) $attributes['account_name']),
                'account_type' => $attributes['account_type'],
                'bank_name' => $this->nullableTrim($attributes['bank_name'] ?? null),
                'masked_account_identifier' => $this->nullableTrim($attributes['masked_account_identifier'] ?? null),
                'custodian_user_id' => $attributes['custodian_user_id'] ?? null,
                'notes' => $this->nullableTrim($attributes['notes'] ?? null),
            ])->save();
            $this->audit($school, $account, $actor, 'master_data_updated', trim((string) ($attributes['reason'] ?? 'Central Fund Account master-data update')), $before, $account->only(array_keys($before)));
        });
    }

    /** Deactivation/archival preserves all history. Archive is impossible until the real balance is zero. */
    public function changeStatus(CentralFinanceUser $actor, School $school, CentralFinanceFundAccount $requestedAccount, string $status, string $reason): void
    {
        if (!in_array($status, [CentralFinanceFundAccount::STATUS_ACTIVE, CentralFinanceFundAccount::STATUS_INACTIVE, CentralFinanceFundAccount::STATUS_ARCHIVED], true) || trim($reason) === '') {
            throw ValidationException::withMessages(['status' => [__('A valid Fund Account status and reason are required.')]]);
        }
        DB::connection('mysql')->transaction(function () use ($actor, $school, $requestedAccount, $status, $reason): void {
            $account = CentralFinanceFundAccount::on('mysql')->lockForUpdate()->findOrFail($requestedAccount->id);
            $groupUser = $this->groupUserForAccount($actor, $school, $account);
            $this->assertAccountInConfigurationScope($account, $school, (int) $groupUser->group_id);
            if ($status === CentralFinanceFundAccount::STATUS_ARCHIVED && abs(app(CentralFinanceFundAccountBalanceService::class)->currentBalance($account)) > 0.0001) {
                throw ValidationException::withMessages(['status' => [__('A Fund Account with a non-zero balance cannot be archived.')]]);
            }
            $account->update([
                'status' => $status, 'is_active' => $status === CentralFinanceFundAccount::STATUS_ACTIVE,
                'status_reason' => trim($reason), 'status_changed_by' => $actor->id, 'status_changed_at' => now(),
            ]);
            $this->audit($school, $account, $actor, 'lifecycle_'.$status, trim($reason), ['status' => $account->getOriginal('status'), 'is_active' => (bool) $account->getOriginal('is_active')], ['status' => $status, 'is_active' => $status === CentralFinanceFundAccount::STATUS_ACTIVE]);
        });
    }

    /** @param array<int,int> $assigneeIds */
    private function syncAssignmentsLocked(CentralFinanceUser $actor, School $school, CentralFinanceFundAccount $account, int $groupId, array $assigneeIds): void
    {
        $ids = collect($assigneeIds)->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->push($actor->id)->unique()->values();
        $assignments = [];
        foreach ($ids as $userId) {
            $this->authorization->assertEligibleAssignee($groupId, $school, $userId, true);
            $assignments[$userId] = ['can_view' => true, 'can_operate' => true, 'created_at' => now(), 'updated_at' => now()];
        }
        $account->authorizedUsers()->sync($assignments);
    }

    private function assertOpening(mixed $balance, mixed $reason): void
    {
        if (!is_numeric($balance) || (float) $balance < 0 || trim((string) $reason) === '') {
            throw ValidationException::withMessages(['opening_balance' => [__('A non-negative signed opening balance and reason are required.')]]);
        }
    }

    /** @param array{account_code:string,account_name:string,currency:string,opening_balance:float|int|string,opening_balance_date:string,opening_reason:string,account_type?:string,bank_name?:?string,masked_account_identifier?:?string,custodian_user_id?:?int,notes?:?string} $attributes @param array<int,int> $assigneeIds */
    private function createAccount(CentralFinanceUser $actor, School $school, array $attributes, array $assigneeIds, string $ownerType): CentralFinanceFundAccount
    {
        $groupUser = $ownerType === CentralFinanceFundAccount::OWNER_HQ
            ? $this->authorization->assertHeadFinanceCanConfigureHq($actor, $school)
            : $this->authorization->assertHeadFinanceCanConfigureSchool($actor, $school);
        $this->assertOpening($attributes['opening_balance'], $attributes['opening_reason']);

        return DB::connection('mysql')->transaction(function () use ($actor, $school, $groupUser, $attributes, $assigneeIds, $ownerType): CentralFinanceFundAccount {
            $this->assertCustodianForCreate($school, $attributes['custodian_user_id'] ?? null, (int) $groupUser->group_id, $ownerType);
            $account = CentralFinanceFundAccount::on('mysql')->create([
                'account_uuid' => (string) Str::uuid(), 'group_id' => $groupUser->group_id,
                'school_id' => $ownerType === CentralFinanceFundAccount::OWNER_HQ ? null : $school->id,
                'owner_type' => $ownerType, 'account_code' => $attributes['account_code'],
                'account_name' => $attributes['account_name'], 'currency' => $attributes['currency'],
                'opening_balance' => $attributes['opening_balance'], 'is_active' => true,
                'account_type' => $attributes['account_type'] ?? CentralFinanceFundAccount::TYPE_OTHER,
                'bank_name' => $this->nullableTrim($attributes['bank_name'] ?? null),
                'masked_account_identifier' => $this->nullableTrim($attributes['masked_account_identifier'] ?? null),
                'custodian_user_id' => $attributes['custodian_user_id'] ?? null,
                'status' => CentralFinanceFundAccount::STATUS_ACTIVE,
                'notes' => $this->nullableTrim($attributes['notes'] ?? null),
            ]);
            if ($ownerType === CentralFinanceFundAccount::OWNER_SCHOOL && Schema::connection('mysql')->hasTable('central_finance_fund_account_school_allocations')) {
                CentralFinanceFundAccountSchoolAllocation::on('mysql')->create([
                    'fund_account_id' => $account->id, 'school_id' => $school->id,
                    'opening_allocation_amount' => $attributes['opening_balance'], 'effective_from' => $attributes['opening_balance_date'],
                    'status' => CentralFinanceFundAccountSchoolAllocation::STATUS_ACTIVE, 'is_active' => true,
                    'assigned_by' => $actor->id, 'assignment_reason' => trim($attributes['opening_reason']),
                ]);
            }
            CentralFinanceFundAccountOpeningBalanceAudit::on('mysql')->create([
                'fund_account_id' => $account->id, 'change_type' => CentralFinanceFundAccountOpeningBalanceAudit::INITIAL,
                'old_opening_balance' => null, 'new_opening_balance' => $attributes['opening_balance'],
                'effective_date' => $attributes['opening_balance_date'], 'reason' => trim($attributes['opening_reason']),
                'created_by' => $actor->id,
            ]);
            $this->audit($school, $account, $actor, 'created', trim($attributes['opening_reason']), [], ['account_code' => $account->account_code, 'account_type' => $account->account_type, 'owner_type' => $account->owner_type, 'custodian_user_id' => $account->custodian_user_id]);
            $this->syncAssignmentsLocked($actor, $school, $account, $groupUser->group_id, $assigneeIds);

            return $account->fresh();
        });
    }

    private function assertAccountInConfigurationScope(CentralFinanceFundAccount $account, School $school, int $groupId): void
    {
        if ((int) $account->group_id !== $groupId
            || ($account->owner_type === CentralFinanceFundAccount::OWNER_SCHOOL && (int) $account->school_id !== $school->id)
            || ($account->owner_type === CentralFinanceFundAccount::OWNER_HQ && $account->school_id !== null)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('The Fund Account is outside the current Central School configuration.');
        }
    }

    private function groupUserForAccount(CentralFinanceUser $actor, School $school, CentralFinanceFundAccount $account): \App\Models\FinanceGroupUser
    {
        return $account->owner_type === CentralFinanceFundAccount::OWNER_HQ
            ? $this->authorization->assertHeadFinanceCanConfigureHq($actor, $school)
            : $this->authorization->assertHeadFinanceCanConfigureSchool($actor, $school);
    }

    private function assertCustodian(CentralFinanceFundAccount $account, School $school, ?int $custodianUserId): void
    {
        if ($custodianUserId === null) return;
        $this->assertCustodianForCreate($school, $custodianUserId, (int) $account->group_id, $account->owner_type);
    }

    private function assertCustodianForCreate(School $school, ?int $custodianUserId, int $groupId, string $ownerType): void
    {
        if ($custodianUserId === null) return;
        $query = CentralFinanceUser::on('mysql')->whereKey($custodianUserId);
        if ($ownerType === CentralFinanceFundAccount::OWNER_SCHOOL) {
            $query->whereIn('id', DB::connection('mysql')->table('central_finance_user_school_scopes')->where('school_id', $school->id)->where('can_view', true)->pluck('user_id'));
        } else {
            $query->whereIn('id', DB::connection('mysql')->table('finance_group_users')->where('group_id', $groupId)->where('status', 'active')->pluck('central_user_id'));
        }
        if (!$query->exists()) throw new \Illuminate\Auth\Access\AuthorizationException('The requested Fund Account custodian is outside the trusted Central Finance scope.');
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function audit(School $school, CentralFinanceFundAccount $account, CentralFinanceUser $actor, string $action, ?string $reason, array $before, array $after): void
    {
        CentralFinanceDocumentAudit::on('mysql')->create([
            'school_id' => $school->id, 'document_type' => 'fund_account', 'document_id' => $account->id,
            'action' => $action, 'actor_id' => $actor->id, 'reason' => $reason,
            'before_values' => $before, 'after_values' => $after,
        ]);
    }
}
