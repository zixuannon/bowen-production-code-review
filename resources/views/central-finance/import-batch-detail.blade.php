@extends('layouts.master')

@section('title', __('Import Batch'))

@section('css')
@include('central-finance.partials.foundation-styles')
@endsection

@section('content')
<div class="content-wrapper central-finance-page">
    <x-central-finance.page-header :title="__('Import Batch')" :description="__('The original preview is immutable. Uploading a corrected file always creates a new Batch.')" :school="$school" :eyebrow="__('Central Finance')" />
    <div class="card mb-3"><div class="card-body">
        <div class="cf-workspace-toolbar"><div><h5 class="mb-1">{{ $batch->file_name }}</h5><p class="text-muted mb-0">{{ $batch->batch_uuid }} · {{ $batch->template_version }}</p></div><span class="badge cf-status-badge badge-{{ $state['badge'] }}">{{ $state['label'] }}</span></div>
        <div class="row"><div class="col-md-3 mb-2"><small class="text-muted d-block">{{ __('Valid') }}</small><strong>{{ $batch->valid_rows }}</strong></div><div class="col-md-3 mb-2"><small class="text-muted d-block">{{ __('Errors') }}</small><strong>{{ $batch->error_rows }}</strong></div><div class="col-md-3 mb-2"><small class="text-muted d-block">{{ __('Created') }}</small><strong>{{ $batch->created_at }}</strong></div><div class="col-md-3 mb-2"><small class="text-muted d-block">{{ __('Confirmed') }}</small><strong>{{ $batch->confirmed_at ?: '—' }}</strong></div></div>
        <div class="d-flex flex-wrap mt-2"><a class="btn btn-sm btn-outline-danger mr-2 mb-2" href="{{ route('central-finance.imports.errors', $batch->token) }}">{{ __('Download error report') }}</a><a class="btn btn-sm btn-outline-primary mr-2 mb-2" href="{{ $correctedUploadUrl }}">{{ __('Upload corrected file as new Batch') }}</a><a class="btn btn-sm btn-light mb-2" href="{{ route('central-finance.imports') }}">{{ __('Back to import batches') }}</a></div>
    </div></div>
    <div class="card"><div class="card-body"><h5>{{ __('Validation details') }}</h5><div class="table-responsive"><table class="table table-sm cf-data-table cf-mobile-card-table mb-0"><thead><tr><th>{{ __('Row') }}</th><th>{{ __('Status') }}</th><th>{{ __('Errors') }}</th></tr></thead><tbody>@forelse(collect($batch->preview_data ?? []) as $row)<tr><td data-label="{{ __('Row') }}">{{ $row['row_number'] ?? '—' }}</td><td data-label="{{ __('Status') }}"><span class="badge cf-status-badge badge-{{ ($row['status'] ?? '') === 'valid' ? 'success' : 'danger' }}">{{ __(ucfirst((string) ($row['status'] ?? 'unknown'))) }}</span></td><td data-label="{{ __('Errors') }}" class="cf-break-anywhere">{{ collect($row['errors'] ?? [])->map(fn ($error) => __((string) $error))->join('; ') ?: '—' }}</td></tr>@empty<tr><td colspan="3"><div class="cf-empty-state">{{ __('No preview rows are retained for this Batch.') }}</div></td></tr>@endforelse</tbody></table></div></div></div>
</div>
@endsection
