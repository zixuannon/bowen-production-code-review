<?php

namespace App\Http\Controllers;

use App\Models\FinanceGroup;
use App\Models\FinanceGroupHqAccount;
use App\Models\FinanceGroupTransfer;
use App\Models\FinanceGroupUser;
use App\Models\User;
use App\Services\FinanceGroupScopeService;
use App\Services\FinanceGroupTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Central Group funding workflow; it never accepts a tenant database name. */
class FinanceGroupTransferController extends Controller
{
    public function __construct(
        private readonly FinanceGroupScopeService $scope,
        private readonly FinanceGroupTransferService $transfers,
    ) {}

    public function funding(FinanceGroup $financeGroup): View
    {
        $groupUser = $this->groupUser($financeGroup);
        $schools = $this->scope->accessibleSchools($groupUser, 'request_group_transfers')->load('school');
        $mayConfirm = $this->scope->canConfirmGroupTransfers($groupUser);
        $mayControlHq = $this->scope->canControlHqAccounts($groupUser);
        $hqAccounts = FinanceGroupHqAccount::query()->active()->where('group_id', $financeGroup->id)
            ->when(!$mayControlHq, fn ($q) => $q->whereHas('authorizedGroupUsers', fn ($users) => $users->whereKey($groupUser->id)))
            ->orderBy('account_name')->get();
        // An HQ Accountant may have no School request scope, but must still
        // be able to reach the HQ accounts explicitly assigned to them.
        abort_unless($schools->isNotEmpty() || $mayConfirm || $hqAccounts->isNotEmpty(), 403);

        $accountsBySchool = [];
        foreach ($schools as $membership) {
            $accountsBySchool[$membership->school_id] = $this->scope->accessibleActiveTenantAccountsForGroupUser($groupUser, $membership->school_id);
        }

        $transfers = $financeGroup->transfers()->with(['school', 'hqAccount', 'requester.centralUser'])->latest('id');
        if (!$mayConfirm) {
            // A School-scoped requester can only inspect funding records for
            // Schools within that exact scope; peer Schools remain opaque.
            $transfers->whereIn('school_id', $schools->pluck('school_id')->all());
        }

        return view('finance-groups.transfers.index', [
            'financeGroup' => $financeGroup,
            'groupUser' => $groupUser,
            'schools' => $schools,
            'hqAccounts' => $hqAccounts,
            'accountsBySchool' => $accountsBySchool,
            'mayConfirm' => $mayConfirm,
            'mayControlHq' => $mayControlHq,
            'groupUsers' => $financeGroup->users()->with('centralUser')->where('status', 'active')->get(),
            'transfers' => $transfers->get(),
        ]);
    }

    public function store(Request $request, FinanceGroup $financeGroup): RedirectResponse
    {
        $groupUser = $this->groupUser($financeGroup);
        $data = $request->validate([
            'school_id' => ['required', 'integer'],
            'tenant_bank_account_id' => ['required', 'integer'],
            'hq_account_id' => ['nullable', 'integer'],
            'direction' => ['required', 'in:HQ_TO_SCHOOL,SCHOOL_TO_HQ'],
            'purpose' => ['required', 'in:HQ_FUNDING,SCHOOL_REMITTANCE,OPERATIONS,PROCUREMENT,ACTIVITY,EMERGENCY,OTHER'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'transfer_date' => ['required', 'date'],
            'reference_no' => ['nullable', 'string', 'max:128'],
            'notes' => ['nullable', 'string'],
        ]);
        $this->transfers->request($groupUser, $data);
        return back()->with('success', __('Group funding request submitted for Head Finance confirmation.'));
    }

    public function confirm(Request $request, FinanceGroup $financeGroup, FinanceGroupTransfer $transfer): RedirectResponse
    {
        $groupUser = $this->groupUser($financeGroup);
        abort_unless($transfer->group_id === $financeGroup->id, 404);
        $data = $request->validate(['hq_account_id' => ['required', 'integer']]);
        $this->transfers->confirm($groupUser, $transfer->id, $data);
        return back()->with('success', __('Group funding transfer confirmed.'));
    }

    public function reject(Request $request, FinanceGroup $financeGroup, FinanceGroupTransfer $transfer): RedirectResponse
    {
        $groupUser = $this->groupUser($financeGroup);
        abort_unless($transfer->group_id === $financeGroup->id, 404);
        $this->transfers->reject($groupUser, $transfer->id, (string) $request->validate(['reason'=>['required','string','max:1000']])['reason']);
        return back()->with('success', __('Group funding request rejected.'));
    }

    public function cancel(Request $request, FinanceGroup $financeGroup, FinanceGroupTransfer $transfer): RedirectResponse
    {
        $groupUser = $this->groupUser($financeGroup);
        abort_unless($transfer->group_id === $financeGroup->id, 404);
        $this->transfers->cancel($groupUser, $transfer->id, (string) $request->validate(['reason'=>['required','string','max:1000']])['reason']);
        return back()->with('success', __('Group funding request cancelled.'));
    }

    private function groupUser(FinanceGroup $group): FinanceGroupUser
    {
        $auth = Auth::user();
        abort_unless($auth && $auth->school_id === null, 403);
        $central = User::on('mysql')->find($auth->id);
        abort_unless($central && $central->school_id === null, 403);
        return FinanceGroupUser::query()->where('group_id', $group->id)->where('central_user_id', $central->id)->where('status', 'active')->firstOrFail();
    }
}
