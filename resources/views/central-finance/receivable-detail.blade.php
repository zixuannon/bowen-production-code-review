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
                    <div class="col-4"><small class="text-muted d-block">{{ __('Due') }}</small><strong>{{ number_format($document->amount_due, 2) }}</strong></div>
                    <div class="col-4"><small class="text-muted d-block">{{ __('Paid') }}</small><strong>{{ number_format($document->amount_paid, 2) }}</strong></div>
                    <div class="col-4"><small class="text-muted d-block">{{ __('Outstanding') }}</small><strong>{{ number_format($document->amount_due - $document->amount_paid, 2) }} {{ $document->currency }}</strong></div>
                </div>
                <hr>
                <dl class="row mb-0"><dt class="col-sm-4">{{ __('Status') }}</dt><dd class="col-sm-8">{{ __($document->status) }}</dd><dt class="col-sm-4">{{ __('Due date') }}</dt><dd class="col-sm-8">{{ $document->due_date?->format('Y-m-d') ?: '—' }}</dd><dt class="col-sm-4">{{ __('Tenant source') }}</dt><dd class="col-sm-8">{{ $document->source_type }} #{{ $document->source_id }}</dd></dl>
            </div></div>
        </div>
        <div class="col-lg-5 mb-3">
            <div class="card cf-danger-panel"><div class="card-body">
                <h5>{{ __('Adjustment / Waiver / Void') }}</h5>
                <p class="small text-muted">{{ __('Changes are append-only audit records. They never alter a tenant Fee Assignment and cannot reduce the receivable below amounts already collected.') }}</p>
                <div class="cf-history-notice mb-3">{{ __('Review the receivable and history above before confirming a correction. Confirmed corrections remain visible in the audit trail and do not edit historical payments.') }}</div>
                @if(!$canOperate)
                    <div class="alert alert-secondary mb-0">{{ __('This Central Finance workspace is read-only for your current School and cutover status.') }}</div>
                @elseif($document->status !== \App\Models\CentralFinanceReceivable::CANCELLED)
                    <form method="POST" action="{{ route('central-finance.receivables.adjustments.store', $document->id) }}">@csrf
                        <input type="hidden" name="idempotency_key" value="{{ $adjustmentIdempotencyKey }}">
                        <div class="form-group"><label>{{ __('Action') }}</label><select name="type" class="form-control" id="central-receivable-adjustment-type" required><option value="adjustment">{{ __('Amount adjustment') }}</option><option value="discount">{{ __('Discount') }}</option><option value="waiver">{{ __('Waiver') }}</option><option value="void">{{ __('Void unpaid receivable') }}</option></select></div>
                        <div class="form-group" id="central-receivable-adjustment-amount"><label>{{ __('Amount delta') }}</label><input name="amount_delta" type="number" step="0.01" class="form-control" placeholder="{{ __('Use + to increase; − to reduce') }}" required><small class="form-text text-muted">{{ __('Discount and waiver must be negative. Void calculates the required reduction itself.') }}</small></div>
                        <div class="form-group"><label>{{ __('Reason') }}</label><textarea name="reason" class="form-control" maxlength="2000" required></textarea></div>
                        <button class="btn btn-warning">{{ __('Record audited adjustment') }}</button>
                    </form>
                    <script>document.addEventListener('DOMContentLoaded',function(){const type=document.getElementById('central-receivable-adjustment-type'),amount=document.getElementById('central-receivable-adjustment-amount');if(!type||!amount)return;type.addEventListener('change',function(){const isVoid=this.value==='void';amount.classList.toggle('d-none',isVoid);amount.querySelector('input').required=!isVoid;});});</script>
                @else
                    <div class="alert alert-secondary mb-0">{{ __('This receivable is voided. Its history remains available below.') }}</div>
                @endif
            </div></div>
        </div>
    </div>

    <div class="card mb-3"><div class="card-body"><h5>{{ __('Payment and receipt history') }}</h5><div class="table-responsive"><table class="table mb-0"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Receipt') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Refunded') }}</th><th></th></tr></thead><tbody>@forelse($document->payments as $payment)<tr><td>{{ $payment->paid_at?->format('Y-m-d H:i') }}</td><td>{{ $payment->receipt?->receipt_no ?: '—' }}</td><td>{{ number_format($payment->amount, 2) }} {{ $payment->currency }}</td><td>{{ number_format($payment->refunds->sum('amount'), 2) }} {{ $payment->currency }}</td><td><a class="btn btn-sm btn-outline-secondary" href="{{ route('central-finance.payments.receipt', $payment->id) }}">{{ __('Receipt') }}</a></td></tr>@empty<tr><td colspan="5" class="text-center text-muted">{{ __('No payments have been recorded.') }}</td></tr>@endforelse</tbody></table></div></div></div>
    <div class="card mb-3"><div class="card-body"><h5>{{ __('Adjustment audit history') }}</h5><div class="table-responsive"><table class="table mb-0"><thead><tr><th>{{ __('When') }}</th><th>{{ __('Action') }}</th><th>{{ __('Amount delta') }}</th><th>{{ __('Reason') }}</th></tr></thead><tbody>@forelse($adjustments as $adjustment)<tr><td>{{ $adjustment->adjusted_at?->format('Y-m-d H:i') }}</td><td>{{ __($adjustment->adjustment_type) }}</td><td>{{ number_format($adjustment->amount_delta, 2) }} {{ $document->currency }}</td><td>{{ $adjustment->reason }}</td></tr>@empty<tr><td colspan="4" class="text-center text-muted">{{ __('No adjustments have been recorded.') }}</td></tr>@endforelse</tbody></table></div></div></div>
    <div class="card"><div class="card-body"><h5>{{ __('Audit timeline') }}</h5><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('When') }}</th><th>{{ __('Action') }}</th><th>{{ __('Reason') }}</th></tr></thead><tbody>@forelse($audits as $audit)<tr><td>{{ $audit->created_at?->format('Y-m-d H:i') }}</td><td>{{ __($audit->action) }}</td><td>{{ $audit->reason }}</td></tr>@empty<tr><td colspan="3" class="text-muted">{{ __('No finance adjustment audit history found.') }}</td></tr>@endforelse</tbody></table></div></div></div>
</div>
@endsection
