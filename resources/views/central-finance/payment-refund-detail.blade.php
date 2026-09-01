@extends('layouts.master')

@section('title', __('Payment Refund / Reversal'))

@section('css')
@include('central-finance.partials.foundation-styles')
@endsection

@section('content')
<div class="content-wrapper central-finance-page"><x-central-finance.page-header :title="__('Payment Refund / Reversal')" :description="__('The original receipt remains immutable. This is its append-only correction record.')" :school="$school" eyebrow="{{ __('学生收费') }}"><a class="btn btn-outline-secondary" href="{{ route('central-finance.payments.receipt', $receipt->paymentId) }}">{{ __('Back to receipt') }}</a></x-central-finance.page-header><div class="card cf-danger-panel"><div class="card-body">
    <div class="cf-history-notice mb-3">{{ __('Historical payments and receipts cannot be edited here. This correction remains linked to the original receipt and Ledger history.') }}</div>
    <table class="table table-bordered cf-data-table"><tbody><tr><th style="width:35%">{{ __('Original receipt') }}</th><td>{{ $receipt->receipt['number'] }}</td></tr><tr><th>{{ __('Refund date') }}</th><td>{{ $refundDocument->refunded_at?->format('Y-m-d H:i') }}</td></tr><tr><th>{{ __('Refund reference') }}</th><td>{{ $refundDocument->refund_reference ?: '—' }}</td></tr><tr><th>{{ __('Refund amount') }}</th><td>{{ number_format($refundDocument->amount,2) }} {{ $refundDocument->currency }}</td></tr><tr><th>{{ __('Reason') }}</th><td class="cf-break-anywhere">{{ $refundDocument->reason }}</td></tr></tbody></table>
    <h6>{{ __('Audit timeline') }}</h6><div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('When') }}</th><th>{{ __('Action') }}</th><th>{{ __('Reason') }}</th></tr></thead><tbody>@forelse($audits as $audit)<tr><td>{{ $audit->created_at?->format('Y-m-d H:i') }}</td><td>{{ __($audit->action) }}</td><td>{{ $audit->reason ?: '—' }}</td></tr>@empty<tr><td colspan="3" class="text-muted">{{ __('No audit entry is available.') }}</td></tr>@endforelse</tbody></table></div>
</div></div></div>
@endsection
