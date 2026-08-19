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
                    <hr><h5>{{ __('Group reporting users') }}</h5>
                    <form class="row" method="POST" action="{{ route('finance-groups.user-scopes.store', $group) }}">@csrf
                        <div class="form-group col-md-3"><select class="form-control" name="central_user_id" required><option value="">{{ __('Select central user') }}</option>@foreach($centralUsers as $user)<option value="{{ $user->id }}">{{ $user->full_name }} {{ $user->email ? '('.$user->email.')' : '' }}</option>@endforeach</select></div>
                        <div class="form-group col-md-2"><select class="form-control" name="capability"><option value="view_reports">{{ __('View reports') }}</option><option value="export_reports">{{ __('Export reports') }}</option><option value="manage_configuration">{{ __('Manage configuration') }}</option><option value="manage_hq_accounts">{{ __('Manage HQ Fund Accounts') }}</option><option value="request_group_transfers">{{ __('Request Group funding') }}</option><option value="confirm_group_transfers">{{ __('Confirm Group funding') }}</option></select></div>
                        <div class="form-group col-md-2"><select class="form-control" name="scope_type"><option value="GROUP">{{ __('All Group Schools') }}</option><option value="SCHOOL">{{ __('One School') }}</option><option value="HQ">{{ __('HQ') }}</option></select></div>
                        <div class="form-group col-md-3"><select class="form-control" name="school_id"><option value="">{{ __('School for School scope') }}</option>@foreach($group->schools->where('status','active') as $member)<option value="{{ $member->school_id }}">{{ $member->school?->name }}</option>@endforeach</select></div>
                        <div class="form-group col-md-2"><button class="btn btn-outline-primary" type="submit">{{ __('Save scope') }}</button></div></form>
                    <ul class="mb-2">@foreach($group->users as $groupUser)<li>{{ $groupUser->centralUser?->full_name ?? ('User #'.$groupUser->central_user_id) }}: {{ $groupUser->scopes->where('status','active')->map(fn($scope) => $scope->capability.' / '.$scope->scope_type.($scope->school ? ' / '.$scope->school->name : ''))->implode(', ') ?: __('No active scope') }}</li>@endforeach</ul>
                    <form class="row align-items-end" method="POST" action="{{ route('finance-groups.tenant-identities.store', $group) }}">@csrf
                        <div class="form-group col-md-4"><label>{{ __('Group reporting user') }}</label><select class="form-control" name="group_user_id" required><option value="">{{ __('Select user') }}</option>@foreach($group->users as $groupUser)<option value="{{ $groupUser->id }}">{{ $groupUser->centralUser?->full_name ?? ('User #'.$groupUser->central_user_id) }}</option>@endforeach</select></div>
                        <div class="form-group col-md-3"><label>{{ __('School') }}</label><select class="form-control" name="school_id" required><option value="">{{ __('Select School') }}</option>@foreach($group->schools->where('status','active') as $member)<option value="{{ $member->school_id }}">{{ $member->school?->name }}</option>@endforeach</select></div>
                        <div class="form-group col-md-3"><label>{{ __('Existing School user ID') }}</label><input class="form-control" type="number" min="1" name="tenant_user_id" required></div>
                        <div class="form-group col-md-2"><button class="btn btn-outline-primary" type="submit">{{ __('Save identity') }}</button></div>
                    </form>
                    <small class="text-muted d-block mb-2">{{ __('The selected existing School user is verified server-side in that School before this mapping is saved.') }}</small>
                    <ul class="mb-2">
                        @foreach($group->users as $groupUser)
                            @foreach($groupUser->tenantIdentities->where('status','active') as $identity)
                                <li>{{ $groupUser->centralUser?->full_name ?? ('User #'.$groupUser->central_user_id) }} → {{ $identity->school?->name }} ({{ __('School user') }} #{{ $identity->tenant_user_id }})</li>
                            @endforeach
                        @endforeach
                    </ul>
                    <small class="text-muted d-block mb-2">{{ __('Group reports and funding are available to configured Group Finance users from the Group Finance menu.') }}</small>
                </div>
            </div>
        @endforeach
    </div>
@endsection
