<?php

namespace App\Http\Controllers;

use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceUnidentifiedDeposit;
use App\Services\CentralFinanceConfigurationAuthorizationService;
use App\Services\CentralFinanceUnidentifiedDepositService;
use App\Services\CentralFinanceWorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/** Head-Finance-only group cash desk; it never fabricates a School for unknown money. */
final class CentralFinanceUnidentifiedDepositController extends Controller
{
    public function __construct(
        private readonly CentralFinanceWorkspaceService $workspace,
        private readonly CentralFinanceConfigurationAuthorizationService $configuration,
        private readonly CentralFinanceUnidentifiedDepositService $deposits,
    ) {}

    public function index(): View
    {
        $actor = $this->workspace->actor(Auth::user());
        $this->workspace->assertHeadFinance($actor);
        $groups = $this->configuration->configurableGroups($actor);
        $accounts = CentralFinanceFundAccount::on('mysql')->active()->where('owner_type', CentralFinanceFundAccount::OWNER_HQ)
            ->whereNull('school_id')->whereIn('group_id', $groups->pluck('id'))->orderBy('account_code')->get();
        $deposits = CentralFinanceUnidentifiedDeposit::on('mysql')->with('fundAccount')->whereIn('group_id', $groups->pluck('id'))
            ->latest('received_date')->paginate(30);
        return view('central-finance.unidentified-deposits.index', compact('accounts', 'deposits'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->workspace->actor(Auth::user());
        $data = $request->validate([
            'fund_account_id' => ['required', 'integer'], 'amount' => ['required', 'regex:/^[0-9]+(?:\.[0-9]{1,4})?$/'],
            'received_date' => ['required', 'date_format:Y-m-d'], 'idempotency_reference' => ['required', 'regex:/^[A-Za-z0-9_.:-]{2,100}$/'],
            'bank_reference' => ['nullable', 'string', 'max:100'], 'known_payer' => ['nullable', 'string', 'max:191'], 'description' => ['nullable', 'string', 'max:2000'],
        ]);
        try {
            $deposit = $this->deposits->record($actor, CentralFinanceFundAccount::on('mysql')->findOrFail($data['fund_account_id']), (string) $data['amount'], CarbonImmutable::parse($data['received_date'].' 00:00:00', 'Asia/Yangon'), $data['idempotency_reference'], $data['bank_reference'] ?? null, $data['description'] ?? null, $data['known_payer'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['deposit' => $exception->getMessage()]);
        }
        return back()->with('success', __('Unidentified Deposit recorded: :id', ['id' => $deposit->deposit_uuid]));
    }

    public function match(Request $request, CentralFinanceUnidentifiedDeposit $deposit): RedirectResponse
    {
        $actor = $this->workspace->actor(Auth::user());
        $data = $request->validate([
            'receivable_id' => ['required', 'integer'], 'amount' => ['required', 'regex:/^[0-9]+(?:\.[0-9]{1,4})?$/'],
            'reason' => ['required', 'string', 'max:2000'], 'idempotency_reference' => ['required', 'regex:/^[A-Za-z0-9_.:-]{2,100}$/'],
        ]);
        try {
            $this->deposits->match($actor, $deposit->id, (int) $data['receivable_id'], (string) $data['amount'], CarbonImmutable::now('Asia/Yangon'), $data['reason'], $data['idempotency_reference']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['match' => $exception->getMessage()]);
        }
        return back()->with('success', __('Unidentified Deposit matched. No second Fund Account or Ledger entry was created.'));
    }
}
