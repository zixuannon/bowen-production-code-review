@extends('layouts.master')
@section('content')
<div class="container-fluid py-3 central-finance-page">
@include('central-finance.partials.foundation-styles')
@include('central-finance.partials.data-visibility-toggle')
<div class="card"><div class="card-body">
    <p class="text-uppercase text-muted mb-1">{{ $isHeadFinance ? __('Central Finance') : __('School Finance') }} · {{ $school->name }}</p>
    <h3>{{ __('Collection Handover Batches') }}</h3>
    <p class="text-muted">{{ __('A handover summary is not an official receipt. Official receipts are issued only after Head Finance confirmation.') }}</p>
    <div class="ui-status-timeline mb-3" aria-label="{{ __('Handover workflow') }}"><span class="ui-status-timeline__step is-current">{{ __('Draft') }}</span><span class="ui-status-timeline__step">{{ __('Submitted') }}</span><span class="ui-status-timeline__step">{{ __('Finance review') }}</span><span class="ui-status-timeline__step">{{ __('Receipt issued') }}</span></div>

    @if($errors->any())
        <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    @if(!$isHeadFinance && $productionSchool)
    <form method="POST" action="{{ route('central-finance.collection-handovers.store') }}" class="row g-2">@csrf
        <div class="col-md-2"><label>{{ __('Channel') }}</label><select name="payment_channel" class="form-control" required><option>Cash</option><option>Bank Transfer</option><option>QR / Wallet</option></select></div>
        <div class="col-md-1"><label>{{ __('Currency') }}</label><input name="currency" class="form-control" value="MMK" maxlength="3" required></div>
        <div class="col-md-2"><label>{{ __('Reference') }}</label><input name="reference" class="form-control" required></div>
        <div class="col-md-2"><label>{{ __('Declared amount') }}</label><input name="declared_handed_over_amount" type="number" step="0.0001" min="0.0001" class="form-control" required></div>
        <div class="col-md-2"><label>{{ __('Idempotency key') }}</label><input name="idempotency_key" class="form-control" required></div>
        <div class="col-md-2 align-self-end"><button class="btn btn-primary">{{ __('Create draft') }}</button></div>
    </form>
    @endif

    <p class="ui-table-scroll-hint mt-4"><i class="fa fa-arrows-h" aria-hidden="true"></i> {{ __('Swipe horizontally to review every handover field.') }}</p>
    <div class="table-responsive ui-responsive-list-wrap mt-4"><table class="table cf-data-table ui-responsive-list ui-mobile-cards">
    <thead><tr><th>{{ __('Reference') }}</th><th>{{ __('Channel') }}</th><th>{{ __('Items') }}</th><th>{{ __('Expected') }}</th><th>{{ __('Declared') }}</th><th>{{ __('Actual') }}</th><th>{{ __('Difference') }}</th><th>{{ __('Status') }}</th><th>{{ __('Actions') }}</th></tr></thead>
    <tbody>
    @forelse($batches as $batch)
        @php($attachedItems = $batch->items->where('status', \App\Models\CentralFinanceCollectionHandoverItem::ATTACHED))
        @php($displayExpected = $batch->status === 'draft' ? $attachedItems->sum(fn($item) => (float) $item->expected_amount_snapshot) : $batch->expected_amount)
        <tr>
            <td data-label="{{ __('Reference') }}"><span class="ui-cell-primary">{{ $batch->reference }}</span><span class="ui-cell-secondary">{{ $batch->currency }}</span></td>
            <td data-label="{{ __('Channel') }}">{{ $batch->payment_channel }} · {{ $batch->currency }}</td>
            <td data-label="{{ __('Items') }}">{{ $attachedItems->count() }}</td>
            <td data-label="{{ __('Expected') }}" class="ui-handover-amount">{{ number_format((float) $displayExpected, 2) }} {{ $batch->currency }}</td>
            <td data-label="{{ __('Declared') }}" class="ui-handover-amount">{{ number_format((float) $batch->declared_handed_over_amount, 2) }} {{ $batch->currency }}</td>
            <td data-label="{{ __('Actual') }}" class="ui-handover-amount">{{ $batch->actual_handed_over_amount === null ? '—' : number_format((float) $batch->actual_handed_over_amount, 2).' '.$batch->currency }}</td>
            <td data-label="{{ __('Difference') }}" class="ui-handover-amount">{{ $batch->difference_amount === null ? '—' : number_format((float) $batch->difference_amount, 2).' '.$batch->currency }}</td>
            <td data-label="{{ __('Status') }}"><span class="badge badge-light">{{ __($batch->status) }}</span><div class="ui-status-timeline" aria-label="{{ __('Handover workflow') }}"><span class="ui-status-timeline__step {{ in_array($batch->status, ['draft','submitted','held','confirmed','rejected','cancelled'], true) ? 'is-complete' : '' }}">{{ __('Draft') }}</span><span class="ui-status-timeline__step {{ in_array($batch->status, ['submitted','held','confirmed','rejected'], true) ? 'is-complete' : '' }}">{{ __('Submitted') }}</span><span class="ui-status-timeline__step {{ in_array($batch->status, ['held','confirmed','rejected'], true) ? 'is-complete' : ($batch->status === 'submitted' ? 'is-current' : '') }}">{{ __('Finance review') }}</span><span class="ui-status-timeline__step {{ $batch->status === 'confirmed' ? 'is-complete' : '' }}">{{ __('Receipt issued') }}</span></div></td>
            <td data-label="{{ __('Actions') }}">
                <details><summary>{{ __('Review') }}</summary><div class="mt-2">
                    @foreach($attachedItems as $item)
                        <div class="border rounded p-2 mb-2 small">
                            <strong>{{ $item->pendingCollection?->acknowledgement_no }}</strong>
                            · {{ $item->pendingCollection?->studentProfile?->student_name }}
                            · {{ number_format((float) $item->expected_amount_snapshot, 2) }} {{ $item->currency_snapshot }}
                            @if($batch->production_eligible && !$isHeadFinance && $batch->status === 'draft')
                                <form method="POST" action="{{ route('central-finance.collection-handovers.items.remove', [$batch, $item]) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-danger ml-1">{{ __('Remove') }}</button></form>
                            @endif
                        </div>
                    @endforeach

                    @if($batch->production_eligible && !$isHeadFinance && $batch->status === 'draft')
                        @php($candidates = $eligiblePending->filter(fn($row) => strtoupper((string) $row->currency) === strtoupper((string) $batch->currency) && (string) $row->payment_method === (string) $batch->payment_channel))
                        <form method="POST" action="{{ route('central-finance.collection-handovers.items.store', $batch) }}" class="mb-2">@csrf
                            <label>{{ __('Add submitted collection') }}</label>
                            <select name="pending_collection_id" class="form-control" required><option value="">{{ __('Select pending collection') }}</option>@foreach($candidates as $pending)<option value="{{ $pending->id }}">{{ $pending->acknowledgement_no }} · {{ $pending->studentProfile?->student_name }} · {{ number_format((float) $pending->amount, 2) }} {{ $pending->currency }}</option>@endforeach</select>
                            <button class="btn btn-sm btn-outline-primary mt-2" @disabled($candidates->isEmpty())>{{ __('Add item') }}</button>
                        </form>
                        <form method="POST" action="{{ route('central-finance.collection-handovers.submit', $batch) }}" class="d-inline">@csrf<button class="btn btn-sm btn-primary" @disabled($attachedItems->isEmpty())>{{ __('Submit') }}</button></form>
                        <form method="POST" action="{{ route('central-finance.collection-handovers.cancel', $batch) }}" class="mt-2">@csrf<input class="form-control" name="reason" placeholder="{{ __('Cancellation reason') }}" required maxlength="2000"><button class="btn btn-sm btn-outline-danger mt-1">{{ __('Cancel') }}</button></form>
                    @endif

                    @if($batch->production_eligible && $isHeadFinance && in_array($batch->status, ['submitted', 'held'], true))
                        @if($batch->status === 'submitted')
                        <form method="POST" action="{{ route('central-finance.collection-handovers.confirm', $batch) }}" class="mb-2">@csrf
                            <label>{{ __('Actual Fund Account') }}</label>
                            <select class="form-control" name="fund_account_id" required><option value="">{{ __('Select Fund Account') }}</option>@foreach($accounts->where('currency', $batch->currency) as $account)<option value="{{ $account->id }}">{{ $account->account_name }} · {{ $account->currency }}</option>@endforeach</select>
                            <label class="mt-2">{{ __('Actual received amount') }}</label><input class="form-control" name="actual_received_amount" type="number" min="0" step="0.0001" required>
                            <label class="mt-2">{{ __('Confirmation reason') }}</label><input class="form-control" name="reason" required maxlength="2000">
                            <button class="btn btn-sm btn-primary mt-2">{{ __('Confirm') }}</button>
                        </form>
                        @endif
                        <form method="POST" action="{{ route('central-finance.collection-handovers.hold', $batch) }}" class="mb-2">@csrf<input class="form-control" name="reason" placeholder="{{ __('Hold reason') }}" required maxlength="2000"><button class="btn btn-sm btn-outline-warning mt-1">{{ __('Hold') }}</button></form>
                        <form method="POST" action="{{ route('central-finance.collection-handovers.reject', $batch) }}">@csrf<input class="form-control" name="reason" placeholder="{{ __('Rejection reason') }}" required maxlength="2000"><button class="btn btn-sm btn-outline-danger mt-1">{{ __('Reject') }}</button></form>
                    @endif
                    @if(!$batch->production_eligible)<span class="badge badge-secondary">{{ __('Read-only QA/Test history') }}</span>@endif
                </div></details>
            </td>
        </tr>
    @empty
        <tr><td colspan="9" data-label=""><div class="cf-empty-state">{{ __('No handover batches.') }}</div></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $batches->links() }}
</div></div>
</div>
@endsection
