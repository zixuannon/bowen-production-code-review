@extends('layouts.master')

@section('title', __('Student Finance'))

@section('content')
<div class="content-wrapper">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3"><div><small class="text-primary font-weight-bold">{{ __('Central Finance / Student Collection') }}</small><h4 class="mb-0">{{ $profile->student_name }}</h4><small class="text-muted">{{ $profile->admission_no ?: '—' }} · {{ trim($profile->class_name.' '.$profile->section_name) ?: '—' }}</small></div><a class="btn btn-outline-secondary" href="{{ route('central-finance.student-collection.index') }}">{{ __('Back to students') }}</a></div>
    <div class="card mb-3"><div class="card-body"><div class="row">@foreach($profile->currency_totals as $currency => $total)<div class="col-md-4 mb-2"><strong>{{ $currency }}</strong><span class="d-block">{{ __('Due') }}: {{ number_format($total['due'],2) }}</span><span class="d-block">{{ __('Paid') }}: {{ number_format($total['paid'],2) }}</span><span class="d-block">{{ __('Outstanding') }}: {{ number_format($total['outstanding'],2) }}</span></div>@endforeach</div></div></div>
    <div class="card mb-3"><div class="card-body"><h5>{{ __('Receivable items') }}</h5><div class="table-responsive"><table class="table"><thead><tr><th>{{ __('Description') }}</th><th>{{ __('Due') }}</th><th>{{ __('Paid') }}</th><th>{{ __('Outstanding') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead><tbody>@forelse($profile->receivables as $receivable)<tr><td>{{ $receivable->description }}</td><td>{{ number_format($receivable->amount_due,2) }} {{ $receivable->currency }}</td><td>{{ number_format($receivable->amount_paid,2) }} {{ $receivable->currency }}</td><td>{{ number_format($receivable->amount_due-$receivable->amount_paid,2) }} {{ $receivable->currency }}</td><td><span class="badge badge-light">{{ $receivable->status }}</span></td><td>@if($canCollect && in_array($receivable->status, ['open','partial'], true))<a class="btn btn-sm btn-theme" href="{{ route('central-finance.student-collection.review', [$profile->id, $receivable->id]) }}">{{ __('Collect') }}</a>@endif</td></tr>@empty<tr><td colspan="6" class="text-center text-muted">{{ __('No Central Receivables are available for this student.') }}</td></tr>@endforelse</tbody></table></div></div></div>
    <div class="card"><div class="card-body"><h5>{{ __('Payment / Receipt history') }}</h5><div class="table-responsive"><table class="table mb-0"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Receivable') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Fund Account') }}</th><th>{{ __('Receipt') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>
    @foreach($profile->receivables as $receivable)
        @foreach($receivable->payments as $payment)
            <tr><td>{{ $payment->paid_at?->format('Y-m-d H:i') }}</td><td>{{ $receivable->description }}</td><td>{{ number_format($payment->amount,2) }} {{ $payment->currency }}</td><td>{{ $payment->fundAccount?->account_name ?: '—' }}</td><td>@if($payment->receipt)<a href="{{ route('central-finance.payments.receipt', $payment->id) }}">{{ $payment->receipt->receipt_no }}</a>@endif</td><td>{{ $payment->refunds->isEmpty() ? __('Collected') : __('Refund status available') }}</td></tr>
        @endforeach
    @endforeach
    </tbody></table></div></div></div>
</div>
@endsection
