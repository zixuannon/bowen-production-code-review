@extends('layouts.master')

@section('title', __('Create Finance Group'))

@section('content')
    <div class="content-wrapper">
        <div class="page-header d-flex flex-wrap align-items-center justify-content-between">
            <h3 class="page-title mb-0">{{ __('Create Finance Group') }}</h3>
            <a class="btn btn-outline-secondary mt-2 mt-md-0" href="{{ route('finance-groups.index') }}">{{ __('Back to Finance Groups') }}</a>
        </div>
        <div class="card"><div class="card-body">
            @include('finance-groups.partials.form', ['group' => null, 'action' => route('finance-groups.store'), 'method' => 'POST', 'mode' => 'full'])
        </div></div>
    </div>
@endsection
