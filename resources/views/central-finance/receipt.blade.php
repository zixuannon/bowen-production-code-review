@extends('layouts.master')

@section('title', __('Receipt'))

@section('css')
<style>
    .central-receipt { max-width: 840px; margin: 0 auto; }
    .central-receipt__number { font-size: 1.25rem; font-weight: 700; letter-spacing: .04em; }
    @media print {
        .sidebar, .header, .footer, .page-header, .btn, .central-receipt__actions { display: none !important; }
        .content-wrapper { margin: 0 !important; padding: 0 !important; }
        .central-receipt { max-width: none; border: 0 !important; box-shadow: none !important; }
    }
</style>
@endsection

@section('content')
<div class="content-wrapper">
    <div class="central-receipt card">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div>
                    <div class="text-muted small">{{ __('Central Finance Receipt') }}</div>
                    <div class="central-receipt__number">{{ $document->receipt?->receipt_no ?? __('Receipt pending') }}</div>
                </div>
                <div class="text-right text-muted small">
                    <div>{{ $school->name }}</div>
                    <div>{{ $document->paid_at?->format('Y-m-d H:i') }}</div>
                </div>
            </div>

            <table class="table table-bordered">
                <tbody>
                    <tr><th style="width:35%">{{ __('Student') }}</th><td>{{ $document->receivable?->studentProfile?->student_name }}</td></tr>
                    <tr><th>{{ __('Student Code') }}</th><td>{{ $document->receivable?->studentProfile?->admission_no ?: '—' }}</td></tr>
                    <tr><th>{{ __('Receivable') }}</th><td>{{ $document->receivable?->description }}</td></tr>
                    <tr><th>{{ __('Fund Account') }}</th><td>{{ $document->fundAccount?->account_name }}@if($document->fundAccount?->account_code) · {{ $document->fundAccount->account_code }}@endif</td></tr>
                    <tr><th>{{ __('Payment method') }}</th><td>{{ $document->payment_method }}</td></tr>
                    <tr><th>{{ __('Payment reference') }}</th><td>{{ $document->payment_reference ?: '—' }}</td></tr>
                    <tr><th>{{ __('Amount received') }}</th><td class="font-weight-bold">{{ number_format($document->amount, 2) }} {{ $document->currency }}</td></tr>
                </tbody>
            </table>

            @if($document->refunds->isNotEmpty())
                <h6 class="mt-4">{{ __('Refund / reversal history') }}</h6>
                <div class="table-responsive"><table class="table table-sm">
                    <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Reference') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Reason') }}</th></tr></thead>
                    <tbody>@foreach($document->refunds as $refund)<tr><td>{{ $refund->refunded_at?->format('Y-m-d H:i') }}</td><td>{{ $refund->refund_reference ?: '—' }}</td><td>{{ number_format($refund->amount, 2) }} {{ $refund->currency }}</td><td>{{ $refund->reason }}</td></tr>@endforeach</tbody>
                </table></div>
            @endif

            <h6 class="mt-4">{{ __('Audit timeline') }}</h6>
            <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('When') }}</th><th>{{ __('Action') }}</th><th>{{ __('Reason') }}</th></tr></thead><tbody>@forelse($audits as $audit)<tr><td>{{ $audit->created_at?->format('Y-m-d H:i') }}</td><td>{{ $audit->action }}</td><td>{{ $audit->reason ?: __('System recorded') }}</td></tr>@empty<tr><td colspan="3" class="text-muted">{{ __('Historical audit entries were not available for this receipt.') }}</td></tr>@endforelse</tbody></table></div>

            <div class="central-receipt__actions d-flex justify-content-between mt-4">
                <a class="btn btn-outline-secondary" href="{{ route('central-finance.payments.index') }}">{{ __('Back to payment history') }}</a>
                <button type="button" class="btn btn-theme" onclick="window.print()">{{ __('Print receipt') }}</button>
            </div>
        </div>
    </div>
</div>
@endsection
