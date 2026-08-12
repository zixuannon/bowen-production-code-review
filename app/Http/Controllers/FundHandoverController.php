<?php

namespace App\Http\Controllers;

use App\Models\FundHandover;
use App\Services\FinanceAccountAccessService;
use App\Services\FundHandoverService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FundHandoverController extends Controller
{
    public function __construct(private readonly FundHandoverService $handovers) {}

    public function index()
    {
        ResponseService::noFeatureThenRedirect('Expense Management');

        $actor = Auth::user();
        // Fund Handover has its own custody roles. A Head Finance or Cashier
        // must not be gated by unrelated generic Expense permissions.
        $this->handovers->assertCanViewRegister($actor);
        $canParticipate = $this->handovers->isParticipant($actor);
        $recipients = collect();
        $senderAccounts = collect();
        $recipientAccounts = [];

        // Oversight is deliberately read-only. Do not call participant-only
        // candidate/account logic for School Admin sessions.
        if ($canParticipate) {
            $access = app(FinanceAccountAccessService::class);
            $recipients = $this->handovers->recipientCandidates($actor)->orderBy('first_name')->get(['id', 'school_id', 'first_name', 'last_name', 'email']);
            $senderAccounts = $access->accessibleAccounts($actor)->active()->orderBy('account_name')->get(['id', 'account_name', 'account_number', 'currency']);
            foreach ($recipients as $recipient) {
                $recipientAccounts[$recipient->id] = $this->handovers->eligibleDestinationAccounts($actor, $recipient)
                    ->orderBy('account_name')
                    ->get(['id', 'account_name', 'account_number', 'currency'])
                    ->values();
            }
        }

        return view('bank-account.handover.index', compact('recipients', 'senderAccounts', 'recipientAccounts', 'canParticipate'));
    }

    public function list(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');

        $actor = Auth::user();
        $this->handovers->assertCanViewRegister($actor);
        $query = FundHandover::where('school_id', $actor->school_id)
            ->with(['from_account:id,account_name', 'to_account:id,account_name', 'sender:id,first_name,last_name', 'receiver:id,first_name,last_name']);
        if (!$actor->hasRole('School Admin')) {
            $query->where(fn ($q) => $q->where('sender_id', $actor->id)->orWhere('receiver_id', $actor->id));
        }
        $total = $query->count();
        $rows = $query->orderByDesc('id')->paginate((int) $request->input('limit', 20), ['*'], 'page', (int) floor(((int) $request->input('offset', 0)) / max(1, (int) $request->input('limit', 20)) + 1));

        return response()->json([
            'total' => $total,
            'rows' => $rows->getCollection()->map(function (FundHandover $handover) use ($actor) {
                return [
                    'id' => $handover->id,
                    'handover_date' => $handover->handover_date?->toDateString(),
                    'from_account_name' => $handover->from_account?->account_name,
                    'to_account_name' => $handover->to_account?->account_name,
                    'sender_name' => trim(($handover->sender?->first_name ?? '') . ' ' . ($handover->sender?->last_name ?? '')),
                    'receiver_name' => trim(($handover->receiver?->first_name ?? '') . ' ' . ($handover->receiver?->last_name ?? '')),
                    'amount' => $handover->amount,
                    'reference_no' => $handover->reference_no,
                    'notes' => $handover->notes,
                    'status' => $handover->status,
                    'audit' => match ($handover->status) {
                        FundHandover::STATUS_CONFIRMED => __('Confirmed by :name at :time', ['name' => trim(($handover->receiver?->first_name ?? '') . ' ' . ($handover->receiver?->last_name ?? '')), 'time' => optional($handover->confirmed_at)->toDateTimeString()]),
                        FundHandover::STATUS_REJECTED => __('Rejected by :name at :time — :reason', ['name' => trim(($handover->receiver?->first_name ?? '') . ' ' . ($handover->receiver?->last_name ?? '')), 'time' => optional($handover->rejected_at)->toDateTimeString(), 'reason' => $handover->rejection_reason]),
                        FundHandover::STATUS_CANCELLED => __('Cancelled by :name at :time — :reason', ['name' => trim(($handover->sender?->first_name ?? '') . ' ' . ($handover->sender?->last_name ?? '')), 'time' => optional($handover->cancelled_at)->toDateTimeString(), 'reason' => $handover->cancellation_reason]),
                        default => __('Pending confirmation'),
                    },
                    'can_confirm' => $handover->status === FundHandover::STATUS_PENDING && $handover->receiver_id === $actor->id,
                    'can_reject' => $handover->status === FundHandover::STATUS_PENDING && $handover->receiver_id === $actor->id,
                    'can_cancel' => $handover->status === FundHandover::STATUS_PENDING && $handover->sender_id === $actor->id,
                ];
            })->values(),
        ]);
    }

    public function store(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        $this->handovers->assertParticipant(Auth::user());
        $data = $request->validate([
            'receiver_id' => ['required', 'integer'],
            'from_account_id' => ['required', 'integer'],
            'to_account_id' => ['required', 'integer', 'different:from_account_id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'handover_date' => ['required', 'date'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $handover = $this->handovers->create(Auth::user(), $data);
        } catch (\DomainException | \InvalidArgumentException $exception) {
            return response()->json(['error' => true, 'message' => $exception->getMessage()], 422);
        }
        return response()->json(['error' => false, 'message' => __('Fund handover is pending receiver confirmation.'), 'id' => $handover->id]);
    }

    public function confirm($id)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        $this->handovers->assertParticipant(Auth::user());
        try {
            $handover = $this->handovers->confirm(Auth::user(), (int) $id);
        } catch (\DomainException | \InvalidArgumentException $exception) {
            return response()->json(['error' => true, 'message' => $exception->getMessage()], 422);
        }
        return response()->json(['error' => false, 'message' => __('Fund handover confirmed and transfer recorded.'), 'id' => $handover->id]);
    }

    public function reject(Request $request, $id)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        $this->handovers->assertParticipant(Auth::user());
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        try {
            $this->handovers->reject(Auth::user(), (int) $id, $data['reason']);
        } catch (\DomainException | \InvalidArgumentException $exception) {
            return response()->json(['error' => true, 'message' => $exception->getMessage()], 422);
        }
        return response()->json(['error' => false, 'message' => __('Fund handover rejected.')]);
    }

    public function cancel(Request $request, $id)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        $this->handovers->assertParticipant(Auth::user());
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        try {
            $this->handovers->cancel(Auth::user(), (int) $id, $data['reason']);
        } catch (\DomainException | \InvalidArgumentException $exception) {
            return response()->json(['error' => true, 'message' => $exception->getMessage()], 422);
        }
        return response()->json(['error' => false, 'message' => __('Fund handover cancelled.')]);
    }
}
