@extends('layouts.master')

@section('title', __('Ledger source document'))

@section('content')
<div class="content-wrapper">
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h4 class="mb-1">{{ __('Central Finance') }} / {{ __('Source document') }}</h4><small class="text-muted">{{ $school?->name ?? __('All authorized Schools') }}</small></div><a class="btn btn-outline-secondary" href="{{ route('central-finance.ledger.show', $entry->id) }}">{{ __('Back to Ledger entry') }}</a></div>
    <div class="card"><div class="card-body">
        <h5>{{ $source['label'] }}</h5>
        @if($source['model'])
            @php($document = $source['model'])
            <dl class="row mb-0">
                <dt class="col-sm-4">{{ __('Document number') }}</dt><dd class="col-sm-8">{{ $source['document_number'] }}</dd>
                <dt class="col-sm-4">{{ __('Status') }}</dt><dd class="col-sm-8"><span class="badge badge-{{ in_array($source['status'], ['reversal','reversed']) ? 'warning' : 'success' }}">{{ __($source['status']) }}</span></dd>
                <dt class="col-sm-4">{{ __('Canonical Ledger reference') }}</dt><dd class="col-sm-8">{{ $entry->entry_uuid }}</dd>
                <dt class="col-sm-4">{{ __('Reference') }}</dt><dd class="col-sm-8">{{ $entry->reference_no ?: '—' }}</dd>
                @if($document->getAttribute('amount') !== null)<dt class="col-sm-4">{{ __('Amount') }}</dt><dd class="col-sm-8">{{ number_format((float) $document->getAttribute('amount'), 2) }} {{ $document->getAttribute('currency') }}</dd>@endif
                @if($document->getAttribute('description'))<dt class="col-sm-4">{{ __('Description') }}</dt><dd class="col-sm-8">{{ $document->getAttribute('description') }}</dd>@endif
                @if($document->getAttribute('reason'))<dt class="col-sm-4">{{ __('Reason') }}</dt><dd class="col-sm-8">{{ $document->getAttribute('reason') }}</dd>@endif
                @if($document->getAttribute('created_at'))<dt class="col-sm-4">{{ __('Created') }}</dt><dd class="col-sm-8">{{ $document->getAttribute('created_at') }}</dd>@endif
            </dl>

            <hr><h6>{{ __('Audit timeline') }}</h6>
            @forelse($audits as $audit)
                <div class="border-left pl-3 mb-3"><strong>{{ __($audit->action) }}</strong><br><small class="text-muted">{{ $audit->created_at }} · {{ $audit->reason ?: '—' }}</small></div>
            @empty
                <p class="text-muted mb-0">{{ __('No additional document audit event is available for this historical source.') }}</p>
            @endforelse
        @else
            <div class="alert alert-warning mb-0">{{ __('The historical source document is unavailable, but this append-only Ledger entry remains intact and auditable.') }}</div>
        @endif
    </div></div>
</div>
@endsection
