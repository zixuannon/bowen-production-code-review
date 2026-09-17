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
        if (!in_array($account->owner_type, [CentralFinanceFundAccount::OWNER_HQ, CentralFinanceFundAccount::OWNER_SCHOOL], true)
            || !$account->is_active
            || (isset($account->status) && $account->status !== CentralFinanceFundAccount::STATUS_ACTIVE)
            || !$this->allocationSchemaAvailable()) {
            return false;
        }

        return CentralFinanceFundAccountSchoolAllocation::on('mysql')
            ->where('fund_account_id', $account->id)
            ->where('school_id', $schoolId)
            ->effective()
            ->exists();
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
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('schoolAllocations', fn (Builder $allocations) => $allocations
            ->where('school_id', $schoolId)
            ->effective());
    }

    public function allocationSchemaAvailable(): bool
    {
        return Schema::connection('mysql')->hasTable('central_finance_fund_account_school_allocations');
    }
}
