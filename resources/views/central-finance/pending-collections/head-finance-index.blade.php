@extends('layouts.master')

@section('title', __('Pending collections'))

@section('content')
@include('central-finance.partials.data-visibility-toggle')

<div class="container-fluid py-3">
    <div class="card">
        <div class="card-body">
            <p class="text-uppercase text-muted mb-1">{{ __('Central Finance') }} · {{ $school->name }}</p>
            <h3>{{ __('Pending collections') }}</h3>
            <p class="text-muted mb-3">
                {{ __('Head Finance verifies the declared collection and confirms it once. Confirmation creates the canonical Payment, Receipt and Ledger entry; it does not change the declared student, receivable, amount or bank account.') }}
            </p>

            <div class="table-responsive">
                <table class="table cf-data-table">
                    <thead>
                    <tr>
                        <th>{{ __('Acknowledgement') }}</th>
                        <th>{{ __('Student') }}</th>
                        <th>{{ __('Fee / Receivable') }}</th>
                        <th>{{ __('Amount') }}</th>
                        <th>{{ __('Method / declared account') }}</th>
                        <th>{{ __('Collected / submitted') }}</th>
                        <th>{{ __('Reference / remarks') }}</th>
                        <th>{{ __('Action') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($pending as $row)
                        @php
                            $confirmedAccount = $accounts
                                ->where('currency', $row->currency)
                                ->filter(fn ($account) => (int) $account->id === (int) $row->intended_fund_account_id
                                    && $account->account_type === 'bank')
                                ->first();
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('central-finance.pending-collections.collection-receipt', $row) }}">{{ $row->acknowledgement_no }}</a>
                            </td>
                            <td>
                                {{ $row->studentProfile?->student_name }}
                                <small class="d-block text-muted">{{ $row->studentProfile?->student_code }}</small>
                            </td>
                            <td>@if($row->relationLoaded('allocations') && $row->allocations->isNotEmpty())@foreach($row->allocations as $allocation)<span class="d-block">{{ $allocation->receivable?->description ?: $allocation->description_snapshot }} · {{ number_format($allocation->amount, 2) }} {{ $allocation->currency }}</span>@endforeach@else{{ $row->receivable?->description ?? '—' }}@endif</td>
                            <td>{{ number_format($row->amount, 2) }} {{ $row->currency }}</td>
                            <td>
                                {{ $row->payment_method }}
                                <small class="d-block text-muted">{{ $row->intendedFundAccount?->account_name ?? __('Cash handover') }}</small>
                            </td>
                            <td>
                                {{ $row->collectedBy?->full_name ?? '—' }}
                                <small class="d-block text-muted">{{ __('Collected') }}: {{ $row->collected_at?->timezone('Asia/Yangon')->format('Y-m-d H:i') ?? '—' }}</small>
                                <small class="d-block text-muted">{{ __('Submitted') }}: {{ $row->submitted_at?->timezone('Asia/Yangon')->format('Y-m-d H:i') ?? '—' }}</small>
                            </td>
                            <td>
                                {{ $row->payment_reference ?: '—' }}
                                <small class="d-block text-muted">{{ $row->note ?: '' }}</small>
                            </td>
                            <td>
                                @if($row->workflow_eligible)
                                    <details>
                                        <summary>{{ __('Review') }}</summary>

                                        @if($row->payment_method === 'Bank Transfer')
                                            <form method="POST" action="{{ route('central-finance.pending-collections.confirm', $row) }}" class="mt-2">
                                                @csrf
                                                @if($confirmedAccount)
                                                    <input type="hidden" name="fund_account_id" value="{{ $confirmedAccount->id }}">
                                                    <label>{{ __('Declared Bank Fund Account') }}</label>
                                                    <input class="form-control" value="{{ $confirmedAccount->account_name }} · {{ $confirmedAccount->currency }}" readonly>
                                                    <small class="form-text text-muted">{{ __('The declared bank account is locked for confirmation. Hold or reject this collection if the evidence does not match it.') }}</small>
                                                @else
                                                    <div class="alert alert-warning mt-2 mb-2">
                                                        {{ __('The declared bank account is no longer eligible. Do not substitute another account; hold or reject this collection.') }}
                                                    </div>
                                                @endif
                                                <label class="mt-2">{{ __('Confirmation reason') }}</label>
                                                <input class="form-control" name="reason" required maxlength="2000">
                                                <button class="btn cf-primary-action mt-2" @disabled(!$confirmedAccount)>{{ __('Confirm') }}</button>
                                            </form>
                                        @else
                                            <div class="alert alert-info mt-2 mb-2">
                                                {{ __('Cash collections must be confirmed through a submitted Cash Handover. Direct confirmation is not available.') }}
                                            </div>
                                        @endif

                                        <form method="POST" action="{{ route('central-finance.pending-collections.hold', $row) }}" class="mt-2">
                                            @csrf
                                            <input class="form-control" name="reason" placeholder="{{ __('Hold reason') }}" required>
                                            <button class="btn btn-outline-warning mt-2">{{ __('Hold') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('central-finance.pending-collections.reject', $row) }}" class="mt-2">
                                            @csrf
                                            <input class="form-control" name="reason" placeholder="{{ __('Rejection reason') }}" required>
                                            <button class="btn btn-outline-danger mt-2">{{ __('Reject') }}</button>
                                        </form>
                                    </details>
                                @else
                                    <span class="badge badge-secondary">{{ __('Read-only QA/Test history') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-muted">{{ __('No pending collections.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{ $pending->links() }}
        </div>
    </div>
</div>
@endsection
