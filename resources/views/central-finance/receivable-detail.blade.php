@extends('layouts.master')

@section('title', __('Receivable details'))

@section('css')
@include('central-finance.partials.foundation-styles')
@endsection

@section('content')
<div class="content-wrapper central-finance-page">
    <x-central-finance.page-header :title="__('Receivable details')" :description="__('Review the immutable source, payment history, and any audited Central Finance corrections.')" :school="$school" eyebrow="{{ __('学生收费') }}">
        <a class="btn btn-outline-secondary" href="{{ route('central-finance.receivables') }}">{{ __('Back to receivables') }}</a>
    </x-central-finance.page-header>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="row">
        <div class="col-lg-7 mb-3">
            <div class="card"><div class="card-body">
                <h5>{{ $document->studentProfile?->student_name }}</h5>
                <p class="text-muted">{{ $document->studentProfile?->admission_no ?: '—' }} · {{ $document->description }}</p>
                <div class="row text-center">
                    <div class="col-6 col-md-4 col-xl mb-2"><small class="text-muted d-block">{{ __('Gross') }}</small><strong>{{ number_format($document->source_amount_due ?? $document->amount_due, 2) }}</strong></div>
                    <div class="col-6 col-md-4 col-xl mb-2"><small class="text-muted d-block">{{ __('Promotion') }}</small><strong>{{ number_format(abs((float) $lifecycleTotals['promotion']), 2) }}</strong></div>
                    <div class="col-6 col-md-4 col-xl mb-2"><small class="text-muted d-block">{{ __('Waiver') }}</small><strong>{{ number_format(abs((float) $lifecycleTotals['waiver']), 2) }}</strong></div>
                    <div class="col-6 col-md-4 col-xl mb-2"><small class="text-muted d-block">{{ __('Correction') }}</small><strong>{{ number_format($lifecycleTotals['correction'], 2) }}</strong></div>
                    <div class="col-6 col-md-4 col-xl mb-2"><small class="text-muted d-block">{{ __('Net due') }}</small><strong>{{ number_format($document->amount_due, 2) }}</strong></div>
                    <div class="col-6 col-md-4 col-xl mb-2"><small class="text-muted d-block">{{ __('Paid') }}</small><strong>{{ number_format($document->amount_paid, 2) }}</strong></div>
                    <div class="col-12 col-md-4 col-xl mb-2"><small class="text-muted d-block">{{ __('Outstanding') }}</small><strong>{{ number_format($document->amount_due - $document->amount_paid, 2) }} {{ $document->currency }}</strong></div>
                </div>
                <hr>
                <dl class="row mb-0"><dt class="col-sm-4">{{ __('Status') }}</dt><dd class="col-sm-8">{{ __($document->status) }}</dd><dt class="col-sm-4">{{ __('Due date') }}</dt><dd class="col-sm-8">{{ $document->due_date?->format('Y-m-d') ?: __('no_due_date') }}</dd><dt class="col-sm-4">{{ __('Tenant source') }}</dt><dd class="col-sm-8">{{ $document->source_type }} #{{ $document->source_id }}</dd></dl>
            </div></div>
        </div>
        <div class="col-lg-5 mb-3">
            <div class="card cf-danger-panel"><div class="card-body">
                <h5>{{ __('Receivable lifecycle') }}</h5>
                <p class="small text-muted">{{ __('Every action is append-only. It never changes a Payment, Receipt, Ledger entry, or Fund Account balance.') }}</p>
                <div class="cf-history-notice mb-3">{{ __('Review the receivable and history above before confirming a correction. Confirmed corrections remain visible in the audit trail and do not edit historical payments.') }}</div>
                @if(!$canOperate)
                    <div class="alert alert-secondary mb-0">{{ __('This Central Finance workspace is read-only for your current School and cutover status.') }}</div>
                @elseif(!in_array($document->status, [\App\Models\CentralFinanceReceivable::CANCELLED, \App\Models\CentralFinanceReceivable::VOIDED], true))
                    @if($eligiblePromotions->isNotEmpty() && !$document->promotionApplication && $document->adjustments->isEmpty())
                    <form class="mb-3" method="POST" action="{{ route('central-finance.receivables.promotions.store', $document->id) }}">@csrf<input type="hidden" name="idempotency_key" value="{{ $adjustmentIdempotencyKey }}-promotion"><strong>{{ __('Apply promotion') }}</strong><select name="promotion_id" class="form-control my-2" required>@foreach($eligiblePromotions as $promotion)<option value="{{ $promotion->id }}">{{ $promotion->code }} — {{ $promotion->name }}</option>@endforeach</select><input type="date" name="effective_date" class="form-control mb-2" value="{{ now('Asia/Yangon')->toDateString() }}" required><textarea name="reason" class="form-control mb-2" maxlength="2000" placeholder="{{ __('Reason / note') }}"></textarea><button class="btn btn-primary">{{ __('Apply promotion') }}</button></form>
                    @endif
                    <form class="mb-3" method="POST" action="{{ route('central-finance.receivables.corrections.store', $document->id) }}">@csrf<input type="hidden" name="idempotency_key" value="{{ $adjustmentIdempotencyKey }}-correction"><strong>{{ __('Correction') }}</strong><input name="amount_delta" type="text" inputmode="decimal" class="form-control my-2" placeholder="+1000.0000 or -1000.0000" required><input type="date" name="effective_date" class="form-control mb-2" value="{{ now('Asia/Yangon')->toDateString() }}" required><textarea name="reason" class="form-control mb-2" maxlength="2000" placeholder="{{ __('Reason') }}" required></textarea><button class="btn btn-warning">{{ __('Record correction') }}</button></form>
                    <form class="mb-3" method="POST" action="{{ route('central-finance.receivables.waivers.store', $document->id) }}">@csrf<input type="hidden" name="idempotency_key" value="{{ $adjustmentIdempotencyKey }}-waiver"><strong>{{ __('Waive outstanding amount') }}</strong><input name="amount" type="text" inputmode="decimal" class="form-control my-2" required><input type="date" name="effective_date" class="form-control mb-2" value="{{ now('Asia/Yangon')->toDateString() }}" required><textarea name="reason" class="form-control mb-2" maxlength="2000" placeholder="{{ __('Reason') }}" required></textarea><button class="btn btn-warning">{{ __('Record waiver') }}</button></form>
                    <form method="POST" action="{{ route('central-finance.receivables.void.store', $document->id) }}">@csrf<input type="hidden" name="idempotency_key" value="{{ $adjustmentIdempotencyKey }}-void"><strong>{{ __('Void unpaid receivable') }}</strong><input type="date" name="effective_date" class="form-control my-2" value="{{ now('Asia/Yangon')->toDateString() }}" required><textarea name="reason" class="form-control mb-2" maxlength="2000" placeholder="{{ __('Reason') }}" required></textarea><button class="btn btn-danger">{{ __('Void unpaid receivable') }}</button></form>
                @else
                    <div class="alert alert-secondary mb-0">{{ __('This receivable is voided. Its history remains available below.') }}</div>
                @endif
            </div></div>
        </div>
    </div>

    <div class="card mb-3"><div class="card-body"><h5>{{ __('Payment and receipt history') }}</h5><div class="table-responsive"><table class="table mb-0"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Receipt') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Refunded') }}</th><th></th></tr></thead><tbody>@forelse($document->payments as $payment)<tr><td>{{ $payment->paid_at?->format('Y-m-d H:i') }}</td><td>{{ $payment->receipt?->receipt_no ?: '—' }}</td><td>{{ number_format($payment->amount, 2) }} {{ $payment->currency }}</td><td>{{ number_format($payment->refunds->sum('amount'), 2) }} {{ $payment->currency }}</td><td><a class="btn btn-sm btn-outline-secondary" href="{{ route('central-finance.payments.receipt', $payment->id) }}">{{ __('Receipt') }}</a></td></tr>@empty<tr><td colspan="5" class="text-center text-muted">{{ __('No payments have been recorded.') }}</td></tr>@endforelse</tbody></table></div></div></div>
    <div class="card mb-3"><div class="card-body"><h5>{{ __('Adjustment audit history') }}</h5><div class="table-responsive"><table class="table mb-0"><thead><tr><th>{{ __('Effective date') }}</th><th>{{ __('Recorded at') }}</th><th>{{ __('Action') }}</th><th>{{ __('Before / after') }}</th><th>{{ __('Reason') }}</th></tr></thead><tbody>@forelse($adjustments as $adjustment)<tr><td>{{ $adjustment->effective_date?->format('Y-m-d') ?: '—' }}</td><td>{{ $adjustment->adjusted_at?->format('Y-m-d H:i') }}</td><td>{{ __($adjustment->adjustment_type) }}</td><td>{{ $adjustment->amount_before !== null ? number_format($adjustment->amount_before, 2) : '—' }} → {{ $adjustment->amount_after !== null ? number_format($adjustment->amount_after, 2) : '—' }} {{ $document->currency }}</td><td>{{ $adjustment->reason }}</td></tr>@empty<tr><td colspan="5" class="text-center text-muted">{{ __('No adjustments have been recorded.') }}</td></tr>@endforelse</tbody></table></div></div></div>
    <div class="card"><div class="card-body"><h5>{{ __('Audit timeline') }}</h5><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('When') }}</th><th>{{ __('Action') }}</th><th>{{ __('Reason') }}</th></tr></thead><tbody>@forelse($audits as $audit)<tr><td>{{ $audit->created_at?->format('Y-m-d H:i') }}</td><td>{{ __($audit->action) }}</td><td>{{ $audit->reason }}</td></tr>@empty<tr><td colspan="3" class="text-muted">{{ __('No finance adjustment audit history found.') }}</td></tr>@endforelse</tbody></table></div></div></div>
</div>
<x-central-finance.lifecycle-confirmation />
@endsection
