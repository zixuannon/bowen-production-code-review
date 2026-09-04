@extends('layouts.master')

@section('title', $group->name.' · '.__('Finance Groups'))

@section('content')
    <div class="content-wrapper">
        <div class="page-header d-flex flex-wrap align-items-center justify-content-between">
            <div><a class="text-muted small" href="{{ route('finance-groups.index') }}">← {{ __('Finance Groups') }}</a><h3 class="page-title mb-0 mt-1">{{ $group->name }}</h3></div>
            <span class="badge badge-{{ $group->status === 'active' ? 'success' : 'secondary' }}">{{ ucfirst($group->status) }}</span>
        </div>
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

        <nav class="nav nav-pills flex-column flex-sm-row mb-4" aria-label="{{ __('Finance Group configuration') }}">
            <a class="nav-link active" href="#basic">{{ __('Basic settings') }}</a>
            <a class="nav-link" href="#schools">{{ __('Member Schools') }}</a>
            <a class="nav-link" href="#staff">{{ __('Central Finance Staff') }}</a>
            <a class="nav-link" href="#advanced">{{ __('Advanced / Legacy') }}</a>
        </nav>

        <section id="basic" class="card mb-4"><div class="card-body"><h4 class="card-title">{{ __('Basic settings') }}</h4>
            @include('finance-groups.partials.form', ['group' => $group, 'action' => route('finance-groups.update', $group), 'method' => 'PUT', 'mode' => 'basic'])
        </div></section>

        <section id="schools" class="card mb-4"><div class="card-body"><h4 class="card-title">{{ __('Member Schools') }}</h4>
            <p class="text-muted">{{ __('Only existing Schools from the central registry can be selected.') }}</p>
            @include('finance-groups.partials.form', ['group' => $group, 'action' => route('finance-groups.update', $group), 'method' => 'PUT', 'mode' => 'schools'])
        </div></section>

        <section id="staff" class="card mb-4"><div class="card-body">
            <h4 class="card-title">{{ __('Central Finance Staff') }}</h4>
            <p class="text-muted">{{ __('Super Admin explicitly configures access and never receives Finance authority automatically.') }}</p>
            <div class="row">
                <div class="col-lg-6"><form class="border rounded p-3 mb-3" method="POST" action="{{ route('finance-groups.central-school-scopes.store', $group) }}">@csrf
                    <h5>{{ __('Head Finance') }}</h5><input type="hidden" name="grant_type" value="head_finance_all">
                    <div class="form-group"><label>{{ __('Central User') }}</label><select class="form-control" name="central_user_id" required><option value="">{{ __('Select Head Finance') }}</option>@foreach($centralUsers->filter(fn($user) => $user->hasRole('Head Finance')) as $user)<option value="{{ $user->id }}">{{ $user->full_name }} {{ $user->email ? '('.$user->email.')' : '' }}</option>@endforeach</select></div>
                    <button class="btn btn-theme" type="submit">{{ __('Authorize All Group Schools') }}</button>
                </form></div>
                <div class="col-lg-6"><form class="border rounded p-3 mb-3" method="POST" action="{{ route('finance-groups.school-staff-accountants.store', $group) }}">@csrf
                    <h5>{{ __('School Accountant') }}</h5>
                    <p class="small text-muted">{{ __('Grant an existing School Staff login Central Finance access. The saved mapping uses a stable Staff UUID, never a tenant user ID.') }}</p>
                    <div class="form-group"><label>{{ __('School') }}</label><select class="form-control" name="school_id" required><option value="">{{ __('Select School') }}</option>@foreach($group->schools->where('status','active') as $member)<option value="{{ $member->school_id }}">{{ $member->school?->name }}</option>@endforeach</select></div>
                    <div class="form-group"><label>{{ __('Existing School Staff') }}</label><select class="form-control" name="tenant_user_id" required><option value="">{{ __('Select School Staff') }}</option>@foreach($schoolStaff as $staff)<option value="{{ $staff->tenant_user_id }}" data-school-id="{{ $staff->school_id }}">{{ $staff->name }}{{ $staff->email ? ' · '.$staff->email : '' }}</option>@endforeach</select></div>
                    <button class="btn btn-outline-primary" type="submit">{{ __('Grant Accountant Finance Access') }}</button>
                </form></div>
                <div class="col-lg-6"><form class="border rounded p-3 mb-3" method="POST" action="{{ route('finance-groups.school-staff-principals.store', $group) }}">@csrf
                    <h5>{{ __('Principal') }}</h5>
                    <p class="small text-muted">{{ __('Grant an existing School Principal read-only Central Finance access. School roles and Central Finance scopes remain separate.') }}</p>
                    <div class="form-group"><label>{{ __('School') }}</label><select class="form-control" name="school_id" required><option value="">{{ __('Select School') }}</option>@foreach($group->schools->where('status','active') as $member)<option value="{{ $member->school_id }}">{{ $member->school?->name }}</option>@endforeach</select></div>
                    <div class="form-group"><label>{{ __('Existing School Staff') }}</label><select class="form-control" name="tenant_user_id" required><option value="">{{ __('Select Principal') }}</option>@foreach($schoolStaff as $staff)<option value="{{ $staff->tenant_user_id }}" data-school-id="{{ $staff->school_id }}">{{ $staff->name }}{{ $staff->email ? ' · '.$staff->email : '' }}</option>@endforeach</select></div>
                    <button class="btn btn-outline-primary" type="submit">{{ __('Grant Principal Read-only Access') }}</button>
                </form></div>
            </div>
            <div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('User') }}</th><th>{{ __('Role') }}</th><th>{{ __('School') }}</th><th>{{ __('View') }}</th><th>{{ __('Operate') }}</th><th>{{ __('Approve') }}</th><th>{{ __('Confirm') }}</th><th>{{ __('Status') }}</th><th>{{ __('Edit') }}</th><th>{{ __('Revoke') }}</th></tr></thead><tbody>
                @forelse($centralScopes as $scope)
                    @php($active = $scope->can_view || $scope->can_operate || $scope->can_approve_reimbursements || $scope->can_confirm_funding)
                    @php($centralUser = $centralUsers->firstWhere('id', $scope->user_id))
                    <tr><td>{{ trim($scope->first_name.' '.$scope->last_name) ?: ('User #'.$scope->user_id) }}</td><td>{{ $centralUser?->hasRole('Head Finance') ? __('Head Finance') : ($scope->can_operate ? __('School Accountant') : __('Principal')) }}</td><td>{{ $scope->school_name }}</td><td>{{ $scope->can_view ? '✓' : '—' }}</td><td>{{ $scope->can_operate ? '✓' : '—' }}</td><td>{{ $scope->can_approve_reimbursements ? '✓' : '—' }}</td><td>{{ $scope->can_confirm_funding ? '✓' : '—' }}</td><td>{{ $active ? __('Active') : __('Disabled') }}</td>
                        <td><details><summary>{{ __('Edit') }}</summary><form method="POST" action="{{ route('finance-groups.central-school-scopes.store', $group) }}" class="mt-2">@csrf<input type="hidden" name="grant_type" value="custom"><input type="hidden" name="central_user_id" value="{{ $scope->user_id }}"><input type="hidden" name="school_id" value="{{ $scope->school_id }}"><label class="mr-1"><input type="checkbox" name="can_view" value="1" @checked($scope->can_view)> {{ __('View') }}</label><label class="mr-1"><input type="checkbox" name="can_operate" value="1" @checked($scope->can_operate)> {{ __('Operate') }}</label><label class="mr-1"><input type="checkbox" name="can_approve_reimbursements" value="1" @checked($scope->can_approve_reimbursements)> {{ __('Approve') }}</label><label class="mr-1"><input type="checkbox" name="can_confirm_funding" value="1" @checked($scope->can_confirm_funding)> {{ __('Confirm') }}</label><button class="btn btn-sm btn-outline-primary">{{ __('Save') }}</button></form></details></td>
                        <td><form method="POST" action="{{ route('finance-groups.central-school-scopes.disable', $group) }}" data-lifecycle-confirm data-lifecycle-object="{{ $scope->email }} · {{ $scope->school_name }}" data-lifecycle-current-status="{{ __('Active') }}" data-lifecycle-result="{{ __('Central Finance School access revoked immediately') }}">@csrf<input type="hidden" name="central_user_id" value="{{ $scope->user_id }}"><input type="hidden" name="school_id" value="{{ $scope->school_id }}"><input name="reason" class="form-control form-control-sm mb-1" placeholder="{{ __('Revocation reason') }}" required><button class="btn btn-sm btn-outline-danger">{{ __('Revoke / Disable') }}</button></form></td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-muted">{{ __('No Central Finance School scopes are configured.') }}</td></tr>
                @endforelse
            </tbody></table></div>
        </div></section>

        <section id="advanced" class="card mb-4"><div class="card-body"><h4 class="card-title">{{ __('Advanced / Legacy') }}</h4>
            <details><summary>{{ __('Legacy / Transition tenant identity mapping') }}</summary><div class="pt-3">
                <div class="alert alert-warning">{{ __('Legacy / Transition only — Central Finance no longer relies on this as its long-term write identity.') }}</div>
                <form class="row align-items-end" method="POST" action="{{ route('finance-groups.tenant-identities.store', $group) }}">@csrf
                    <div class="form-group col-md-4"><label>{{ __('Group reporting user') }}</label><select class="form-control" name="group_user_id" required><option value="">{{ __('Select user') }}</option>@foreach($group->users as $groupUser)<option value="{{ $groupUser->id }}">{{ $groupUser->centralUser?->full_name ?? ('User #'.$groupUser->central_user_id) }}</option>@endforeach</select></div>
                    <div class="form-group col-md-3"><label>{{ __('School') }}</label><select class="form-control" name="school_id" required><option value="">{{ __('Select School') }}</option>@foreach($group->schools->where('status','active') as $member)<option value="{{ $member->school_id }}">{{ $member->school?->name }}</option>@endforeach</select></div>
                    <div class="form-group col-md-3"><label>{{ __('Existing School user ID') }}</label><input class="form-control" type="number" min="1" name="tenant_user_id" required></div>
                    <div class="form-group col-md-2"><button class="btn btn-outline-primary" type="submit">{{ __('Save identity') }}</button></div>
                </form>
                <small class="text-muted d-block mb-2">{{ __('The selected existing School user is verified server-side in that School before this mapping is saved.') }}</small>
                <ul class="mb-0">
                    @foreach($group->users as $groupUser)
                        @foreach($groupUser->tenantIdentities->where('status','active') as $identity)
                            <li>{{ $groupUser->centralUser?->full_name ?? ('User #'.$groupUser->central_user_id) }} → {{ $identity->school?->name }} ({{ __('School user') }} #{{ $identity->tenant_user_id }})</li>
                        @endforeach
                    @endforeach
                </ul>
            </div></details>
        </div></section>
    </div>
    <x-central-finance.lifecycle-confirmation />
@endsection
