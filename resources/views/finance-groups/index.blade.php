@extends('layouts.master')

@section('title', __('Finance Groups'))

@section('content')
    <div class="content-wrapper">
        <div class="page-header d-flex flex-wrap align-items-center justify-content-between">
            <h3 class="page-title mb-0">{{ __('Finance Groups') }}</h3>
            <a class="btn btn-theme mt-2 mt-md-0" href="{{ route('finance-groups.create') }}">+ {{ __('Create Finance Group') }}</a>
        </div>

        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        <p class="text-muted">{{ __('Groups link existing Schools for consolidated Central Finance configuration. They do not merge School data or create Fund Accounts.') }}</p>

        <div class="row">
            @forelse ($groups as $group)
                <div class="col-md-6 col-xl-4 mb-4">
                    <div class="card h-100"><div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start">
                            <h4 class="card-title mb-1">{{ $group->name }}</h4>
                            <span class="badge badge-{{ $group->status === 'active' ? 'success' : 'secondary' }}">{{ ucfirst($group->status) }}</span>
                        </div>
                        <dl class="mb-3 mt-2">
                            <dt>{{ __('Schools') }}</dt>
                            <dd>{{ $group->schools->where('status', 'active')->pluck('school.name')->filter()->join(', ') ?: __('No active Schools') }}</dd>
                            <dt>{{ __('Reporting Currency') }}</dt>
                            <dd>{{ $group->reporting_currency }}</dd>
                        </dl>
                        <a class="btn btn-outline-primary mt-auto" href="{{ route('finance-groups.show', $group) }}">{{ __('Manage') }}</a>
                    </div></div>
                </div>
            @empty
                <div class="col-12"><div class="alert alert-info mb-0">{{ __('No Finance Groups have been created.') }}</div></div>
            @endforelse
        </div>
    </div>
@endsection
