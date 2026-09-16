@extends('layouts.master')

@section('title', __('Finance Staff Onboarding'))

@section('content')
<div class="content-wrapper">
    <div class="page-header d-flex flex-wrap align-items-center justify-content-between">
        <div>
            <h3 class="page-title mb-1">{{ __('Finance Staff Onboarding') }}</h3>
            <p class="text-muted mb-0">{{ __('Assign a School identity role first. Super Admin grants the separate Central Finance School scope afterwards.') }}</p>
        </div>
        <a class="btn btn-outline-secondary mt-2 mt-md-0" href="{{ route('staff.index') }}">{{ __('Back to Staff') }}</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="card mb-4"><div class="card-body">
        <h4 class="card-title">{{ __('Assign tenant role') }}</h4>
        <div class="alert alert-info">
            {{ __('This action keeps all existing roles. It does not grant Central Finance scope, Fund Account access, tuition collection, Payment, Receipt, or Ledger authority.') }}
        </div>
        <form method="POST" action="{{ route('staff.finance-onboarding.store') }}">
            @csrf
            <div class="form-group">
                <label for="onboarding-staff">{{ __('Existing Staff') }} *</label>
                <select id="onboarding-staff" class="form-control" name="staff_id" required>
                    <option value="">{{ __('Select Staff') }}</option>
                    @foreach($staff as $member)
                        <option value="{{ $member->id }}" @selected((string) old('staff_id') === (string) $member->id)>
                            {{ $member->full_name }}{{ $member->email ? ' · '.$member->email : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <fieldset class="form-group">
                <legend class="h6">{{ __('Approved tenant roles') }} *</legend>
                @foreach($roleDescriptions as $role => $description)
                    <div class="form-check mb-3">
                        <label class="form-check-label" for="onboarding-role-{{ $loop->index }}">
                            <input id="onboarding-role-{{ $loop->index }}" class="form-check-input" type="checkbox" name="roles[]" value="{{ $role }}" @checked(in_array($role, old('roles', []), true))>
                            <i class="input-helper" aria-hidden="true"></i>
                            <span class="d-block">
                                <strong>{{ __($role) }}</strong><br>
                                <span class="text-muted">{{ __($description) }}</span>
                            </span>
                        </label>
                    </div>
                @endforeach
            </fieldset>
            <div class="form-group">
                <label for="onboarding-reason">{{ __('Audit reason') }} *</label>
                <textarea id="onboarding-reason" class="form-control" name="reason" maxlength="2000" required>{{ old('reason') }}</textarea>
            </div>
            <button class="btn btn-theme" type="submit">{{ __('Assign role and record audit') }}</button>
        </form>
    </div></div>

    <div class="card"><div class="card-body">
        <h4 class="card-title">{{ __('Current Staff roles') }}</h4>
        <div class="table-responsive"><table class="table">
            <thead><tr><th>{{ __('Staff') }}</th><th>{{ __('Roles') }}</th></tr></thead>
            <tbody>
            @forelse($staff as $member)
                <tr><td>{{ $member->full_name }}<div class="small text-muted">{{ $member->email }}</div></td><td>{{ $member->roles->pluck('name')->join(', ') ?: '—' }}</td></tr>
            @empty
                <tr><td colspan="2" class="text-muted">{{ __('No active Staff are available.') }}</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div></div>
</div>
@endsection
