@extends('layouts.master')

@section('title', __('Finance Groups'))

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">{{ __('Finance Groups') }}</h3>
        </div>

        <div class="alert alert-info">
            {{ __('Groups link existing Schools for future consolidated read-only Finance reporting. They do not merge School data or create Fund Accounts.') }}
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h4 class="card-title">{{ __('Create Finance Group') }}</h4>
                @include('finance-groups.partials.form', ['group' => null, 'action' => route('finance-groups.store'), 'method' => 'POST'])
            </div>
        </div>

        @foreach ($groups as $group)
            <div class="card mb-4">
                <div class="card-body">
                    <h4 class="card-title">{{ $group->name }}</h4>
                    @include('finance-groups.partials.form', ['group' => $group, 'action' => route('finance-groups.update', $group), 'method' => 'PUT'])
                </div>
            </div>
        @endforeach
    </div>
@endsection
