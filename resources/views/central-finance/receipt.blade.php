@extends('layouts.master')

@section('title', __('Receipt'))

@section('css')
<style>
    @page { size: 80mm auto; margin: 0; }
    .central-receipt-page { padding: 1.5rem !important; }
    .central-receipt {
        width: 80mm;
        max-width: 100%;
        margin: 0 auto;
        background: #fff;
        color: #000;
        font: 12px/1.35 Arial, sans-serif;
    }
    .central-receipt .card-body { padding: 5mm !important; }
    .central-receipt__brand,
    .central-receipt h1 { text-align: center; margin: 0 0 5px; }
    .central-receipt__brand img { width: 13mm; height: 13mm; object-fit: contain; display: block; margin: 0 auto 3px; }
    .central-receipt__school { font-weight: 700; font-size: 13px; }
    .central-receipt h1 { font-size: 16px; font-weight: 700; }
    .central-receipt__status { text-align: center; font-weight: 700; border: 1px solid #000; padding: 4px; margin: 8px 0; }
    .central-receipt__status.is-partial { background: #eee; }
    .central-receipt__number { text-align: center; font-weight: 700; letter-spacing: .03em; overflow-wrap: anywhere; }
    .central-receipt dl { margin: 0; }
    .central-receipt dt { font-weight: 700; margin-top: 6px; }
    .central-receipt dd { margin: 0; overflow-wrap: anywhere; }
    .central-receipt__amount { font-size: 15px; font-weight: 700; }
    .central-receipt__section { border-top: 1px dashed #000; margin-top: 10px; padding-top: 8px; }
    .central-receipt__section-title { font-weight: 700; margin: 0 0 4px; }
    .central-receipt__audit { font-size: 10px; }
    .central-receipt__audit-row { border-top: 1px dotted #777; margin-top: 4px; padding-top: 4px; }
    .central-receipt__notice { border-top: 1px dashed #000; padding-top: 6px; margin: 10px 0 0; font-size: 10px; }
    @media print {
        body * { visibility: hidden; }
        .central-receipt-page, .central-receipt-page * { visibility: visible; }
        .central-receipt-page { position: absolute; left: 0; top: 0; width: 80mm; padding: 0 !important; }
        .central-receipt { width: 80mm; margin: 0; box-shadow: none !important; border: 0 !important; }
        .central-receipt .card-body { padding: 4mm !important; }
        .central-receipt__actions { display: none !important; }
    }
</style>
@endsection

@section('content')
<div class="content-wrapper central-receipt-page"><div class="central-receipt card"><div class="card-body">
    @include('central-finance.partials.receipt-document', ['receipt' => $receipt, 'audits' => $audits])
    <div class="central-receipt__actions mt-3 no-print"><a class="btn btn-outline-secondary" href="{{ route('central-finance.payments.index') }}">{{ __('Back to payment history') }}</a><button type="button" class="btn btn-theme float-right" onclick="window.print()">{{ __('打印收据') }}</button></div>
</div></div></div>
@endsection
