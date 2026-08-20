@extends('layouts.master')

@section('title', __('Group Finance'))

@section('content')
    <div class="content-wrapper">
        <div class="page-header d-flex justify-content-between align-items-center">
            <h3 class="page-title">{{ $financeGroup->name }} — {{ __('Group Finance') }}</h3>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('group-finance.show', $financeGroup) }}" class="form-inline">
                    <label class="mr-2" for="group-finance-school">{{ __('School Switcher') }}</label>
                    <select id="group-finance-school" class="form-control mr-2" name="school_id" onchange="this.form.submit()">
                        <option value="">{{ __('All Schools') }}</option>
                        @foreach ($schools as $membership)
                            <option value="{{ $membership->school_id }}" @selected($selected?->school_id === $membership->school_id)>
                                {{ $membership->school?->name }}
                            </option>
                        @endforeach
                    </select>
                    <noscript><button class="btn btn-outline-primary" type="submit">{{ __('Apply') }}</button></noscript>
                </form>
                <small class="text-muted d-block mt-2">
                    {{ __('School data is read only and is limited by the configured School scope, tenant identity, and Fund Account scope.') }}
                </small>
            </div>
        </div>

        @if ($operatingSchools->isNotEmpty())
            <div class="card mb-3" data-operating-school-switcher>
                <div class="card-body d-flex flex-wrap align-items-center justify-content-between">
                    <div>
                        <strong>{{ __('Operating School') }}</strong>
                        <p class="mb-0 text-muted small">{{ __('Open an authorized School in read-only Finance workspace. Your central login remains unchanged.') }}</p>
                    </div>
                    <form method="POST" action="{{ route('group-finance.operating.enter', ['financeGroup' => $financeGroup]) }}" id="operating-school-form" class="form-inline mt-2 mt-md-0">
                        @csrf
                        <select id="operating-school" name="school_id" class="form-control mr-2" aria-label="{{ __('Operating School') }}">
                            @foreach ($operatingSchools as $membership)
                                <option value="{{ $membership->school_id }}">{{ $membership->school?->name }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-primary" type="submit">{{ __('Open Read-only Finance') }}</button>
                    </form>
                </div>
            </div>
        @endif

        @if ($result['incomplete']->isNotEmpty())
            <div class="alert alert-warning">{{ __('Incomplete: one or more authorized School reports could not be read. Totals below exclude them.') }}</div>
        @endif

        <div class="row">
            <div class="col-md-3"><div class="card"><div class="card-body">{{ __('Operating Income') }}<h4>{{ number_format($result['summary']['operating_income'], 2) }}</h4></div></div></div>
            <div class="col-md-3"><div class="card"><div class="card-body">{{ __('Operating Expense') }}<h4>{{ number_format($result['summary']['operating_expense'], 2) }}</h4></div></div></div>
            <div class="col-md-3"><div class="card"><div class="card-body">{{ __('Operating Net') }}<h4>{{ number_format($result['summary']['operating_net'], 2) }}</h4></div></div></div>
            <div class="col-md-3"><div class="card"><div class="card-body">{{ __('Internal Transfer') }}<h4>{{ number_format($result['summary']['internal_transfer_amount'], 2) }}</h4></div></div></div>
        </div>

        @if ($selected)
            <div class="card mb-3"><div class="card-body">
                <strong>{{ __('Authorized Fund Accounts') }}:</strong>
                {{ collect($accounts)->pluck('account_name')->implode(', ') ?: __('No authorized Fund Accounts') }}
            </div></div>
        @endif

        <a class="btn btn-outline-primary mb-3" href="{{ route('group-finance.export', array_merge(['financeGroup' => $financeGroup], request()->query())) }}">{{ __('Export CSV') }}</a>

        <div class="card"><div class="card-body table-responsive">
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('School') }}</th><th>{{ __('Type') }}</th><th>{{ __('Reference') }}</th><th>{{ __('Fund Account') }}</th><th>{{ __('Money In') }}</th><th>{{ __('Money Out') }}</th></tr></thead>
                <tbody>
                    @forelse ($result['rows'] as $row)
                        <tr><td>{{ $row['posting_date'] }}</td><td>{{ $row['school_name'] }}</td><td>{{ $row['transaction_class'] }}</td><td>{{ $row['reference_no'] }}</td><td>{{ $row['fund_account_name'] }}</td><td>{{ number_format($row['money_in'], 2) }}</td><td>{{ number_format($row['money_out'], 2) }}</td></tr>
                    @empty
                        <tr><td colspan="7">{{ __('No authorized Ledger records found.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div></div>
    </div>
@endsection
