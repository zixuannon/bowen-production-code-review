@extends('layouts.master')

@section('title', __('Payment Collected'))

@section('css')
@include('central-finance.partials.foundation-styles')
@endsection

@section('content')
<div class="content-wrapper central-finance-page">
    <x-central-finance.page-header :title="__('Payment collected')" :school="$school" :status="$cutoverStatus" :eyebrow="__('Student Finance')" />
    <div class="card"><div class="card-body"><div class="alert alert-success"><h5 class="mb-1">{{ __('Payment collected') }}</h5><p class="mb-0">{{ __('The Central Payment, Receipt, and Standard Ledger entry were recorded once.') }}</p></div>
        <dl class="row mb-4"><dt class="col-sm-3">{{ __('Student') }}</dt><dd class="col-sm-9">{{ $receipt->student['name'] }}</dd><dt class="col-sm-3">{{ __('Payment') }}</dt><dd class="col-sm-9">{{ number_format($receipt->payment['this_payment'],2) }} {{ $receipt->payment['currency'] }} · {{ $receipt->payment['payment_method'] }}</dd><dt class="col-sm-3">{{ __('Receipt') }}</dt><dd class="col-sm-9">{{ $receipt->receipt['number'] }}</dd><dt class="col-sm-3">{{ __('Fund Account') }}</dt><dd class="col-sm-9">{{ $receipt->fundAccount['name'] }} · {{ $receipt->fundAccount['code'] }} · {{ $receipt->fundAccount['currency'] }}</dd><dt class="col-sm-3">{{ __('Outstanding') }}</dt><dd class="col-sm-9">{{ number_format($receipt->payment['outstanding_at_receipt'],2) }} {{ $receipt->payment['currency'] }}</dd></dl>
        <div class="d-flex flex-wrap" style="gap:.5rem"><a class="btn cf-primary-action" href="{{ route('central-finance.payments.receipt', $payment->id) }}">{{ __('View / Print Receipt') }}</a><a class="btn btn-outline-primary" href="{{ route('central-finance.student-collection.show', $payment->receivable->student_profile_id) }}">{{ __('Continue Payment') }}</a><a class="btn btn-outline-secondary" href="{{ route('central-finance.student-collection.index') }}">{{ __('Back to Student') }}</a></div>
    </div></div>
</div>

@if(false)
<div class="content-wrapper"><div class="card"><div class="card-body"><div class="alert alert-success"><h4>{{ __('Payment collected') }}</h4><p class="mb-0">{{ __('The Central Payment, Receipt, and Standard Ledger entry were recorded once.') }}</p></div>
    <dl class="row"><dt class="col-sm-3">{{ __('Student') }}</dt><dd class="col-sm-9">{{ $receipt->student['name'] }}</dd><dt class="col-sm-3">{{ __('Payment') }}</dt><dd class="col-sm-9">{{ number_format($receipt->payment['this_payment'],2) }} {{ $receipt->payment['currency'] }} · {{ $receipt->payment['payment_method'] }}</dd><dt class="col-sm-3">{{ __('Receipt') }}</dt><dd class="col-sm-9">{{ $receipt->receipt['number'] }}</dd><dt class="col-sm-3">{{ __('Fund Account') }}</dt><dd class="col-sm-9">{{ $receipt->fundAccount['name'] }} · {{ $receipt->fundAccount['code'] }} · {{ $receipt->fundAccount['currency'] }}</dd><dt class="col-sm-3">{{ __('Outstanding') }}</dt><dd class="col-sm-9">{{ number_format($receipt->payment['outstanding_at_receipt'],2) }} {{ $receipt->payment['currency'] }}</dd></dl>
    <a class="btn btn-theme" href="{{ route('central-finance.payments.receipt', $payment->id) }}">{{ __('View / Print Receipt') }}</a> <a class="btn btn-outline-primary" href="{{ route('central-finance.student-collection.show', $payment->receivable->student_profile_id) }}">{{ __('Continue Payment') }}</a> <a class="btn btn-outline-secondary" href="{{ route('central-finance.student-collection.index') }}">{{ __('Back to Student') }}</a>
</div></div></div>
@endif
@endsection
