@extends('layouts.master')

@section('title', __('Receipt'))

@section('css')
<style>
    .central-receipt { max-width: 820px; margin: 0 auto; }
    .central-receipt__brand { display:flex; gap:1rem; align-items:center; }
    .central-receipt__brand img { width:64px; height:64px; object-fit:contain; }
    .central-receipt__number { font-size:1.25rem; font-weight:700; letter-spacing:.04em; }
    .central-receipt__amount { font-size:1.1rem; font-weight:700; }
    @page { size:A4; margin:14mm; }
    @media print { .sidebar,.navbar,.header,.footer,.page-header,.btn,.central-receipt__actions{display:none!important}.content-wrapper,.main-panel{margin:0!important;padding:0!important;width:100%!important}.central-receipt,.card,.card-body{max-width:none!important;border:0!important;box-shadow:none!important;padding:0!important}body{color:#000!important;background:#fff!important}.badge{border:1px solid #000!important;color:#000!important;background:#fff!important} }
</style>
@endsection

@section('content')
<div class="content-wrapper"><div class="central-receipt card"><div class="card-body p-4">
    @include('central-finance.partials.receipt-document', ['receipt' => $receipt])
    <h6 class="mt-4">{{ __('Audit timeline') }}</h6><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('When') }}</th><th>{{ __('Action') }}</th><th>{{ __('Reason') }}</th></tr></thead><tbody>@forelse($audits as $audit)<tr><td>{{ $audit->created_at?->format('Y-m-d H:i') }}</td><td>{{ __($audit->action) }}</td><td>{{ $audit->reason ?: __('System recorded') }}</td></tr>@empty<tr><td colspan="3" class="text-muted">{{ __('Historical audit entries were not available for this receipt.') }}</td></tr>@endforelse</tbody></table></div>
    <div class="central-receipt__actions d-flex justify-content-between mt-4"><a class="btn btn-outline-secondary" href="{{ route('central-finance.payments.index') }}">{{ __('Back to payment history') }}</a><div><span class="small text-muted mr-2">{{ __('PDF: NOT IMPLEMENTED — P1') }}</span><button type="button" class="btn btn-theme" onclick="window.print()">{{ __('打印收据') }}</button></div></div>
</div></div></div>
@endsection
