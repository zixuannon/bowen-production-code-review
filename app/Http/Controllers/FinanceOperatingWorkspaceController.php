<?php

namespace App\Http\Controllers;

use App\Models\FinanceGroup;
use App\Services\FinanceOperatingContextService;
use App\Services\FinanceOperatingWorkspaceService;
use App\Services\FinanceOperatingWriteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Scheme B single-School, central-identity workspace.
 *
 * Routes are deliberately GET-only except enter/exit session state. Existing
 * tenant Finance write routes are intentionally not mounted here.
 */
class FinanceOperatingWorkspaceController extends Controller
{
    public function __construct(
        private readonly FinanceOperatingContextService $context,
        private readonly FinanceOperatingWorkspaceService $workspace,
        private readonly FinanceOperatingWriteService $writes,
    ) {
    }

    public function enter(Request $request, FinanceGroup $financeGroup): RedirectResponse
    {
        $actor = Auth::user();
        abort_unless($actor, 403);
        $schoolId = $request->validate(['school_id' => ['required', 'integer', 'min:1']])['school_id'];
        $this->context->enterSchool($actor, $financeGroup->id, (int) $schoolId);

        return redirect()->route('group-finance.operating.bank-accounts');
    }

    public function exit(): RedirectResponse
    {
        $actor = Auth::user();
        abort_unless($actor, 403);
        $group = $this->context->currentGroup($actor);
        $this->context->exitSchool($actor);

        return $group
            ? redirect()->route('group-finance.show', $group)
            : redirect()->route('group-finance.index');
    }

    public function bankAccounts(): View
    {
        $actor = Auth::user();
        abort_unless($actor, 403);
        $workspace = $this->workspace->workspace($actor);
        $accounts = $this->workspace->accounts($actor);

        return view('group-finance.operating.bank-accounts', compact('workspace', 'accounts'));
    }

    public function transactions(Request $request): View
    {
        $actor = Auth::user();
        abort_unless($actor, 403);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'type' => ['nullable', Rule::in(\App\Services\FinanceTransactionRegisterService::TYPES)],
            'bank_account_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
            'keyword' => ['nullable', 'string', 'max:255'],
        ]);
        $workspace = $this->workspace->workspace($actor);
        $register = $this->workspace->ledger($actor, $filters);
        $accounts = $this->workspace->accounts($actor);

        return view('group-finance.operating.transactions', compact('workspace', 'register', 'filters', 'accounts'));
    }

    public function reports(Request $request): View
    {
        $actor = Auth::user();
        abort_unless($actor, 403);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'type' => ['nullable', Rule::in(\App\Services\FinanceTransactionRegisterService::TYPES)],
            'bank_account_id' => ['nullable', 'integer'],
        ]);
        $workspace = $this->workspace->workspace($actor);
        $register = $this->workspace->ledger($actor, $filters);
        $categories = $register['rows']->groupBy('transaction_class')->map(fn ($rows, $type) => [
            'type' => $type,
            'income' => (float) $rows->sum('operating_income'),
            'expense' => (float) $rows->sum('operating_expense'),
            'count' => $rows->count(),
        ])->values();

        return view('group-finance.operating.reports', compact('workspace', 'register', 'filters', 'categories'));
    }

    public function operations(): View
    {
        $actor = Auth::user();
        abort_unless($actor, 403);
        $workspace = $this->workspace->workspace($actor);
        $options = $this->writes->formOptions($actor);
        return view('group-finance.operating.operations', compact('workspace', 'options'));
    }

    public function storeExpense(Request $request): RedirectResponse
    {
        $actor = Auth::user(); abort_unless($actor, 403);
        $id = $this->writes->createExpense($actor, $request->all());
        return back()->with('success', __('Expense created.') . ' #' . $id);
    }

    public function receiveMoney(Request $request): RedirectResponse
    {
        $actor = Auth::user(); abort_unless($actor, 403);
        $id = $this->writes->receiveMoney($actor, $request->all());
        return back()->with('success', __('Money received successfully.') . ' #' . $id);
    }

    public function receiveStudentFee(Request $request): RedirectResponse
    {
        $actor = Auth::user(); abort_unless($actor, 403);
        $id = $this->writes->receiveStudentFee($actor, $request->all());
        return back()->with('success', __('Student fee received.') . ' #' . $id);
    }

    public function storeBankTransfer(Request $request): RedirectResponse
    {
        $actor = Auth::user(); abort_unless($actor, 403);
        $id = $this->writes->createBankTransfer($actor, $request->all());
        return back()->with('success', __('Bank transfer created successfully') . ' #' . $id);
    }

    public function storeFundHandover(Request $request): RedirectResponse
    {
        $actor = Auth::user(); abort_unless($actor, 403);
        $id = $this->writes->createFundHandover($actor, $request->all());
        return back()->with('success', __('Fund handover is pending receiver confirmation.') . ' #' . $id);
    }
}
