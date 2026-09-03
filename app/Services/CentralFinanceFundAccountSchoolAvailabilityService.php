<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceFundAccountSchoolAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/** The one compatibility seam for a physical Fund Account's School availability. */
final class CentralFinanceFundAccountSchoolAvailabilityService
{
    public function assertAccountAvailableForSchool(CentralFinanceFundAccount $account, int $schoolId): void
    {
        if (!$this->isAccountAvailableForSchool($account, $schoolId)) {
            throw new InvalidArgumentException('The Fund Account is not available for this School.');
        }
    }

    public function isAccountAvailableForSchool(CentralFinanceFundAccount $account, int $schoolId): bool
    {
        if ($account->owner_type === CentralFinanceFundAccount::OWNER_HQ) {
            // Existing HQ ownership/group authorization remains enforced by
            // callers; this helper only replaces the single-School account
            // ownership check.
            return $account->school_id === null;
        }
        if ($account->owner_type !== CentralFinanceFundAccount::OWNER_SCHOOL) {
            return false;
        }

        if ($this->allocationSchemaAvailable() && CentralFinanceFundAccountSchoolAllocation::on('mysql')
            ->where('fund_account_id', $account->id)->where('school_id', $schoolId)->effective()->exists()) {
            return true;
        }

        // Transitional fallback only.  The additive migration backfills every
        // legacy school account, so this branch protects rollout ordering and
        // old isolated fixtures without becoming a second ownership model.
        return (int) $account->school_id === $schoolId;
    }

    public function activeAllocation(CentralFinanceFundAccount $account, int $schoolId): ?CentralFinanceFundAccountSchoolAllocation
    {
        if (!$this->allocationSchemaAvailable()) return null;
        return CentralFinanceFundAccountSchoolAllocation::on('mysql')
            ->where('fund_account_id', $account->id)->where('school_id', $schoolId)->effective()->first();
    }

    /** @param Builder<CentralFinanceFundAccount> $query */
    public function scopeAccountsForSchool(Builder $query, int $schoolId): Builder
    {
        if (!$this->allocationSchemaAvailable()) {
            return $query->where('owner_type', CentralFinanceFundAccount::OWNER_SCHOOL)->where('school_id', $schoolId);
        }
        return $query->where(function (Builder $accounts) use ($schoolId): void {
            $accounts->where(function (Builder $schoolAccounts) use ($schoolId): void {
                $schoolAccounts->where('owner_type', CentralFinanceFundAccount::OWNER_SCHOOL)
                    ->where(function (Builder $ownership) use ($schoolId): void {
                        $ownership->where('school_id', $schoolId)
                            ->orWhereHas('schoolAllocations', fn (Builder $allocations) => $allocations->where('school_id', $schoolId)->effective());
                    });
            });
        });
    }

    public function allocationSchemaAvailable(): bool
    {
        return Schema::connection('mysql')->hasTable('central_finance_fund_account_school_allocations');
    }
}
