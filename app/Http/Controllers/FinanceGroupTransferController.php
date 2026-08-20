<?php

namespace App\Http\Controllers;

use App\Models\FinanceGroup;
use App\Models\FinanceGroupHqAccount;
use App\Models\FinanceGroupTransfer;
use App\Services\FinanceGroupScopeService;
use App\Services\FinanceGroupTransferService;
use App\Services\FinanceOperatingWorkspaceService;
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
        private readonly FinanceOperatingWorkspaceService $operatingWorkspace,
    ) {}

    public function funding(FinanceGroup $financeGroup): View
    {
        $workspace = $this->operatingWorkspace($financeGroup);
        $groupUser = $workspace['groupUser'];
        $operatingSchool = $workspace['school'];
        $schools = collect([$workspace['group']->schools()->where('school_id', $operatingSchool->id)->firstOrFail()->load('school')]);
        $mayConfirm = $this->scope->canConfirmGroupTransfers($groupUser);
        $mayControlHq = $this->scope->canControlHqAccounts($groupUser);
        $hqAccounts = FinanceGroupHqAccount::query()->active()->where('group_id', $financeGroup->id)
            ->when(!$mayControlHq, fn ($q) => $q->whereHas('authorizedGroupUsers', fn ($users) => $users->whereKey($groupUser->id)))
            ->orderBy('account_name')->get();
        $accountsBySchool = [
            $operatingSchool->id => $this->scope->accessibleActiveTenantAccountsForGroupUser(
                $groupUser, $operatingSchool->id, 'operate_finance',
            ),
        ];

        $transfers = $financeGroup->transfers()->with(['school', 'hqAccount', 'requester.centralUser'])->latest('id');
        // An Operating Context is exactly one trusted School. A URL cannot
        // turn it into an all-School funding history view.
        $transfers->where('school_id', $operatingSchool->id);

        return view('finance-groups.transfers.index', [
            'financeGroup' => $financeGroup,
            'groupUser' => $groupUser,
            'schools' => $schools,
            'hqAccounts' => $hqAccounts,
            'accountsBySchool' => $accountsBySchool,
            'operatingSchool' => $operatingSchool,
            'mayConfirm' => $mayConfirm,
            'mayControlHq' => $mayControlHq,
            'groupUsers' => $financeGroup->users()->with('centralUser')->where('status', 'active')->get(),
            'transfers' => $transfers->get(),
        ]);
    }

    public function store(Request $request, FinanceGroup $financeGroup): RedirectResponse
    {
        $workspace = $this->operatingWorkspace($financeGroup);
        $groupUser = $workspace['groupUser'];
        $data = $request->validate([
            'tenant_bank_account_id' => ['required', 'integer'],
            'hq_account_id' => ['nullable', 'integer'],
            'direction' => ['required', 'in:HQ_TO_SCHOOL,SCHOOL_TO_HQ'],
            'purpose' => ['required', 'in:HQ_FUNDING,SCHOOL_REMITTANCE,OPERATIONS,PROCUREMENT,ACTIVITY,EMERGENCY,OTHER'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'transfer_date' => ['required', 'date'],
            'reference_no' => ['nullable', 'string', 'max:128'],
            'notes' => ['nullable', 'string'],
        ]);
        // The Operating Context, not request input, selects the School.
        $data['school_id'] = (int) $workspace['school']->id;
        $this->transfers->request($groupUser, $data, 'operate_finance');
        return back()->with('success', __('Group funding request submitted for Head Finance confirmation.'));
    }

    public function confirm(Request $request, FinanceGroup $financeGroup, FinanceGroupTransfer $transfer): RedirectResponse
    {
        $workspace = $this->operatingWorkspace($financeGroup);
        $groupUser = $workspace['groupUser'];
        abort_unless($transfer->group_id === $financeGroup->id && $transfer->school_id === $workspace['school']->id, 404);
        $data = $request->validate(['hq_account_id' => ['required', 'integer']]);
        $this->transfers->confirm($groupUser, $transfer->id, $data, 'operate_finance');
        return back()->with('success', __('Group funding transfer confirmed.'));
    }

    public function reject(Request $request, FinanceGroup $financeGroup, FinanceGroupTransfer $transfer): RedirectResponse
    {
        $workspace = $this->operatingWorkspace($financeGroup);
        $groupUser = $workspace['groupUser'];
        abort_unless($transfer->group_id === $financeGroup->id && $transfer->school_id === $workspace['school']->id, 404);
        $this->transfers->reject($groupUser, $transfer->id, (string) $request->validate(['reason'=>['required','string','max:1000']])['reason']);
        return back()->with('success', __('Group funding request rejected.'));
    }

    public function cancel(Request $request, FinanceGroup $financeGroup, FinanceGroupTransfer $transfer): RedirectResponse
    {
        $workspace = $this->operatingWorkspace($financeGroup);
        $groupUser = $workspace['groupUser'];
        abort_unless($transfer->group_id === $financeGroup->id && $transfer->school_id === $workspace['school']->id, 404);
        $this->transfers->cancel($groupUser, $transfer->id, (string) $request->validate(['reason'=>['required','string','max:1000']])['reason']);
        return back()->with('success', __('Group funding request cancelled.'));
    }

    /** @return array<string,mixed> */
    private function operatingWorkspace(FinanceGroup $group): array
    {
        $auth = Auth::user();
        abort_unless($auth && $auth->school_id === null, 403);
        $workspace = $this->operatingWorkspace->workspace($auth);
        abort_unless($workspace['group']->id === $group->id, 403);

        return $workspace;
    }
}
