@extends('layouts.master')

@section('title', __('Fund Handover'))

@section('content')
<div class="content-wrapper">
    <div class="page-header"><h3 class="page-title">{{ __('Fund Handover') }}</h3></div>
    @if($canParticipate)
    <div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
        <h4 class="card-title">{{ __('New Pending Handover') }}</h4>
        <p class="text-muted">{{ __('A pending handover does not change balances. The designated receiver must confirm before a transfer is recorded.') }}</p>
        <form id="fund-handover-form"><input type="hidden" name="_token" value="{{ csrf_token() }}">
            <div class="row">
                <div class="form-group col-md-3"><label>{{ __('Receiver') }}</label><select class="form-control" name="receiver_id" id="receiver_id" required><option value="">{{ __('Select receiver') }}</option>@foreach($recipients as $user)<option value="{{ $user->id }}">{{ $user->first_name }} {{ $user->last_name }}</option>@endforeach</select></div>
                <div class="form-group col-md-3"><label>{{ __('From Account') }}</label><select class="form-control" name="from_account_id" id="from_account_id" required><option value="">{{ __('Select account') }}</option>@foreach($senderAccounts as $account)<option value="{{ $account->id }}">{{ $account->account_name }} ({{ $account->currency }})</option>@endforeach</select></div>
                <div class="form-group col-md-3"><label>{{ __('Receiver Account') }}</label><select class="form-control" name="to_account_id" id="to_account_id" required><option value="">{{ __('Select receiver first') }}</option></select></div>
                <div class="form-group col-md-3"><label>{{ __('Amount') }}</label><input class="form-control" name="amount" type="number" step="0.01" min="0.01" required></div>
                <div class="form-group col-md-3"><label>{{ __('Handover Date') }}</label><input class="form-control" name="handover_date" type="date" value="{{ now()->toDateString() }}" required></div>
                <div class="form-group col-md-3"><label>{{ __('Reference No.') }}</label><input class="form-control" name="reference_no" maxlength="100"></div>
                <div class="form-group col-md-6"><label>{{ __('Notes') }}</label><input class="form-control" name="notes" maxlength="1000"></div>
            </div>
            <button class="btn btn-theme" type="submit">{{ __('Request Handover') }}</button>
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
@endsection

@section('js')
<script>
const recipientAccounts = @json($recipientAccounts);
const csrf = '{{ csrf_token() }}';
function toast(message, ok = false) { ok ? showSuccessToast(message) : showErrorToast(message); }
function loadRecipientAccounts() {
    const receiver = document.getElementById('receiver_id').value;
    const target = document.getElementById('to_account_id');
    target.innerHTML = '<option value="">{{ __('Select account') }}</option>';
    (recipientAccounts[receiver] || []).forEach(account => target.add(new Option(`${account.account_name} (${account.currency})`, account.id)));
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
document.getElementById('fund-handover-form').addEventListener('submit', async event => {
    event.preventDefault(); const response = await fetch('{{ route('fund-handovers.store') }}', {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'}, body: new FormData(event.target)}); const data = await response.json();
    if (response.ok && !data.error) { toast(data.message, true); event.target.reset(); loadRecipientAccounts(); loadHandovers(); } else toast(data.message || '{{ __('Something went wrong.') }}');
});
}
document.querySelector('#handover-table tbody').addEventListener('click', async event => {
    const button = event.target.closest('.handover-action'); if (!button) return;
    let body = new FormData(); body.append('_token', csrf);
    if (button.dataset.action !== 'confirm') { const reason = window.prompt('{{ __('Reason is required') }}'); if (!reason) return; body.append('reason', reason); }
    const response = await fetch(`/fund-handovers/${button.dataset.id}/${button.dataset.action}`, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'}, body}); const data = await response.json();
    if (response.ok && !data.error) { toast(data.message, true); loadHandovers(); } else toast(data.message || '{{ __('Something went wrong.') }}');
});
loadHandovers();
</script>
@endsection
