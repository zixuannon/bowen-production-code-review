@extends('layouts.master')

@section('title', __('Collection Receipt'))

@section('content')
<div class="content-wrapper collection-receipt-page">
    @if(session('success'))<div class="alert alert-success no-print">{{ session('success') }}</div>@endif
    <div class="collection-receipt" id="collection-receipt">
        <div class="collection-receipt__brand">BOWEN SCHOOL</div>
        <h1>{{ __('Collection Receipt') }}</h1>
        <p class="collection-receipt__status {{ $pending->status === 'confirmed' ? 'is-confirmed' : '' }}">
            {{ $pending->status === 'confirmed' ? __('Finance Confirmed') : __('Collected – Pending Finance Confirmation') }}
        </p>
        <dl>
            <dt>{{ __('Collection Receipt No.') }}</dt><dd>{{ $pending->acknowledgement_no }}</dd>
            <dt>{{ __('School') }}</dt><dd>{{ $school->name }}</dd>
            <dt>{{ __('Student') }}</dt><dd>{{ $pending->studentProfile?->student_name ?? '—' }}</dd>
            <dt>{{ __('Student Code') }}</dt><dd>{{ $pending->studentProfile?->student_code ?? '—' }}</dd>
            <dt>{{ __('Fee / Receivable') }}</dt><dd>{{ $pending->receivable?->description ?? '—' }}</dd>
            <dt>{{ __('Amount') }}</dt><dd>{{ number_format((float) $pending->amount, 2) }} {{ $pending->currency }}</dd>
            <dt>{{ __('Payment Method') }}</dt><dd>{{ __($pending->payment_method) }}</dd>
            @if($pending->payment_method === 'Bank Transfer')
                <dt>{{ __('Intended Fund Account') }}</dt><dd>{{ $pending->intendedFundAccount?->account_name ?? '—' }}</dd>
            @endif
            <dt>{{ __('Collected By') }}</dt><dd>{{ $pending->collectedBy?->full_name ?? '—' }}</dd>
            <dt>{{ __('Collection Date/Time') }}</dt><dd>{{ $pending->collected_at?->timezone('Asia/Yangon')->format('Y-m-d H:i') }}</dd>
            <dt>{{ __('Reference') }}</dt><dd>{{ $pending->payment_reference ?: '—' }}</dd>
            <dt>{{ __('Remarks') }}</dt><dd>{{ $pending->note ?: '—' }}</dd>
            @if($pending->status === 'confirmed')
                <dt>{{ __('Official Receipt') }}</dt><dd>{{ $pending->confirmedPayment?->receipt?->receipt_no ?? '—' }}</dd>
            @endif
        </dl>
        <p class="collection-receipt__notice">{{ __('This Collection Receipt is not an official Finance Receipt until Head Finance confirms it.') }}</p>
    </div>
    <div class="mt-3 no-print"><button class="btn btn-theme" onclick="window.print()">{{ __('Print') }}</button></div>
</div>
@endsection

@section('css')
<style>
.collection-receipt{width:80mm;max-width:100%;margin:0 auto;background:#fff;color:#000;padding:5mm;font:12px/1.35 Arial,sans-serif}.collection-receipt__brand,.collection-receipt h1{text-align:center;margin:0 0 5px}.collection-receipt h1{font-size:16px}.collection-receipt__status{text-align:center;font-weight:700;border:1px solid #000;padding:4px;margin:8px 0}.collection-receipt__status.is-confirmed{background:#eee}.collection-receipt dl{margin:0}.collection-receipt dt{font-weight:700;margin-top:6px}.collection-receipt dd{margin:0;overflow-wrap:anywhere}.collection-receipt__notice{border-top:1px dashed #000;padding-top:6px;margin-top:10px;font-size:10px}@media print{body *{visibility:hidden}.collection-receipt-page,.collection-receipt-page *{visibility:visible}.collection-receipt-page{position:absolute;left:0;top:0;width:80mm;padding:0!important}.collection-receipt{margin:0;padding:4mm;width:80mm}.no-print{display:none!important}}
</style>
@endsection
