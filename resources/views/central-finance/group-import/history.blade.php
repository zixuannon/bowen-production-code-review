@extends('layouts.master')
@section('title', __('Group Import History'))
@section('content')
<div class="central-finance-page">
    @include('central-finance.partials.foundation-styles')
    <div class="cf-page-header mb-3">
        <div>
            <p class="cf-page-header__eyebrow">{{ __('Group Finance') }}</p>
            <h1 class="cf-page-header__title">{{ __('Group Import History') }}</h1>
            <p class="cf-page-header__description">{{ __('Only batches uploaded by your account in authorized Finance Groups are shown.') }}</p>
        </div>
        <a class="btn btn-outline-primary" href="{{ route('central-finance.group-import.index', request()->only('include_qa_test')) }}">{{ __('New import') }}</a>
    </div>

    @include('central-finance.partials.data-visibility-toggle')

    <form method="GET" action="{{ route('central-finance.group-import.history') }}" class="card mb-3">
        @if($includeQaTest)<input type="hidden" name="include_qa_test" value="1">@endif
        <div class="card-body row align-items-end">
            <div class="col-md-4 form-group mb-md-0">
                <label for="group-import-history-group">{{ __('Finance Group') }}</label>
                <select class="form-control" id="group-import-history-group" name="finance_group_id">
                    <option value="">{{ __('All authorized groups') }}</option>
                    @foreach($groups as $group)<option value="{{ $group->id }}" @selected(request('finance_group_id') == $group->id)>{{ $group->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-3 form-group mb-md-0">
                <label for="group-import-history-status">{{ __('Status') }}</label>
                <select class="form-control" id="group-import-history-status" name="status">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach(['previewed', 'completed', 'failed'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ __($status) }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-3 form-group mb-md-0">
                <label for="group-import-history-search">{{ __('File name') }}</label>
                <input class="form-control" id="group-import-history-search" type="search" name="search" value="{{ request('search') }}" maxlength="180">
            </div>
            <div class="col-md-2 mt-3 mt-md-0"><button class="btn btn-theme btn-block">{{ __('Filter') }}</button></div>
        </div>
    </form>

    <div class="card"><div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm cf-data-table">
                <thead><tr><th>{{ __('Uploaded') }}</th><th>{{ __('Finance Group') }}</th><th>{{ __('File') }}</th><th>{{ __('Status') }}</th><th>{{ __('Rows') }}</th><th>{{ __('Errors') }}</th><th>{{ __('Conflicts') }}</th><th>{{ __('Confirmed by') }}</th><th>{{ __('Actions') }}</th></tr></thead>
                <tbody>
                @forelse($history as $batch)
                    <tr>
                        <td>{{ $batch->created_at?->format('Y-m-d H:i') ?: '—' }}</td>
                        <td>{{ $groups->firstWhere('id', $batch->finance_group_id)?->name ?: '—' }}</td>
                        <td class="cf-break-anywhere">{{ $batch->file_name }}</td>
                        <td><span class="badge badge-{{ $batch->status === 'completed' ? 'success' : ($batch->status === 'failed' ? 'danger' : 'info') }}">{{ __($batch->status) }}</span></td>
                        <td>{{ $batch->total_rows }}</td>
                        <td>{{ $batch->error_rows }}</td>
                        <td>{{ $batch->conflict_rows }}</td>
                        <td>{{ $batch->confirmedBy?->full_name ?: '—' }}</td>
                        <td class="text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('central-finance.group-import.index', array_filter(['batch' => $batch->token, 'include_qa_test' => $includeQaTest ? 1 : null])) }}">{{ __('View batch') }}</a>
                            @if(($batch->error_rows + $batch->conflict_rows) > 0)
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('central-finance.group-import.errors', array_filter(['batch' => $batch->token, 'include_qa_test' => $includeQaTest ? 1 : null])) }}">{{ __('Export error rows') }}</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9"><div class="cf-empty-state">{{ __('No import batches match this filter.') }}</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $history->links() }}
    </div></div>
</div>
@endsection
