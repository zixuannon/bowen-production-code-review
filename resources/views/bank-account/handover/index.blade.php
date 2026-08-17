@extends('layouts.master')

@section('title', __('Fund Handover'))

@section('content')
<div class="content-wrapper">
    <div class="page-header"><h3 class="page-title">{{ __('Fund Handover') }}</h3></div>
    @if($canParticipate)
    <div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
        <h4 class="card-title">{{ __('New Pending Handover') }}</h4>
        <p class="text-muted">{{ __('A pending handover does not change balances. The designated receiver must confirm before a transfer is recorded. Fund Handovers are between Head Finance and Accountant.') }}</p>
        <form id="fund-handover-form"><input type="hidden" name="_token" value="{{ csrf_token() }}">
            <div class="row">
                <div class="form-group col-md-3"><label>{{ __('Receiver') }}</label><select class="form-control" name="receiver_id" id="receiver_id" required><option value="">{{ __('Select receiver') }}</option>@foreach($recipients as $user)<option value="{{ $user->id }}">{{ $user->first_name }} {{ $user->last_name }}</option>@endforeach</select></div>
                <div class="form-group col-md-3"><label>{{ __('From / Sender Fund Account') }}</label><select class="form-control" name="from_account_id" id="from_account_id" required><option value="">{{ __('Select account') }}</option>@foreach($senderAccounts as $account)<option value="{{ $account->id }}" data-balance="{{ $account->current_balance }}">{{ $account->account_name }} ({{ $account->currency }})</option>@endforeach</select><small class="text-muted" id="available-balance">{{ __('Select a Fund Account to view its available balance.') }}</small></div>
                <div class="form-group col-md-3">
                    <label>{{ __('Receiver Account') }}</label>
                    <select class="form-control" name="to_account_id" id="to_account_id" required disabled>
                        <option value="">{{ __('Select receiver first') }}</option>
                    </select>
                    <small class="form-text text-danger d-none" id="receiver-account-empty-state" role="status">
                        {{ __('This finance staff member has no assigned Fund Account. Please assign one in Finance Staff first.') }}
                        @if($canManageFinanceStaff)
                            <a href="{{ route('finance-staff.index') }}" class="d-inline-block ml-1">{{ __('Go to Finance Staff') }}</a>
                        @endif
                    </small>
                </div>
                <div class="form-group col-md-3"><label>{{ __('Amount') }}</label><input class="form-control" name="amount" type="number" step="0.01" min="0.01" required></div>
                <div class="form-group col-md-3"><label>{{ __('Handover Date') }}</label><input class="form-control" name="handover_date" type="date" value="{{ now()->toDateString() }}" required></div>
                <div class="form-group col-md-3"><label>{{ __('Reference No.') }}</label><input class="form-control" name="reference_no" maxlength="100"></div>
                <div class="form-group col-md-6"><label>{{ __('Notes') }}</label><input class="form-control" name="notes" maxlength="1000"></div>
            </div>
            <button class="btn btn-theme" type="submit" id="request-handover-submit" disabled>{{ __('Request Handover') }}</button>
        </form>
    </div></div></div></div>
    @else
    <div class="alert alert-info">{{ __('Read-only oversight: School Admin can review current-school Fund Handovers and their audit history.') }}</div>
    @endif
    <div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
        <h4 class="card-title">{{ $canParticipate ? __('My Fund Handovers') : __('Fund Handover Register') }}</h4>
        <table class="table" id="handover-table"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Reference') }}</th><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th>{{ __('Sender') }}</th><th>{{ __('Receiver') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Status') }}</th><th>{{ __('Audit') }}</th><th>{{ __('Action') }}</th></tr></thead><tbody></tbody></table>
    </div></div></div></div>
</div>

<div class="modal fade" id="handoverActionModal" tabindex="-1" role="dialog" aria-labelledby="handoverActionTitle" aria-hidden="true">
    <div class="modal-dialog" role="document"><div class="modal-content"><form id="handover-action-form">
        <div class="modal-header"><h5 class="modal-title" id="handoverActionTitle"></h5><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Close') }}"><span aria-hidden="true">&times;</span></button></div>
        <div class="modal-body"><p id="handoverActionMessage"></p><div class="form-group d-none" id="handoverReasonGroup"><label for="handover_reason">{{ __('Reason') }}</label><textarea class="form-control" id="handover_reason" maxlength="1000"></textarea><div class="invalid-feedback">{{ __('A reason is required.') }}</div></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">{{ __('Cancel') }}</button><button type="submit" class="btn btn-theme" id="handoverActionSubmit"></button></div>
    </form></div></div>
</div>
@endsection

@section('js')
<script>
const recipientAccounts = @json($recipientAccounts);
const csrf = '{{ csrf_token() }}';
function toast(message, ok = false) { ok ? showSuccessToast(message) : showErrorToast(message); }
function loadRecipientAccounts() {
    const receiver = document.getElementById('receiver_id').value;
    const target = document.getElementById('to_account_id');
    const emptyState = document.getElementById('receiver-account-empty-state');
    const submit = document.getElementById('request-handover-submit');
    const accounts = recipientAccounts[receiver] || [];
    target.innerHTML = '<option value="">{{ __('Select account') }}</option>';
    accounts.forEach(account => target.add(new Option(`${account.account_name} (${account.currency})`, account.id)));
    const unavailable = !receiver || accounts.length === 0;
    target.disabled = unavailable;
    emptyState.classList.toggle('d-none', !receiver || accounts.length > 0);
    submit.disabled = true;
}
function updateHandoverSubmitState() {
    const target = document.getElementById('to_account_id');
    document.getElementById('request-handover-submit').disabled = target.disabled || !target.value;
}
function loadAvailableBalance() {
    const option = document.querySelector('#from_account_id option:checked');
    const balance = option && option.dataset.balance;
    document.getElementById('available-balance').textContent = balance === undefined || !option.value
        ? '{{ __('Select a Fund Account to view its available balance.') }}'
        : '{{ __('Available balance:') }} ' + Number(balance).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
function actionButton(row, label, action) { return `<button type="button" class="btn btn-sm btn-outline-primary handover-action" data-id="${row.id}" data-action="${action}">${label}</button>`; }
async function loadHandovers() {
    const response = await fetch('{{ route('fund-handovers.list') }}'); const data = await response.json();
    document.querySelector('#handover-table tbody').innerHTML = data.rows.map(row => {
        let actions = ''; if (row.can_confirm) actions += actionButton(row, '{{ __('Confirm') }}', 'confirm') + ' ' + actionButton(row, '{{ __('Reject') }}', 'reject'); if (row.can_cancel) actions += actionButton(row, '{{ __('Cancel') }}', 'cancel');
        return `<tr><td>${row.handover_date}</td><td>${row.reference_no || '-'}</td><td>${row.from_account_name || '-'}</td><td>${row.to_account_name || '-'}</td><td>${row.sender_name}</td><td>${row.receiver_name}</td><td>${row.amount}</td><td>${row.status}</td><td>${row.audit || '-'}</td><td>${actions}</td></tr>`;
    }).join('');
}
const canParticipate = @json($canParticipate);
if (canParticipate) {
document.getElementById('receiver_id').addEventListener('change', loadRecipientAccounts);
document.getElementById('to_account_id').addEventListener('change', updateHandoverSubmitState);
document.getElementById('from_account_id').addEventListener('change', loadAvailableBalance);
document.getElementById('fund-handover-form').addEventListener('submit', async event => {
    event.preventDefault(); const response = await fetch('{{ route('fund-handovers.store') }}', {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'}, body: new FormData(event.target)}); const data = await response.json();
    if (response.ok && !data.error) { toast(data.message, true); event.target.reset(); loadRecipientAccounts(); loadHandovers(); } else toast(data.message || '{{ __('Something went wrong.') }}');
});
}
document.querySelector('#handover-table tbody').addEventListener('click', async event => {
    const button = event.target.closest('.handover-action'); if (!button) return;
    const action = button.dataset.action;
    const copy = action === 'confirm'
        ? {title: '{{ __('Confirm Receipt') }}', message: '{{ __('Confirming receipt will create the actual Fund Transfer / BankTransfer. This cannot be undone from the transfer screen.') }}', submit: '{{ __('Confirm Receipt') }}', reason: false}
        : action === 'reject'
            ? {title: '{{ __('Reject Handover') }}', message: '{{ __('Explain why this pending handover is rejected.') }}', submit: '{{ __('Reject') }}', reason: true}
            : {title: '{{ __('Cancel Handover') }}', message: '{{ __('Explain why this pending handover is cancelled.') }}', submit: '{{ __('Cancel Handover') }}', reason: true};
    document.getElementById('handoverActionTitle').textContent = copy.title;
    document.getElementById('handoverActionMessage').textContent = copy.message;
    document.getElementById('handoverActionSubmit').textContent = copy.submit;
    document.getElementById('handoverReasonGroup').classList.toggle('d-none', !copy.reason);
    document.getElementById('handover_reason').value = '';
    document.getElementById('handover_reason').classList.remove('is-invalid');
    document.getElementById('handover-action-form').dataset.id = button.dataset.id;
    document.getElementById('handover-action-form').dataset.action = action;
    document.getElementById('handover-action-form').dataset.reasonRequired = copy.reason ? '1' : '0';
    $('#handoverActionModal').modal('show');
});
document.getElementById('handover-action-form').addEventListener('submit', async event => {
    event.preventDefault(); const form = event.currentTarget; const reason = document.getElementById('handover_reason');
    if (form.dataset.reasonRequired === '1' && !reason.value.trim()) { reason.classList.add('is-invalid'); reason.focus(); return; }
    let body = new FormData(); body.append('_token', csrf); if (form.dataset.reasonRequired === '1') body.append('reason', reason.value.trim());
    const response = await fetch(`/fund-handovers/${form.dataset.id}/${form.dataset.action}`, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'}, body}); const data = await response.json();
    if (response.ok && !data.error) { toast(data.message, true); loadHandovers(); } else toast(data.message || '{{ __('Something went wrong.') }}');
    if (response.ok && !data.error) $('#handoverActionModal').modal('hide');
});
loadHandovers();
</script>
@endsection
