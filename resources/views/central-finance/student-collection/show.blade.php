@extends('layouts.master')

@section('title', __('Student Finance'))

@section('css')
@include('central-finance.partials.foundation-styles')
@endsection

@section('content')
<div class="content-wrapper central-finance-page">
    <x-central-finance.page-header :title="$profile->student_name" :description="($profile->admission_no ?: '—').' · '.(trim($profile->class_name.' '.$profile->section_name) ?: '—')" :school="$school" :status="$cutoverStatus" :eyebrow="__('Student Finance')">
        <a class="btn btn-outline-secondary" href="{{ route('central-finance.student-collection.index') }}">{{ __('Back to students') }}</a>
    </x-central-finance.page-header>

    <div class="card mb-3"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3"><div><h5 class="mb-1">{{ __('Student Finance') }}</h5></div><a class="btn btn-sm btn-outline-primary" href="{{ route('central-finance.student-ledger', ['student' => $profile->admission_no]) }}">{{ __('Student Ledger') }}</a></div>
        <div class="row">@forelse($profile->currency_totals as $currency => $total)<div class="col-md-4 mb-2"><div class="cf-summary-card"><span class="cf-summary-card__label">{{ $currency }}</span><span class="cf-summary-card__value">{{ __('Due') }} {{ number_format($total['due'],2) }}</span><span class="d-block text-muted small">{{ __('Paid') }} {{ number_format($total['paid'],2) }} · {{ __('Outstanding') }} {{ number_format($total['outstanding'],2) }}</span></div></div>@empty<div class="col-12"><div class="cf-empty-state">{{ __('No Central Receivables are available for this student.') }}</div></div>@endforelse</div>
    </div></div>

    <div class="card mb-3"><div class="card-body"><div class="d-flex flex-wrap justify-content-between align-items-center mb-3"><div><h5 class="mb-1">{{ __('Receivable items') }}</h5></div></div>
        <div class="table-responsive"><table class="table cf-data-table cf-mobile-card-table mb-0"><thead><tr><th>{{ __('Description') }}</th><th>{{ __('Due') }}</th><th>{{ __('Paid') }}</th><th>{{ __('Outstanding') }}</th><th>{{ __('Status') }}</th><th>{{ __('Action') }}</th></tr></thead><tbody>@forelse($profile->receivables as $receivable)@php($outstanding = max(0, $receivable->amount_due - $receivable->amount_paid))<tr><td data-label="{{ __('Description') }}"><span class="cf-primary-line">{{ $receivable->description }}</span><span class="cf-secondary-line">{{ $receivable->due_date?->format('Y-m-d') ?: '—' }}</span></td><td data-label="{{ __('Due') }}">{{ number_format($receivable->amount_due,2) }} {{ $receivable->currency }}</td><td data-label="{{ __('Paid') }}">{{ number_format($receivable->amount_paid,2) }} {{ $receivable->currency }}</td><td data-label="{{ __('Outstanding') }}"><strong>{{ number_format($outstanding,2) }} {{ $receivable->currency }}</strong></td><td data-label="{{ __('Status') }}"><span class="badge cf-status-badge badge-light">{{ __($receivable->status) }}</span></td><td data-label="">@if($canCollect && in_array($receivable->status, ['open','partial'], true))<a class="btn btn-sm cf-primary-action" href="{{ route('central-finance.student-collection.review', [$profile->id, $receivable->id]) }}">{{ __('Collect') }}</a>@else<span class="text-muted small">{{ __('View only') }}</span>@endif</td></tr>@empty<tr><td colspan="6"><div class="cf-empty-state">{{ __('No Central Receivables are available for this student.') }}</div></td></tr>@endforelse</tbody></table></div>
    </div></div>

    <div class="card"><div class="card-body"><div class="d-flex flex-wrap justify-content-between align-items-center mb-3"><div><h5 class="mb-1">{{ __('Payment / Receipt history') }}</h5></div></div><div class="table-responsive"><table class="table cf-data-table cf-mobile-card-table mb-0"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Receivable') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Fund Account') }}</th><th>{{ __('Receipt') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>
        @php($hasPayments = false)
        @foreach($profile->receivables as $receivable)
            @foreach($receivable->payments as $payment)
                @php($hasPayments = true)
                <tr><td data-label="{{ __('Date') }}">{{ $payment->paid_at?->format('Y-m-d H:i') }}</td><td data-label="{{ __('Receivable') }}"><span class="cf-primary-line">{{ $receivable->description }}</span></td><td data-label="{{ __('Amount') }}">{{ number_format($payment->amount,2) }} {{ $payment->currency }}</td><td data-label="{{ __('Fund Account') }}"><span class="cf-primary-line">{{ $payment->fundAccount?->account_name ?: '—' }}</span></td><td data-label="{{ __('Receipt') }}">@if($payment->receipt)<a href="{{ route('central-finance.payments.receipt', $payment->id) }}">{{ $payment->receipt->receipt_no }}</a>@else — @endif</td><td data-label="{{ __('Status') }}">{{ $payment->refunds->isEmpty() ? __('Collected') : __('Refund status available') }}</td></tr>
            @endforeach
        @endforeach
        @if(!$hasPayments)
            <tr><td colspan="6"><div class="cf-empty-state">{{ __('No payments have been recorded.') }}</div></td></tr>
        @endif
    </tbody></table></div></div></div>
</div>

@endsection
