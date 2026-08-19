<?php

namespace App\Http\Controllers;

use App\Models\FinanceGroup;
use App\Models\FinanceGroupHqAccount;
use App\Models\FinanceGroupHqAccountAdjustment;
use App\Models\FinanceGroupUser;
use App\Models\User;
use App\Services\FinanceGroupHqAccountBalanceService;
use App\Services\FinanceGroupScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Central HQ account control; no tenant Fund Account is mutated here. */
class FinanceGroupHqAccountController extends Controller
{
    public function __construct(private readonly FinanceGroupScopeService $scope, private readonly FinanceGroupHqAccountBalanceService $balances) {}

    public function store(Request $request, FinanceGroup $financeGroup): RedirectResponse
    {
        $actor = $this->actor($financeGroup); abort_unless($this->scope->canControlHqAccounts($actor), 403);
        $data = $this->createData($request);
        FinanceGroupHqAccount::query()->create(array_merge($data, ['group_id'=>$financeGroup->id, 'created_by'=>$actor->central_user_id, 'updated_by'=>$actor->central_user_id]));
        return back()->with('success', __('HQ Fund Account created.'));
    }

    public function update(Request $request, FinanceGroup $financeGroup, FinanceGroupHqAccount $hqAccount): RedirectResponse
    {
        $actor=$this->actor($financeGroup); abort_unless($hqAccount->group_id===$financeGroup->id && $this->scope->canControlHqAccounts($actor),403);
        // opening_balance/currency are immutable after creation. A later
        // balance change must use adjust(), recording old/new/reason/actor.
        $data=$request->validate(['account_name'=>['required','string','max:191'],'account_number'=>['nullable','string','max:128'],'account_type'=>['required','in:cash,bank,mobile_payment'],'is_active'=>['required','boolean'],'notes'=>['nullable','string']]);
        $hqAccount->update(array_merge($data,['updated_by'=>$actor->central_user_id]));
        return back()->with('success', __('HQ Fund Account updated.'));
    }

    public function adjust(Request $request, FinanceGroup $financeGroup, FinanceGroupHqAccount $hqAccount): RedirectResponse
    {
        $actor=$this->actor($financeGroup); abort_unless($hqAccount->group_id===$financeGroup->id && $this->scope->canControlHqAccounts($actor),403);
        $data=$request->validate(['amount'=>['required','numeric','not_in:0'],'adjustment_date'=>['required','date'],'reason'=>['required','string','max:2000']]);
        DB::connection('mysql')->transaction(function () use ($hqAccount,$actor,$data): void {
            $account=FinanceGroupHqAccount::query()->lockForUpdate()->findOrFail($hqAccount->id);
            $before=$this->balances->currentBalance($account); $after=$before+(float)$data['amount'];
            FinanceGroupHqAccountAdjustment::query()->create(['hq_account_id'=>$account->id,'amount'=>$data['amount'],'balance_before'=>$before,'balance_after'=>$after,'adjustment_date'=>$data['adjustment_date'],'reason'=>trim($data['reason']),'created_by_group_user_id'=>$actor->id]);
        });
        return back()->with('success', __('HQ Fund Account adjustment recorded.'));
    }

    public function syncUsers(Request $request, FinanceGroup $financeGroup, FinanceGroupHqAccount $hqAccount): RedirectResponse
    {
        $actor=$this->actor($financeGroup); abort_unless($hqAccount->group_id===$financeGroup->id && $this->scope->canControlHqAccounts($actor),403);
        $ids=collect($request->validate(['group_user_ids'=>['nullable','array'],'group_user_ids.*'=>['integer','distinct']])['group_user_ids']??[])->map(fn($id)=>(int)$id)->filter()->values();
        abort_unless(FinanceGroupUser::query()->where('group_id',$financeGroup->id)->whereIn('id',$ids)->count()===$ids->count(),422);
        $hqAccount->authorizedGroupUsers()->sync($ids->all());
        return back()->with('success', __('HQ Fund Account assignments saved.'));
    }

    private function actor(FinanceGroup $group): FinanceGroupUser
    {
        $auth=Auth::user(); abort_unless($auth && $auth->school_id===null,403); $central=User::on('mysql')->find($auth->id); abort_unless($central && $central->school_id===null,403);
        return FinanceGroupUser::query()->where('group_id',$group->id)->where('central_user_id',$central->id)->where('status','active')->firstOrFail();
    }

    private function createData(Request $request): array
    {
        return $request->validate(['account_name'=>['required','string','max:191'],'account_number'=>['nullable','string','max:128'],'account_type'=>['required','in:cash,bank,mobile_payment'],'currency'=>['required','string','size:3','alpha'],'opening_balance'=>['required','numeric'],'opening_balance_date'=>['required','date'],'is_active'=>['required','boolean'],'notes'=>['nullable','string']]);
    }
}
