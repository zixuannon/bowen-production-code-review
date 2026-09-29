@extends('layouts.master')

@section('title', __('Payment details'))
@section('css')@include('central-finance.partials.foundation-styles')@endsection

@section('content')
<div class="content-wrapper central-finance-page">
    <x-central-finance.page-header :title="__('Payment details')" :description="__('The confirmed payment and receipt are immutable. Refund and reversal create separate append-only correction records.')" :school="$school" eyebrow="{{ __('学生收费') }}">
        <a class="btn btn-outline-secondary" href="{{ route('central-finance.payments.index') }}">{{ __('Back to payment history') }}</a>
    </x-central-finance.page-header>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="card mb-3"><div class="card-body"><h5>{{ __('Original confirmed payment') }}</h5><table class="table table-bordered cf-data-table mb-0"><tbody>
        <tr><th>{{ __('Receipt') }}</th><td><a href="{{ route('central-finance.payments.receipt', $document->id) }}">{{ $receipt->receipt['number'] }}</a></td></tr>
        <tr><th>{{ __('Original Payment') }}</th><td>{{ number_format($document->amount, 2) }} {{ $document->currency }}</td></tr>
        <tr><th>{{ __('Refunded') }}</th><td>{{ number_format($refundTotal, 2) }} {{ $document->currency }}</td></tr>
        <tr><th>{{ __('Remaining Refundable') }}</th><td><strong>{{ number_format($remainingRefundable, 2) }} {{ $document->currency }}</strong></td></tr>
        <tr><th>{{ __('Reversal') }}</th><td>{{ $document->reversal ? __('Full reversal recorded') : __('None') }}</td></tr>
        <tr><th>{{ __('Fund Account') }}</th><td>{{ $receipt->fundAccount['name'] }} · {{ $receipt->fundAccount['code'] }}</td></tr>
    </tbody></table></div></div>

    @if($canCorrect)
    <div class="row">
        <div class="col-lg-6 mb-3"><div class="card cf-danger-panel"><div class="card-body"><h5>{{ __('Initiate Refund') }}</h5><p class="small text-muted">{{ __('Refund means money is actually returned to the parent/customer.') }}</p>
        @if($document->reversal)<div class="alert alert-secondary mb-0">{{ __('Refund is unavailable because this payment has been fully reversed.') }}</div>
        @elseif($remainingRefundable <= 0)<div class="alert alert-secondary mb-0">{{ __('This payment has no remaining refundable amount.') }}</div>
        @else<form method="POST" action="{{ route('central-finance.payments.refund', $document->id) }}" data-lifecycle-confirm data-lifecycle-title="{{ __('Confirm Refund') }}" data-lifecycle-notice="{{ __('Refund means money is actually returned to the parent/customer. Confirm the amount, original Fund Account, method, effective date, and reason.') }}" data-lifecycle-confirm-label="{{ __('Confirm Refund') }}" data-lifecycle-object="{{ $receipt->receipt['number'] }}" data-lifecycle-amount="{{ number_format($remainingRefundable,2) }} {{ $document->currency }}" data-lifecycle-source-destination="{{ $receipt->fundAccount['name'] }}" data-lifecycle-current-status="{{ __('Confirmed payment') }}" data-lifecycle-result="{{ __('Refund with append-only Ledger counter-entry') }}">@csrf
            <input type="hidden" name="idempotency_key" value="{{ $refundToken }}">
            <div class="form-group"><label>{{ __('Requested refund amount') }}</label><input name="amount" type="number" min="0.01" max="{{ $remainingRefundable }}" step="0.0001" class="form-control" required></div>
            <div class="form-group"><label>{{ __('Refund method') }}</label><input name="refund_method" maxlength="40" class="form-control" required></div>
            <div class="form-group"><label>{{ __('Transaction Date') }}</label><input name="effective_date" type="date" max="{{ now('Asia/Yangon')->toDateString() }}" value="{{ now('Asia/Yangon')->toDateString() }}" class="form-control" required></div>
            <div class="form-group"><label>{{ __('Reference') }}</label><input name="refund_reference" maxlength="100" class="form-control"></div>
            <div class="form-group"><label>{{ __('Reason') }}</label><textarea name="reason" maxlength="2000" class="form-control" required></textarea></div>
            <button class="btn btn-warning">{{ __('Confirm Refund') }}</button>
        </form>@endif</div></div></div>
        <div class="col-lg-6 mb-3"><div class="card cf-danger-panel"><div class="card-body"><h5>{{ __('Initiate Reversal') }}</h5><p class="small text-muted">{{ __('Reversal corrects an invalid, duplicate, or mistakenly confirmed Payment. It is not the same as physically refunding money.') }}</p>
        @if($document->reversal)<div class="alert alert-secondary mb-0">{{ __('This payment has already been reversed.') }}</div>
        @elseif($refundTotal > 0)<div class="alert alert-secondary mb-0">{{ __('Reversal is unavailable because this payment has Refund history.') }}</div>
        @else<form method="POST" action="{{ route('central-finance.payments.reversal', $document->id) }}" data-lifecycle-confirm data-lifecycle-title="{{ __('Confirm Full Reversal') }}" data-lifecycle-notice="{{ __('Reversal corrects an invalid, duplicate, or mistakenly confirmed Payment. It does not mean money is physically returned to the parent/customer.') }}" data-lifecycle-confirm-label="{{ __('Confirm Full Reversal') }}" data-lifecycle-object="{{ $receipt->receipt['number'] }}" data-lifecycle-amount="{{ number_format($document->amount,2) }} {{ $document->currency }}" data-lifecycle-source-destination="{{ $receipt->fundAccount['name'] }}" data-lifecycle-current-status="{{ __('Confirmed payment') }}" data-lifecycle-result="{{ __('Full append-only payment reversal') }}">@csrf
            <input type="hidden" name="idempotency_key" value="{{ $reversalToken }}">
            <p><strong>{{ __('Full reversal amount') }}:</strong> {{ number_format($document->amount,2) }} {{ $document->currency }}</p>
            <div class="form-group"><label>{{ __('Transaction Date') }}</label><input name="effective_date" type="date" max="{{ now('Asia/Yangon')->toDateString() }}" value="{{ now('Asia/Yangon')->toDateString() }}" class="form-control" required></div>
            <div class="form-group"><label>{{ __('Reference') }}</label><input name="reversal_reference" maxlength="100" class="form-control"></div>
            <div class="form-group"><label>{{ __('Reason') }}</label><textarea name="reason" maxlength="2000" class="form-control" required></textarea></div>
            <button class="btn btn-danger">{{ __('Confirm Full Reversal') }}</button>
        </form>@endif</div></div></div>
    </div>
    @elseif($correctionUnavailableReason)
    <div class="alert alert-secondary">{{ $correctionUnavailableReason }}</div>
    @endif
    <div class="card"><div class="card-body"><h5>{{ __('Correction history') }}</h5><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Type') }}</th><th>{{ __('Transaction Date') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Method') }}</th><th>{{ __('Actor') }}</th><th>{{ __('Reason') }}</th><th></th></tr></thead><tbody>
    @forelse($document->refunds as $refund)<tr><td>{{ __('Refund') }}</td><td>{{ $refund->effective_date?->format('Y-m-d') ?: $refund->refunded_at?->format('Y-m-d') }}</td><td>{{ number_format($refund->amount,2) }} {{ $refund->currency }}</td><td>{{ $refund->refund_method ?: '—' }}</td><td>{{ $refund->refundedBy?->full_name ?: '—' }}</td><td>{{ $refund->reason }}</td><td><a href="{{ route('central-finance.payments.refunds.show', [$document->id, $refund->id]) }}">{{ __('Details') }}</a></td></tr>@empty @if(!$document->reversal)<tr><td colspan="7" class="text-muted">{{ __('No corrections have been recorded.') }}</td></tr>@endif @endforelse
    @if($document->reversal)<tr><td>{{ __('Full reversal') }}</td><td>{{ $document->reversal->effective_date?->format('Y-m-d') }}</td><td>{{ number_format($document->reversal->amount,2) }} {{ $document->reversal->currency }}</td><td>—</td><td>{{ $document->reversal->reversedBy?->full_name ?: '—' }}</td><td>{{ $document->reversal->reason }}</td><td><a href="{{ route('central-finance.payments.reversals.show', [$document->id, $document->reversal->id]) }}">{{ __('Details') }}</a></td></tr>@endif
    </tbody></table></div></div></div>
</div>
<x-central-finance.lifecycle-confirmation />
@endsection
