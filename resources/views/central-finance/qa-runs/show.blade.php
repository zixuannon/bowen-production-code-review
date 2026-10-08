@extends('layouts.master')

@section('content')
<div class="container-fluid">
    <div class="alert alert-warning"><strong>QA ONLY</strong> — This Run and all of its records remain excluded from Official totals.</div>
    <h3>Zixuan QA Run #{{ $run->run_number }} — {{ $run->label }}</h3>
    <p>Status: <strong>{{ ucfirst($run->status) }}</strong> · Created {{ $run->created_at }}</p>
    @if($run->status === 'preparing')
        <p>Use the existing Student Import V2 workflow to provision fresh Students. Recover a partial import with the action below before activating.</p>
        <form method="POST" action="{{ route('central-finance.qa-runs.reconcile', $run->id) }}" class="d-inline">@csrf<button class="btn btn-outline-secondary">Reconcile Student Import V2</button></form>
        <form method="POST" action="{{ route('central-finance.qa-runs.activate', $run->id) }}" class="d-inline">@csrf<button class="btn btn-primary">Activate Run</button></form>
    @elseif($run->status === 'active')
        <form method="POST" action="{{ route('central-finance.qa-runs.complete', $run->id) }}" class="d-inline">@csrf<button class="btn btn-warning">Complete Run</button></form>
    @elseif($run->status === 'completed')
        <form method="POST" action="{{ route('central-finance.qa-runs.archive', $run->id) }}" class="form-inline mt-2">@csrf<label for="archive-reason" class="mr-2">Archive reason</label><input id="archive-reason" class="form-control mr-2" name="reason" maxlength="2000" required><button class="btn btn-secondary">Archive Run</button></form>
    @endif
    <div class="row mt-4">
    @foreach(['student' => 'Students', 'student_fee_assignment' => 'Fee Assignments', 'student_profile' => 'Profiles', 'promotion' => 'Promotions / Discounts', 'promotion_application' => 'Promotion Applications', 'student_discount_request' => 'Discount Requests', 'receivable' => 'Receivables', 'pending_collection' => 'Collections', 'pending_collection_allocation' => 'Collection Allocations', 'collection_handover' => 'Collection Handovers', 'collection_handover_item' => 'Handover Items', 'unidentified_deposit' => 'Unidentified Deposits', 'payment' => 'Payments', 'payment_allocation' => 'Payment Allocations', 'receipt' => 'Receipts', 'ledger' => 'Ledger', 'payment_refund' => 'Refunds', 'payment_reversal' => 'Reversals', 'receivable_adjustment' => 'Adjustments / Waivers / Corrections', 'audit' => 'Run Audit'] as $type => $title)
        @php($members = $run->records->where('subject_type', $type))
        <div class="col-xl-4 col-md-6 mb-3"><div class="card h-100"><div class="card-header">{{ $title }} <span class="badge badge-secondary">{{ $type === 'audit' ? $auditEntries->count() : $members->count() }}</span></div><div class="card-body">
            @if($type === 'audit')
                <ul class="mb-0">@forelse($auditEntries as $entry)<li><strong>{{ $entry->document_type }} · {{ $entry->action }}</strong> · actor #{{ $entry->actor_id }}<br><small>{{ $entry->reason }} · {{ $entry->created_at }}</small></li>@empty<li class="text-muted">No audit entries in this Run.</li>@endforelse</ul>
            @elseif($type === 'unidentified_deposit')
                <ul class="mb-2">@forelse($unidentifiedDeposits as $deposit)<li><strong>Deposit #{{ $deposit->id }} · {{ number_format((float) $deposit->amount, 2) }} {{ $deposit->currency }}</strong><br><small class="text-muted">{{ $deposit->received_date?->format('Y-m-d') }} · {{ $deposit->fundAccount?->account_code }} · {{ $deposit->fundAccount?->account_name }} · {{ $deposit->bank_reference ?: $deposit->manual_identity ?: 'No reference' }} · {{ ucfirst($deposit->status) }}</small></li>@empty<li class="text-muted">No unidentified deposits in this Run.</li>@endforelse</ul>
                @if($hasUnidentifiedDeposits)<a class="btn btn-sm btn-outline-primary" href="{{ route('central-finance.unidentified-deposits.index', ['include_qa_test' => 1, 'qa_run_id' => $run->id]) }}">Open Run deposits</a>@endif
            @else
                <ul class="mb-0">@forelse($members as $member)<li>{{ $member->subject_id ?? 'Provisioning: '.$member->source_identity }} <small class="text-muted">{{ $member->subject_scope }}</small></li>@empty<li class="text-muted">No records in this Run.</li>@endforelse</ul>
            @endif
        </div></div></div>
    @endforeach
    </div>
    <p><a href="{{ route('central-finance.qa-runs.index') }}">Back to QA Runs</a></p>
</div>
@endsection
