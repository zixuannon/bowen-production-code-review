@extends('layouts.master')

@section('title', __('Finance Staff'))

@section('content')
<div class="content-wrapper">
    <div class="page-header"><h3 class="page-title">{{ __('Finance Staff') }}</h3></div>
    <div class="card"><div class="card-body">
        <button type="button" class="btn btn-theme float-right" data-toggle="modal" data-target="#addAccountantModal"><i class="fa fa-plus"></i> {{ __('Add Accountant') }}</button>
        <p class="text-muted">{{ __('Manage Finance roles and Accountant Fund Account access for the current school.') }}</p>
        <div class="table-responsive"><table class="table table-striped" id="finance-staff-table">
            <thead><tr><th>{{ __('User') }}</th><th>{{ __('Finance Role') }}</th><th>{{ __('Fund Accounts') }}</th><th>{{ __('Status') }}</th><th>{{ __('Actions') }}</th></tr></thead>
            <tbody>@foreach($users as $user)
                @php($roles = collect(['Head Finance', 'Cashier'])->filter(fn ($role) => $user->hasRole($role))->values())
                <tr>
                    <td>{{ $user->full_name }}</td>
                    <td>{{ $roles->isNotEmpty() ? $roles->map(fn ($role) => $role === 'Cashier' ? __('Accountant') : $role)->implode(', ') : __('No Finance Role') }}</td>
                    <td>{{ $user->authorized_bank_accounts->pluck('account_name')->implode(', ') ?: __('None assigned') }}</td>
                    <td><span class="badge badge-{{ $user->status ? 'success' : 'secondary' }}">{{ $user->status ? __('Active') : __('Inactive') }}</span></td>
                    <td>
                        @if($actor->hasRole('School Admin') || ($actor->hasRole('Head Finance') && $user->hasRole('Cashier')))
                            <button type="button" class="btn btn-sm btn-outline-primary finance-staff-manage"
                                data-user-id="{{ $user->id }}"
                                data-user-name="{{ e($user->full_name) }}"
                                data-roles="{{ $roles->implode('|') }}"
                                data-account-ids="{{ $user->authorized_bank_accounts->pluck('id')->implode(',') }}"
                                data-is-cashier="{{ $roles->contains('Cashier') ? '1' : '0' }}">
                                <i class="fa fa-cog"></i> {{ __('Manage') }}
                            </button>
                        @else
                            <span class="text-muted">{{ __('No action') }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach</tbody>
        </table></div>
    </div></div>
</div>

<div class="modal fade" id="addAccountantModal" tabindex="-1" role="dialog" aria-labelledby="addAccountantTitle" aria-hidden="true"><div class="modal-dialog modal-lg" role="document"><div class="modal-content"><form id="add-accountant-form"><div class="modal-header"><h5 class="modal-title" id="addAccountantTitle">{{ __('Add Accountant') }}</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div><div class="modal-body"><div id="add-accountant-error" class="alert alert-danger d-none"></div><p class="text-muted">{{ __('A secure password setup link will be sent to the new Accountant.') }}</p><div class="row"><div class="form-group col-md-6"><label>{{ __('First Name') }} *</label><input class="form-control" name="first_name" required></div><div class="form-group col-md-6"><label>{{ __('Last Name') }} *</label><input class="form-control" name="last_name" required></div><div class="form-group col-md-6"><label>{{ __('Email') }} *</label><input type="email" class="form-control" name="email" required></div><div class="form-group col-md-6"><label>{{ __('Mobile') }}</label><input class="form-control" name="mobile"></div></div><h6>{{ __('Fund Accounts') }}</h6><p class="text-muted small">{{ __('Optional. An Accountant without an assigned Fund Account can log in but cannot access Fund Accounts until one is assigned.') }}</p>@foreach($accounts as $account)<div class="form-check"><label class="form-check-label"><input class="form-check-input" type="checkbox" name="account_ids[]" value="{{ $account->id }}"> {{ $account->account_name }}</label></div>@endforeach</div><div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">{{ __('Cancel') }}</button><button class="btn btn-theme" type="submit">{{ __('Create Accountant') }}</button></div></form></div></div></div>

<div class="modal fade" id="financeStaffModal" tabindex="-1" role="dialog" aria-labelledby="financeStaffModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="financeStaffModalTitle">{{ __('Manage Finance Staff') }}</h5><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Close') }}"><span>&times;</span></button></div>
        <div class="modal-body">
            <div id="finance-staff-error" class="alert alert-danger d-none" role="alert"></div>
            <dl class="row mb-3"><dt class="col-sm-4">{{ __('User') }}</dt><dd class="col-sm-8" id="finance-staff-user"></dd><dt class="col-sm-4">{{ __('Current Finance Role') }}</dt><dd class="col-sm-8" id="finance-staff-role"></dd><dt class="col-sm-4">{{ __('Assigned Fund Accounts') }}</dt><dd class="col-sm-8" id="finance-staff-current-accounts"></dd></dl>
            @if($actor->hasRole('School Admin'))
                <div id="finance-staff-role-actions" class="border rounded p-3 mb-3">
                    <h6>{{ __('Finance Role') }}</h6><p class="text-muted small">{{ __('Choose a clear role action. Removing Accountant also removes that user’s Fund Account assignments.') }}</p>
                    <button type="button" class="btn btn-sm btn-outline-success finance-role-action" data-role="Cashier" data-action="assign">{{ __('Assign Accountant') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-primary finance-role-action" data-role="Head Finance" data-action="assign">{{ __('Assign Head Finance') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-danger finance-role-action" data-role="Cashier" data-action="remove">{{ __('Remove Accountant') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-danger finance-role-action" data-role="Head Finance" data-action="remove">{{ __('Remove Head Finance') }}</button>
                </div>
            @endif
            <form id="finance-staff-accounts-form">
                <h6>{{ __('Accountant Fund Accounts') }}</h6><p class="text-muted small">{{ __('Only active current-school Fund Accounts can be assigned.') }}</p>
                <div id="finance-staff-account-options" class="row">@foreach($accounts as $account)<div class="col-md-6"><div class="form-check mb-2"><label class="form-check-label"><input class="form-check-input finance-staff-account" type="checkbox" value="{{ $account->id }}"> {{ $account->account_name }}</label></div></div>@endforeach</div>
                <div class="modal-footer px-0 pb-0"><button type="button" class="btn btn-light" data-dismiss="modal">{{ __('Cancel') }}</button><button type="submit" class="btn btn-theme">{{ __('Save Fund Accounts') }}</button></div>
            </form>
        </div>
        <div class="modal-footer" id="finance-staff-close-actions"><button type="button" class="btn btn-light" data-dismiss="modal">{{ __('Close') }}</button></div>
    </div></div>
</div>
@endsection

@section('js')
<script>
const financeStaffToken = '{{ csrf_token() }}';
let financeStaffUser = null;
function financeStaffError(message) { const box = document.getElementById('finance-staff-error'); box.textContent = message; box.classList.remove('d-none'); }
function financeStaffClearError() { const box = document.getElementById('finance-staff-error'); box.textContent = ''; box.classList.add('d-none'); }
function financeStaffRefresh() { window.location.assign('{{ route('finance-staff.index') }}'); }
function financeStaffRoles() { return financeStaffUser.roles.length ? financeStaffUser.roles.map(role => role === 'Cashier' ? '{{ __('Accountant') }}' : role).join(', ') : '{{ __('No Finance Role') }}'; }
function financeStaffAccounts() { const selected = [...document.querySelectorAll('#finance-staff-accounts-form .finance-staff-account:checked')].map(input => input.parentElement.textContent.trim()); return selected.length ? selected.join(', ') : '{{ __('None assigned') }}'; }
document.querySelectorAll('.finance-staff-manage').forEach(button => button.addEventListener('click', () => {
    const roles = button.dataset.roles.split('|').filter(Boolean);
    const accountIds = button.dataset.accountIds.split(',').filter(Boolean);
    financeStaffUser = {id: button.dataset.userId, name: button.dataset.userName, roles, accountIds, isCashier: button.dataset.isCashier === '1'};
    financeStaffClearError();
    document.getElementById('finance-staff-user').textContent = financeStaffUser.name;
    document.getElementById('finance-staff-role').textContent = financeStaffRoles();
    document.querySelectorAll('.finance-staff-account').forEach(input => input.checked = financeStaffUser.accountIds.map(String).includes(input.value));
    document.getElementById('finance-staff-current-accounts').textContent = financeStaffAccounts();
    document.querySelectorAll('.finance-role-action').forEach(action => {
        const assigned = financeStaffUser.roles.includes(action.dataset.role);
        action.classList.toggle('d-none', action.dataset.action === 'assign' ? assigned : !assigned);
    });
    const canManageAccounts = financeStaffUser.isCashier || financeStaffUser.roles.includes('Cashier');
    document.querySelectorAll('.finance-staff-account, #finance-staff-accounts-form button[type="submit"]').forEach(input => input.disabled = !canManageAccounts);
    document.getElementById('finance-staff-accounts-form').classList.toggle('opacity-50', !canManageAccounts);
    document.getElementById('finance-staff-close-actions').classList.remove('d-none');
    $('#financeStaffModal').modal('show');
}));
document.querySelectorAll('.finance-role-action').forEach(button => button.addEventListener('click', async () => {
    if (!financeStaffUser) return;
    financeStaffClearError();
    const role = button.dataset.role;
    if (!role || (button.dataset.action === 'remove' && !financeStaffUser.roles.includes(role))) return financeStaffError('{{ __('This user does not have that Finance Role.') }}');
    const response = await fetch(`/finance-staff/${financeStaffUser.id}/role`, {method: 'POST', headers: {'X-CSRF-TOKEN': financeStaffToken, 'Accept': 'application/json', 'Content-Type': 'application/json'}, body: JSON.stringify({role, action: button.dataset.action})});
    if (!response.ok) return financeStaffError((await response.json().catch(() => ({}))).message || '{{ __('Unable to update Finance Role.') }}');
    financeStaffRefresh();
}));
document.getElementById('finance-staff-accounts-form').addEventListener('submit', async event => {
    event.preventDefault(); if (!financeStaffUser) return;
    financeStaffClearError();
    const account_ids = [...event.currentTarget.querySelectorAll('.finance-staff-account:checked')].map(input => Number(input.value));
    const response = await fetch(`/finance-staff/${financeStaffUser.id}/accounts`, {method: 'PUT', headers: {'X-CSRF-TOKEN': financeStaffToken, 'Accept': 'application/json', 'Content-Type': 'application/json'}, body: JSON.stringify({account_ids})});
    if (!response.ok) return financeStaffError((await response.json().catch(() => ({}))).message || '{{ __('Unable to update Fund Accounts.') }}');
    financeStaffRefresh();
});
document.getElementById('add-accountant-form').addEventListener('submit', async event => { event.preventDefault(); const form=event.currentTarget; const error=document.getElementById('add-accountant-error'); error.classList.add('d-none'); const response=await fetch('{{ route('finance-staff.store') }}',{method:'POST',headers:{'X-CSRF-TOKEN':financeStaffToken,'Accept':'application/json'},body:new FormData(form)}); if(!response.ok){const body=await response.json().catch(()=>({})); error.textContent=body.message||'{{ __('Unable to create Accountant.') }}'; error.classList.remove('d-none'); return;} financeStaffRefresh(); });
</script>
@endsection
