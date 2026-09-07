@extends('layouts.master')
@section('content')
<div class="container-fluid py-3"><div class="card"><div class="card-body">
<h3>{{ __('Collection Handover Batches') }}</h3>
<p class="text-muted">{{ __('A handover summary is not an official receipt. Official receipts are issued only after Head Finance confirmation.') }}</p>
<form method="POST" action="{{ route('central-finance.collection-handovers.store') }}" class="row g-2">@csrf
<div class="col-md-2"><label>{{ __('Channel') }}</label><select name="payment_channel" class="form-control" required><option>Cash</option><option>Bank Transfer</option><option>QR / Wallet</option></select></div>
<div class="col-md-1"><label>{{ __('Currency') }}</label><input name="currency" class="form-control" value="MMK" maxlength="3" required></div>
<div class="col-md-2"><label>{{ __('Reference') }}</label><input name="reference" class="form-control" required></div>
<div class="col-md-2"><label>{{ __('Declared amount') }}</label><input name="declared_handed_over_amount" type="number" step="0.0001" min="0.0001" class="form-control" required></div>
<div class="col-md-2"><label>{{ __('Idempotency key') }}</label><input name="idempotency_key" class="form-control" required></div>
<div class="col-md-2 align-self-end"><button class="btn btn-primary">{{ __('Create draft') }}</button></div>
</form>
<div class="table-responsive mt-4"><table class="table"><thead><tr><th>{{ __('Reference') }}</th><th>{{ __('Channel') }}</th><th>{{ __('Expected') }}</th><th>{{ __('Declared') }}</th><th>{{ __('Status') }}</th><th>{{ __('Actions') }}</th></tr></thead><tbody>
@forelse($batches as $batch)<tr><td>{{ $batch->reference }}</td><td>{{ $batch->payment_channel }}</td><td>{{ $batch->expected_amount }}</td><td>{{ $batch->declared_handed_over_amount }}</td><td>{{ __($batch->status) }}</td><td>@if($batch->status === 'draft')<form method="POST" action="{{ route('central-finance.collection-handovers.submit',$batch) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-primary">{{ __('Submit') }}</button></form>@endif</td></tr>@empty<tr><td colspan="6">{{ __('No handover batches.') }}</td></tr>@endforelse
</tbody></table></div>{{ $batches->links() }}
</div></div></div>
@endsection
