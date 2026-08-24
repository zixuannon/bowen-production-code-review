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
                    <hr><h5>{{ __('Central Finance Staff') }}</h5>
                    <p class="text-muted">{{ __('Configure Central Finance access here. A Head Finance grant covers every active Group School; a School Accountant grant is limited to one School. Super Admin configures access but never receives it automatically.') }}</p>
                    <div class="row">
                        <div class="col-lg-6"><form class="border rounded p-3 mb-3" method="POST" action="{{ route('finance-groups.central-school-scopes.store', $group) }}">@csrf
                            <h6>{{ __('Head Finance · All Group Schools') }}</h6><input type="hidden" name="grant_type" value="head_finance_all">
                            <div class="form-group"><select class="form-control" name="central_user_id" required><option value="">{{ __('Select Head Finance') }}</option>@foreach($centralUsers->filter(fn($user) => $user->hasRole('Head Finance')) as $user)<option value="{{ $user->id }}">{{ $user->full_name }} {{ $user->email ? '('.$user->email.')' : '' }}</option>@endforeach</select></div>
                            <button class="btn btn-theme" type="submit">{{ __('Authorize all Group Schools') }}</button>
                        </form></div>
                        <div class="col-lg-6"><form class="border rounded p-3 mb-3" method="POST" action="{{ route('finance-groups.central-school-scopes.store', $group) }}">@csrf
                            <h6>{{ __('School Accountant · One School') }}</h6><input type="hidden" name="grant_type" value="school_accountant">
                            <div class="form-group"><select class="form-control" name="central_user_id" required><option value="">{{ __('Select Central user') }}</option>@foreach($centralUsers as $user)<option value="{{ $user->id }}">{{ $user->full_name }} {{ $user->email ? '('.$user->email.')' : '' }}</option>@endforeach</select></div>
                            <div class="form-group"><select class="form-control" name="school_id" required><option value="">{{ __('Select School') }}</option>@foreach($group->schools->where('status','active') as $member)<option value="{{ $member->school_id }}">{{ $member->school?->name }}</option>@endforeach</select></div>
                            <button class="btn btn-outline-primary" type="submit">{{ __('Authorize School Accountant') }}</button>
                        </form></div>
                    </div>
                    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('User') }}</th><th>{{ __('School') }}</th><th>{{ __('View') }}</th><th>{{ __('Operate') }}</th><th>{{ __('Approve') }}</th><th>{{ __('Confirm') }}</th><th>{{ __('Status') }}</th><th>{{ __('Action') }}</th></tr></thead><tbody>
                        @foreach($centralScopes->filter(fn($scope) => $group->schools->where('status','active')->pluck('school_id')->contains($scope->school_id)) as $scope)
                            @php($active = $scope->can_view || $scope->can_operate || $scope->can_approve_reimbursements || $scope->can_confirm_funding)
                            <tr><td>{{ trim($scope->first_name.' '.$scope->last_name) ?: ('User #'.$scope->user_id) }}</td><td>{{ $scope->school_name }}</td><td>{{ $scope->can_view ? '✓' : '—' }}</td><td>{{ $scope->can_operate ? '✓' : '—' }}</td><td>{{ $scope->can_approve_reimbursements ? '✓' : '—' }}</td><td>{{ $scope->can_confirm_funding ? '✓' : '—' }}</td><td>{{ $active ? __('Active') : __('Disabled') }}</td><td><details><summary>{{ __('Edit') }}</summary><form method="POST" action="{{ route('finance-groups.central-school-scopes.store', $group) }}" class="mt-2">@csrf<input type="hidden" name="grant_type" value="custom"><input type="hidden" name="central_user_id" value="{{ $scope->user_id }}"><input type="hidden" name="school_id" value="{{ $scope->school_id }}"><label class="mr-1"><input type="checkbox" name="can_view" value="1" @checked($scope->can_view)> {{ __('View') }}</label><label class="mr-1"><input type="checkbox" name="can_operate" value="1" @checked($scope->can_operate)> {{ __('Operate') }}</label><label class="mr-1"><input type="checkbox" name="can_approve_reimbursements" value="1" @checked($scope->can_approve_reimbursements)> {{ __('Approve') }}</label><label class="mr-1"><input type="checkbox" name="can_confirm_funding" value="1" @checked($scope->can_confirm_funding)> {{ __('Confirm') }}</label><button class="btn btn-sm btn-outline-primary">{{ __('Save') }}</button></form><form method="POST" action="{{ route('finance-groups.central-school-scopes.disable', $group) }}" class="mt-1">@csrf<input type="hidden" name="central_user_id" value="{{ $scope->user_id }}"><input type="hidden" name="school_id" value="{{ $scope->school_id }}"><button class="btn btn-sm btn-outline-danger">{{ __('Revoke / Disable') }}</button></form></details></td></tr>
                        @endforeach
                    </tbody></table></div>
                    <details class="mb-2"><summary>{{ __('Legacy / Transition tenant identity mapping') }}</summary><div class="pt-2"><p class="text-muted">{{ __('This mapping is retained only for legacy Group Finance transition. It is not a Central Finance user, School Scope, or Fund Account configuration entry.') }}</p><form class="row align-items-end" method="POST" action="{{ route('finance-groups.tenant-identities.store', $group) }}">@csrf
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
                    <small class="text-muted d-block mb-2">{{ __('Group reports and funding are available to configured Group Finance users from the Group Finance menu.') }}</small></div></details>
                </div>
            </div>
        @endforeach
    </div>
@endsection
