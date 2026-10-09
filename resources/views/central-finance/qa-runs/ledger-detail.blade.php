@extends('layouts.master')

@section('title', __('QA Run Ledger entry'))

@section('content')
<div class="container-fluid">
    <div class="alert alert-warning"><strong>QA ONLY</strong> — This ledger entry is visible here only through its QA Run membership and remains excluded from Official totals.</div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h4 class="mb-1">Zixuan QA Run #{{ $run->run_number }} / Ledger #{{ $entry->id }}</h4><small class="text-muted">{{ $run->label }}</small></div>
        <a class="btn btn-outline-secondary" href="{{ route('central-finance.qa-runs.show', $run->id) }}">{{ __('Back to QA Run') }}</a>
    </div>
    <div class="card"><div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4">{{ __('Ledger UUID') }}</dt><dd class="col-sm-8">{{ $entry->entry_uuid }}</dd>
            <dt class="col-sm-4">{{ __('School ID') }}</dt><dd class="col-sm-8">{{ $entry->school_id ?? __('Group-owned; no School ID') }}</dd>
            <dt class="col-sm-4">{{ __('Fund Account') }}</dt><dd class="col-sm-8">{{ $entry->fundAccount->account_name }} · {{ $entry->fundAccount->account_code }}</dd>
            <dt class="col-sm-4">{{ __('Transaction Date') }}</dt><dd class="col-sm-8">{{ $entry->entry_date?->format('Y-m-d') }}</dd>
            <dt class="col-sm-4">{{ __('Recorded At') }}</dt><dd class="col-sm-8">{{ $entry->created_at?->timezone('Asia/Yangon')->format('Y-m-d H:i:s') }}</dd>
            <dt class="col-sm-4">{{ __('Source') }}</dt><dd class="col-sm-8">{{ $entry->source_type }} · {{ $entry->source_id }}</dd>
            <dt class="col-sm-4">{{ __('Transaction Type') }}</dt><dd class="col-sm-8">{{ $entry->transaction_type }}</dd>
            <dt class="col-sm-4">{{ __('Money In / Out') }}</dt><dd class="col-sm-8">{{ number_format((float) $entry->money_in, 2) }} / {{ number_format((float) $entry->money_out, 2) }} {{ $entry->currency }}</dd>
            <dt class="col-sm-4">{{ __('Operating Income / Expense') }}</dt><dd class="col-sm-8">{{ number_format((float) $entry->operating_income, 2) }} / {{ number_format((float) $entry->operating_expense, 2) }} {{ $entry->currency }}</dd>
            <dt class="col-sm-4">{{ __('Reference') }}</dt><dd class="col-sm-8">{{ $entry->reference_no ?: '—' }}</dd>
            <dt class="col-sm-4">{{ __('Description') }}</dt><dd class="col-sm-8">{{ $entry->memo ?: '—' }}</dd>
        </dl>
    </div></div>
</div>
@endsection
