@extends('layouts.master')

@section('title', __('Payment Refund / Reversal'))

@section('content')
<div class="content-wrapper"><div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start"><div><h4 class="mb-1">{{ __('Payment Refund / Reversal') }}</h4><p class="text-muted mb-3">{{ __('The original receipt remains immutable. This record is its append-only correction.') }}</p></div><a class="btn btn-outline-secondary" href="{{ route('central-finance.payments.receipt', $receipt->paymentId) }}">{{ __('Back to receipt') }}</a></div>
    <table class="table table-bordered"><tbody><tr><th style="width:35%">{{ __('Original receipt') }}</th><td>{{ $receipt->receipt['number'] }}</td></tr><tr><th>{{ __('Refund date') }}</th><td>{{ $refundDocument->refunded_at?->format('Y-m-d H:i') }}</td></tr><tr><th>{{ __('Refund reference') }}</th><td>{{ $refundDocument->refund_reference ?: '—' }}</td></tr><tr><th>{{ __('Refund amount') }}</th><td>{{ number_format($refundDocument->amount,2) }} {{ $refundDocument->currency }}</td></tr><tr><th>{{ __('Reason') }}</th><td>{{ $refundDocument->reason }}</td></tr></tbody></table>
    <h6>{{ __('Audit timeline') }}</h6><div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('When') }}</th><th>{{ __('Action') }}</th><th>{{ __('Reason') }}</th></tr></thead><tbody>@forelse($audits as $audit)<tr><td>{{ $audit->created_at?->format('Y-m-d H:i') }}</td><td>{{ $audit->action }}</td><td>{{ $audit->reason ?: '—' }}</td></tr>@empty<tr><td colspan="3" class="text-muted">{{ __('No audit entry is available.') }}</td></tr>@endforelse</tbody></table></div>
</div></div></div>
@endsection
