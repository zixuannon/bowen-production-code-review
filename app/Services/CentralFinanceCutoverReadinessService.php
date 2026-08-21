<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceFundAccountOpeningBalanceAudit;
use App\Models\CentralFinanceUser;
use App\Models\FinanceGroupSchool;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Verifies configuration only; it never creates accounts, balances, or transactions. */
final class CentralFinanceCutoverReadinessService
{
    public function __construct(private readonly CentralFinanceConfigurationAuthorizationService $authorization) {}

    public function assertReadyForCentral(School $school): void
    {
        $groupIds = FinanceGroupSchool::on('mysql')->where('school_id', $school->id)->where('status', 'active')
            ->whereHas('group', fn ($query) => $query->where('status', 'active'))->pluck('group_id');
        if ($groupIds->isEmpty()) {
            throw new LogicException('Central cutover requires active Group membership for the School.');
        }
        $accounts = CentralFinanceFundAccount::on('mysql')->active()->where('owner_type', CentralFinanceFundAccount::OWNER_SCHOOL)
            ->where('school_id', $school->id)->whereIn('group_id', $groupIds)->get();
        if ($accounts->isEmpty()) {
            throw new LogicException('Central cutover requires an active School Fund Account.');
        }
        foreach ($accounts as $account) {
            $initial = CentralFinanceFundAccountOpeningBalanceAudit::on('mysql')->where([
                'fund_account_id' => $account->id, 'change_type' => CentralFinanceFundAccountOpeningBalanceAudit::INITIAL,
            ])->first();
            $latest = CentralFinanceFundAccountOpeningBalanceAudit::on('mysql')->where('fund_account_id', $account->id)->latest('id')->first();
            if (!$initial || !$latest || (float) $latest->new_opening_balance !== (float) $account->opening_balance) {
                continue;
            }
            $headIds = DB::connection('mysql')->table('central_finance_fund_account_users')
                ->where('fund_account_id', $account->id)->where('can_view', true)->where('can_operate', true)->pluck('user_id');
            foreach (CentralFinanceUser::on('mysql')->whereIn('id', $headIds)->get() as $actor) {
                if ($this->authorization->isHeadFinanceOperatingForSchool($actor, $school, (int) $account->group_id)) {
                    return;
                }
            }
        }
        throw new LogicException('Central cutover requires a signed opening balance and assigned Head Finance Fund Account authority.');
    }
}
