<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Services\FinanceAccountAccessService;
use App\Services\FinanceAuthorizationService;
use App\Services\FinanceTransactionRegisterService;
use App\Services\OtherIncomeService;
use App\Services\ResponseService;
use App\Services\FeesPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class FinanceTransactionController extends Controller
{
    public function transactions(Request $request, FinanceTransactionRegisterService $register)
    {
        ResponseService::noFeatureThenRedirect('Expense Management');
        app(FinanceAuthorizationService::class)->assert(Auth::user(), 'finance-dashboard-view');

        $filters = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'type' => ['nullable', Rule::in(FinanceTransactionRegisterService::TYPES)],
            'bank_account_id' => ['nullable', 'integer'], 'reference' => ['nullable', 'string', 'max:100'],
            'keyword' => ['nullable', 'string', 'max:255'],
        ]);
        $data = $register->register(Auth::user(), $filters);
        $accounts = app(FinanceAccountAccessService::class)->accessibleAccounts(Auth::user())
            ->active()->orderBy('account_name')->get(['id', 'account_name', 'currency']);
        $canReceive = app(FinanceAuthorizationService::class)->can(Auth::user(), 'finance-payment-create');
        $canExpense = app(FinanceAuthorizationService::class)->can(Auth::user(), 'finance-expense-create');

        return view('finance-transactions.index', array_merge($data, compact('filters', 'accounts', 'canReceive', 'canExpense')));
    }

    public function receive(Request $request, OtherIncomeService $income)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        app(FinanceAuthorizationService::class)->assert(Auth::user(), 'finance-payment-create');
        $data = $request->validate([
            'date' => ['required', 'date'],
            'payer' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:1000'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', Rule::in(FeesPaymentService::PAYMENT_METHODS)],
            'bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->where(fn ($q) => $q
                ->where('school_id', Auth::user()->school_id)->where('is_active', true)->whereNull('deleted_at'))],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'transaction_currency' => ['nullable', Rule::in(['MMK', 'USD', 'CNY'])],
            'original_amount' => ['nullable', 'numeric', 'min:0.01'],
            'exchange_rate_snapshot' => ['nullable', 'numeric', 'min:0.00000001'],
            'remark' => ['nullable', 'string', 'max:5000'],
        ]);

        $created = $income->receive(Auth::user(), $data);
        return response()->json(['error' => false, 'message' => __('Money received successfully.'), 'id' => $created->id]);
    }
}
