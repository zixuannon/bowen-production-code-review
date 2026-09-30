@extends('layouts.master')

@section('title', $schoolFinanceFacade ? __('School Finance') : __('Student Finance'))

@section('css')
@include('central-finance.partials.foundation-styles')
@endsection

@section('content')
<div class="content-wrapper central-finance-page">
    <x-central-finance.page-header :title="$profile->student_name" :description="__('Student Code').': '.($profile->student_code ?: '—').' · '.__('Gr Number').': '.($profile->admission_no ?: '—').' · '.(trim($profile->class_name.' '.$profile->section_name) ?: '—')" :school="$school" :status="$cutoverStatus" :eyebrow="__('Student Finance')" :school-finance-facade="$schoolFinanceFacade">
        <a class="btn btn-outline-secondary" href="{{ route('central-finance.student-collection.index') }}">{{ __('Back to students') }}</a>
    </x-central-finance.page-header>

    <div class="card mb-3"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3"><div><h5 class="mb-1">{{ __('Student Finance') }}</h5></div><a class="btn btn-sm btn-outline-primary" href="{{ route('central-finance.student-ledger', ['student' => $profile->admission_no]) }}">{{ __('Student Ledger') }}</a></div>
        <div class="row">@forelse($profile->currency_totals as $currency => $total)<div class="col-md-4 mb-2"><div class="cf-summary-card"><span class="cf-summary-card__label">{{ $currency }}</span><span class="cf-summary-card__value">{{ __('Due') }} {{ number_format($total['due'],2) }}</span><span class="d-block text-muted small">{{ __('Confirmed paid') }} {{ number_format($total['paid'],2) }}</span><span class="d-block text-warning small">{{ __('Pending finance confirmation') }} {{ number_format($total['pending_confirmation'],2) }}</span><span class="d-block text-muted small">{{ __('Official outstanding') }} {{ number_format($total['outstanding'],2) }}</span><strong class="d-block text-info small">{{ __('Available to collect') }} {{ number_format($total['available_to_collect'],2) }}</strong></div></div>@empty<div class="col-12"><div class="cf-empty-state">{{ __('No Central Receivables are available for this student.') }}</div></div>@endforelse</div>
    </div></div>

    <div class="card mb-3" id="receivable-items"><div class="card-body"><div class="d-flex flex-wrap justify-content-between align-items-center mb-3"><div><h5 class="mb-1">{{ __('Receivable items') }}</h5><p class="small text-muted mb-0">{{ __('Gross, Promotion, Net, Paid, and Outstanding are shown separately. Select one or more receivables to declare one allocated collection.') }}</p></div><div class="d-flex flex-wrap gap-2">
        @if($canSubmitPending)
            <button class="btn btn-sm cf-primary-action" form="multi-receivable-collection" type="submit">{{ __('Collect selected') }}</button>
        @endif
        @if(($canCollect || $canSubmitPending) && $optionalItems->isNotEmpty())
            <button type="button" class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#optional-fee-modal">{{ __('+ Add Item') }}</button>
        @endif
    </div></div>
        @if($canSubmitPending)<form id="multi-receivable-collection" method="GET" action="{{ route('central-finance.pending-collections.review-multiple', $profile->id) }}"></form>@endif
        <div class="table-responsive"><table class="table cf-data-table cf-mobile-card-table mb-0"><thead><tr>@if($canSubmitPending)<th>{{ __('Select') }}</th>@endif<th>{{ __('Description') }}</th><th>{{ __('Gross') }}</th><th>{{ __('Promotion') }}</th><th>{{ __('Net due') }}</th><th>{{ __('Confirmed paid') }}</th><th>{{ __('Pending finance confirmation') }}</th><th>{{ __('Outstanding') }}</th><th>{{ __('Available to collect') }}</th><th>{{ __('Status') }}</th><th>{{ __('Action') }}</th></tr></thead><tbody>@forelse($profile->receivables as $receivable)@php($gross = \App\Support\CentralFinanceDecimal::normalize((string) ($receivable->source_amount_due ?? $receivable->amount_due)))@php($promotion = \App\Support\CentralFinanceDecimal::max(\App\Support\CentralFinanceDecimal::subtract($gross, (string) $receivable->amount_due), '0'))@php($outstanding = \App\Support\CentralFinanceDecimal::max(\App\Support\CentralFinanceDecimal::subtract((string) $receivable->amount_due, (string) $receivable->amount_paid), '0'))@php($pending = \App\Support\CentralFinanceDecimal::max((string) ($receivable->pending_confirmation_amount ?? '0'), '0'))@php($available = \App\Support\CentralFinanceDecimal::max(\App\Support\CentralFinanceDecimal::subtract($outstanding, $pending), '0'))<tr>@if($canSubmitPending)<td data-label="{{ __('Select') }}">@if(in_array($receivable->status, ['open','partial'], true) && \App\Support\CentralFinanceDecimal::compare($available, '0') > 0)<input form="multi-receivable-collection" type="checkbox" name="receivable_ids[]" value="{{ $receivable->id }}" aria-label="{{ __('Select') }} {{ $receivable->description }}">@endif</td>@endif<td data-label="{{ __('Description') }}"><span class="cf-primary-line">{{ $receivable->description }}</span><span class="cf-secondary-line">{{ $receivable->due_date?->format('Y-m-d') ?: '—' }} · {{ __('Qty') }} {{ $receivable->quantity_snapshot ?: 1 }}</span></td><td data-label="{{ __('Gross') }}">{{ number_format((float) $gross,2) }} {{ $receivable->currency }}</td><td data-label="{{ __('Promotion') }}">{{ number_format((float) $promotion,2) }} {{ $receivable->currency }}</td><td data-label="{{ __('Net due') }}">{{ number_format((float) $receivable->amount_due,2) }} {{ $receivable->currency }}</td><td data-label="{{ __('Confirmed paid') }}">{{ number_format((float) $receivable->amount_paid,2) }} {{ $receivable->currency }}</td><td data-label="{{ __('Pending finance confirmation') }}"><span class="text-warning">{{ number_format((float) $pending,2) }} {{ $receivable->currency }}</span></td><td data-label="{{ __('Outstanding') }}"><strong>{{ number_format((float) $outstanding,2) }} {{ $receivable->currency }}</strong></td><td data-label="{{ __('Available to collect') }}"><strong class="text-info">{{ number_format((float) $available,2) }} {{ $receivable->currency }}</strong></td><td data-label="{{ __('Status') }}"><span class="badge cf-status-badge badge-light">{{ __($receivable->status) }}</span></td><td data-label="">@if($canSubmitPending && in_array($receivable->status, ['open','partial'], true))<a class="btn btn-sm btn-outline-primary" href="{{ route('central-finance.pending-collections.review', [$profile->id, $receivable->id]) }}">{{ __('Collect one') }}</a>@elseif($canCollect && in_array($receivable->status, ['open','partial'], true))<a class="btn btn-sm btn-outline-primary" href="{{ route('central-finance.pending-collections.index') }}">{{ __('Review pending collections') }}</a>@else<span class="text-muted small">{{ __('View only') }}</span>@endif</td></tr>@empty<tr><td colspan="11"><div class="cf-empty-state">{{ __('No Central Receivables are available for this student.') }}</div></td></tr>@endforelse</tbody></table></div>
    </div></div>

    @if(($canCollect || $canSubmitPending) && $optionalItems->isNotEmpty() && $optionalAttemptUuid)
    <div class="modal fade" id="optional-fee-modal" tabindex="-1" role="dialog" aria-labelledby="optional-fee-modal-title" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document"><form method="POST" action="{{ route('central-finance.student-collection.optional-items.store', $profile->id) }}" class="modal-content">
            @csrf<input type="hidden" name="optional_attempt_uuid" value="{{ $optionalAttemptUuid }}">
            <div class="modal-header"><div><h5 class="modal-title" id="optional-fee-modal-title">{{ __('Student Fee Setup') }}</h5><p class="small text-muted mb-0">{{ __('Select approved optional items, quantity where the item allows it, and only an already-approved applicable Promotion.') }}</p></div><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Close') }}"><span aria-hidden="true">&times;</span></button></div>
            <div class="modal-body"><div class="table-responsive"><table class="table cf-data-table cf-mobile-card-table mb-0"><thead><tr><th>{{ __('Item') }}</th><th>{{ __('Unit price') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Approved Promotion') }}</th><th>{{ __('Select') }}</th></tr></thead><tbody>
                @foreach($optionalItems as $item)<tr>
                    <td data-label="{{ __('Item') }}"><span class="cf-primary-line">{{ $item->name }}</span><span class="cf-secondary-line">{{ $item->fee_type ?: '—' }} · {{ $item->academic_year }}</span></td>
                    <td data-label="{{ __('Unit price') }}"><strong>{{ number_format($item->amount, 2) }} {{ $item->currency }}</strong></td>
                    <td data-label="{{ __('Quantity') }}"><input class="form-control form-control-sm" name="optional_fee_quantities[{{ $item->id }}]" type="number" min="1" max="{{ app(\App\Services\StudentFeeAssignmentService::class)->maxQuantity() }}" step="1" value="1" @disabled(!$item->quantity_enabled)><small class="text-muted">{{ $item->quantity_enabled ? __('Quantity based') : __('Fixed at 1') }}</small></td>
                    <td data-label="{{ __('Approved Promotion') }}"><select class="form-control form-control-sm" name="promotions[{{ $item->id }}]"><option value="">{{ __('No Promotion') }}</option>@foreach($item->promotions as $promotion)<option value="{{ $promotion->id }}">{{ $promotion->code }} · {{ $promotion->name }}</option>@endforeach</select></td>
                    <td data-label="{{ __('Select') }}"><div class="form-check"><input class="form-check-input" type="checkbox" name="optional_fee_ids[]" value="{{ $item->id }}" id="optional-item-{{ $item->id }}"><label class="form-check-label sr-only" for="optional-item-{{ $item->id }}">{{ __('Select') }} {{ $item->name }}</label></div></td>
                </tr>@endforeach
            </tbody></table></div></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-dismiss="modal">{{ __('Cancel') }}</button><button type="submit" class="btn cf-primary-action">{{ __('Preview and add selected items') }}</button></div>
        </form></div>
    </div>
    @endif

    <div class="card" id="payment-history"><div class="card-body"><div class="d-flex flex-wrap justify-content-between align-items-center mb-3"><div><h5 class="mb-1">{{ __('Payment / Receipt history') }}</h5></div></div><div class="table-responsive"><table class="table cf-data-table cf-mobile-card-table mb-0"><thead><tr><th>{{ __('Payment Effective Date') }}</th><th>{{ __('Receivable') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Payment method') }}</th><th>{{ __('Received by') }}</th><th>{{ __('Fund Account') }}</th><th>{{ __('Receipt') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>
        @php($hasPayments = false)
        @foreach($profile->receivables as $receivable)
            @php($settlements = $receivable->relationLoaded('paymentAllocations') ? $receivable->paymentAllocations->map(fn ($allocation) => (object) ['payment' => $allocation->payment, 'amount' => $allocation->amount]) : $receivable->payments->map(fn ($payment) => (object) ['payment' => $payment, 'amount' => $payment->amount]))
            @foreach($settlements as $settlement)
                @php($payment = $settlement->payment)
                @if($payment)@php($hasPayments = true)
                <tr><td data-label="{{ __('Payment Effective Date') }}">{{ $payment->paid_at?->format('Y-m-d H:i') }}</td><td data-label="{{ __('Receivable') }}"><span class="cf-primary-line">{{ $receivable->description }}</span></td><td data-label="{{ __('Amount') }}">{{ number_format($settlement->amount,2) }} {{ $payment->currency }}</td><td data-label="{{ __('Payment method') }}">{{ $payment->payment_method }}</td><td data-label="{{ __('Received by') }}">{{ $payment->receivedBy?->full_name ?: '—' }}</td><td data-label="{{ __('Fund Account') }}"><span class="cf-primary-line">{{ $payment->fundAccount?->account_name ?: '—' }}</span></td><td data-label="{{ __('Receipt') }}">@if($payment->receipt)<a href="{{ route('central-finance.payments.receipt', $payment->id) }}">{{ $payment->receipt->receipt_no }}</a>@else — @endif</td><td data-label="{{ __('Status') }}">{{ $payment->refunds->isEmpty() ? __('Collected') : __('Refund status available') }}</td></tr>
                @endif
            @endforeach
        @endforeach
        @if(!$hasPayments)
            <tr><td colspan="8"><div class="cf-empty-state">{{ __('No payments have been recorded.') }}</div></td></tr>
        @endif
    </tbody></table></div></div></div>
</div>

@endsection
