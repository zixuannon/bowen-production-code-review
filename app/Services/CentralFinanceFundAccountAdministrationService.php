<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceFundAccountOpeningBalanceAudit;
use App\Models\CentralFinanceUser;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Creates and controls Central Fund Accounts without producing a Ledger entry. */
final class CentralFinanceFundAccountAdministrationService
{
    public function __construct(private readonly CentralFinanceConfigurationAuthorizationService $authorization) {}

    /** @param array{account_code:string,account_name:string,currency:string,opening_balance:float|int|string,opening_balance_date:string,opening_reason:string} $attributes @param array<int,int> $assigneeIds */
    public function createSchoolAccount(CentralFinanceUser $actor, School $school, array $attributes, array $assigneeIds): CentralFinanceFundAccount
    {
        $groupUser = $this->authorization->assertHeadFinanceCanConfigureSchool($actor, $school);
        $this->assertOpening($attributes['opening_balance'], $attributes['opening_reason']);

        return DB::connection('mysql')->transaction(function () use ($actor, $school, $groupUser, $attributes, $assigneeIds): CentralFinanceFundAccount {
            $account = CentralFinanceFundAccount::on('mysql')->create([
                'account_uuid' => (string) Str::uuid(), 'group_id' => $groupUser->group_id,
                'school_id' => $school->id, 'owner_type' => CentralFinanceFundAccount::OWNER_SCHOOL,
                'account_code' => $attributes['account_code'], 'account_name' => $attributes['account_name'],
                'currency' => $attributes['currency'], 'opening_balance' => $attributes['opening_balance'], 'is_active' => true,
            ]);
            CentralFinanceFundAccountOpeningBalanceAudit::on('mysql')->create([
                'fund_account_id' => $account->id, 'change_type' => CentralFinanceFundAccountOpeningBalanceAudit::INITIAL,
                'old_opening_balance' => null, 'new_opening_balance' => $attributes['opening_balance'],
                'effective_date' => $attributes['opening_balance_date'], 'reason' => trim($attributes['opening_reason']),
                'created_by' => $actor->id,
            ]);
            $this->syncAssignmentsLocked($actor, $school, $account, $groupUser->group_id, $assigneeIds);

            return $account->fresh();
        });
    }

    /** @param array<int,int> $assigneeIds */
    public function syncSchoolAssignments(CentralFinanceUser $actor, School $school, CentralFinanceFundAccount $requestedAccount, array $assigneeIds): void
    {
        $groupUser = $this->authorization->assertHeadFinanceCanConfigureSchool($actor, $school);
        DB::connection('mysql')->transaction(function () use ($actor, $school, $requestedAccount, $groupUser, $assigneeIds): void {
            $account = CentralFinanceFundAccount::on('mysql')->lockForUpdate()->findOrFail($requestedAccount->id);
            if ($account->owner_type !== CentralFinanceFundAccount::OWNER_SCHOOL || (int) $account->school_id !== $school->id || (int) $account->group_id !== $groupUser->group_id) {
                throw new \Illuminate\Auth\Access\AuthorizationException('The Fund Account is outside the current Central School configuration.');
            }
            $this->syncAssignmentsLocked($actor, $school, $account, $groupUser->group_id, $assigneeIds);
        });
    }

    public function adjustOpeningBalance(CentralFinanceUser $actor, School $school, CentralFinanceFundAccount $requestedAccount, float $amount, string $effectiveDate, string $reason): void
    {
        $groupUser = $this->authorization->assertHeadFinanceCanConfigureSchool($actor, $school);
        if (abs($amount) < 0.0001 || trim($reason) === '') {
            throw ValidationException::withMessages(['amount' => [__('Opening balance adjustment and reason are required.')]]);
        }
        DB::connection('mysql')->transaction(function () use ($actor, $school, $requestedAccount, $groupUser, $amount, $effectiveDate, $reason): void {
            $account = CentralFinanceFundAccount::on('mysql')->lockForUpdate()->findOrFail($requestedAccount->id);
            if ($account->owner_type !== CentralFinanceFundAccount::OWNER_SCHOOL || (int) $account->school_id !== $school->id || (int) $account->group_id !== $groupUser->group_id) {
                throw new \Illuminate\Auth\Access\AuthorizationException('The Fund Account is outside the current Central School configuration.');
            }
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
}
