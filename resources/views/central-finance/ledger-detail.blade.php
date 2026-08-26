@extends('layouts.master')

@section('title', __('Ledger entry'))

@section('content')
<div class="content-wrapper">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h4 class="mb-1">{{ __('Central Finance') }} / {{ __('Ledger entry') }}</h4><small class="text-muted">{{ $school?->name ?? __('All authorized Schools') }}</small></div>
        <a class="btn btn-outline-secondary" href="{{ route('central-finance.ledger') }}">{{ __('Back to Standard Ledger') }}</a>
    </div>
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between align-items-start"><div><h5 class="mb-1">{{ $entry->readable_source }}</h5><span class="badge badge-{{ $entry->source_status === 'reversal' || $entry->source_status === 'reversed' ? 'warning' : 'success' }}">{{ $entry->source_status }}</span></div><a class="btn btn-sm btn-outline-primary" href="{{ route('central-finance.ledger.source', $entry->id) }}">{{ __('Open source document') }}</a></div>
        <dl class="row mt-3 mb-0">
            <dt class="col-sm-4">{{ __('Ledger UUID') }}</dt><dd class="col-sm-8">{{ $entry->entry_uuid }}</dd>
            <dt class="col-sm-4">{{ __('Date / time') }}</dt><dd class="col-sm-8">{{ $entry->occurred_at }}</dd>
            <dt class="col-sm-4">{{ __('Fund Account') }}</dt><dd class="col-sm-8">{{ $entry->fundAccount?->account_name }} · {{ $entry->fundAccount?->account_code }}</dd>
            <dt class="col-sm-4">{{ __('Direction / operating effect') }}</dt><dd class="col-sm-8">{{ $entry->direction_label }} · {{ $entry->operating_label }}</dd>
            <dt class="col-sm-4">{{ __('Money In / Out') }}</dt><dd class="col-sm-8">{{ number_format($entry->money_in, 2) }} / {{ number_format($entry->money_out, 2) }} {{ $entry->currency }}</dd>
            <dt class="col-sm-4">{{ __('Reference') }}</dt><dd class="col-sm-8">{{ $entry->reference_no ?: '—' }}</dd>
            <dt class="col-sm-4">{{ __('Description') }}</dt><dd class="col-sm-8">{{ $entry->memo ?: '—' }}</dd>
        </dl>
    </div></div>
    <div class="card"><div class="card-body"><h5>{{ __('Original / reversal chain') }}</h5><p class="text-muted small">{{ __('Ledger entries are append-only. Reversals remain separate entries; the original is never deleted.') }}</p>
        <div class="table-responsive"><table class="table mb-0"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Source') }}</th><th>{{ __('Direction') }}</th><th>{{ __('Money In') }}</th><th>{{ __('Money Out') }}</th><th>{{ __('Reference') }}</th></tr></thead><tbody>
        <tr class="table-active"><td>{{ $entry->entry_date }}</td><td>{{ $entry->readable_source }}</td><td>{{ $entry->direction_label }}</td><td>{{ number_format($entry->money_in,2) }}</td><td>{{ number_format($entry->money_out,2) }}</td><td>{{ $entry->reference_no }}</td></tr>
        @foreach($related as $relatedEntry)<tr><td>{{ $relatedEntry->entry_date }}</td><td><a href="{{ route('central-finance.ledger.show', $relatedEntry->id) }}">{{ $relatedEntry->readable_source }}</a></td><td>{{ $relatedEntry->direction_label }}</td><td>{{ number_format($relatedEntry->money_in,2) }}</td><td>{{ number_format($relatedEntry->money_out,2) }}</td><td>{{ $relatedEntry->reference_no }}</td></tr>@endforeach
        </tbody></table></div>
    </div></div>
</div>
@endsection
