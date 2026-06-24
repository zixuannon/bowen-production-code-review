@extends('layouts.master')

@section('title')
    {{ __('Bank Account Summary') }}
@endsection

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">
                {{ __('Bank Account Summary Report') }}
            </h3>
        </div>

        {{-- Filters --}}
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">{{ __('Filters') }}</h4>
                        <form id="bank-account-report-filter" method="GET" action="{{ route('bank-account-report.index') }}">
                            <div class="row">
                                <div class="form-group col-md-2">
                                    <label>{{ __('Date From') }}</label>
                                    <input type="date" name="date_from" class="form-control" value="{{ $dateFrom }}">
                                </div>
                                <div class="form-group col-md-2">
                                    <label>{{ __('Date To') }}</label>
                                    <input type="date" name="date_to" class="form-control" value="{{ $dateTo }}">
                                </div>
                                <div class="form-group col-md-3">
                                    <label>{{ __('Bank Account') }}</label>
                                    <select name="bank_account_id" class="form-control">
                                        <option value="">{{ __('All Accounts') }}</option>
                                        @foreach ($bankAccounts as $account)
                                            <option value="{{ $account->id }}"
                                                {{ $bankAccountId == $account->id ? 'selected' : '' }}>
                                                {{ $account->account_name }}
                                                @if ($account->bank_name)
                                                    ({{ $account->bank_name }})
                                                @endif
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-group col-md-5">
                                    <label>&nbsp;</label>
                                    <div>
                                        <button type="submit" class="btn btn-theme">{{ __('Apply') }}</button>
                                        <a href="{{ route('bank-account-report.index') }}" class="btn btn-secondary ml-2">{{ __('Clear') }}</a>
                                        <a href="{{ route('bank-account-report.export', request()->query()) }}" class="btn btn-success ml-2">
                                            <i class="fa fa-download"></i> {{ __('Export Excel') }}
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- Summary Cards --}}
        <div class="row">
            <div class="col-md-3 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body text-center">
                        <h3 class="text-info">{{ number_format($totalOpening) }} {{ __('MMK') }}</h3>
                        <small class="text-muted">{{ __('Period Opening Balance') }}</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body text-center">
                        <h3 class="text-success">{{ number_format($totalIncome) }} {{ __('MMK') }}</h3>
                        <small class="text-muted">{{ __('Income During Period') }}</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body text-center">
                        <h3 class="text-danger">{{ number_format($totalExpense) }} {{ __('MMK') }}</h3>
                        <small class="text-muted">{{ __('Expense During Period') }}</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body text-center">
                        <h3 class="{{ $totalClosing >= 0 ? 'text-primary' : 'text-danger' }}">
                            {{ number_format($totalClosing) }} {{ __('MMK') }}
                        </h3>
                        <small class="text-muted">{{ __('Closing Balance') }}</small>
                    </div>
                </div>
            </div>
        </div>

        {{-- Detail Table --}}
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">
                            {{ __('Account Summary') }}
                            @if (count($rows) > 0)
                                <span class="badge badge-info ml-2">{{ count($rows) }}</span>
                            @endif
                        </h4>

                        @if (count($rows) > 0)
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Account Name') }}</th>
                                            <th>{{ __('Bank') }}</th>
                                            <th class="text-right">{{ __('Period Opening Balance') }}</th>
                                            <th class="text-right">{{ __('Income During Period') }}</th>
                                            <th class="text-right">{{ __('Expense During Period') }}</th>
                                            <th class="text-right">{{ __('Closing Balance') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($rows as $row)
                                            <tr>
                                                <td>
                                                    <a href="{{ route('bank-accounts.show', $row['id']) }}">
                                                        {{ $row['account_name'] }}
                                                    </a>
                                                </td>
                                                <td>{{ $row['bank_name'] }}</td>
                                                <td class="text-right">{{ number_format($row['period_opening_balance'], 2) }}</td>
                                                <td class="text-right text-success">{{ number_format($row['period_income'], 2) }}</td>
                                                <td class="text-right text-danger">{{ number_format($row['period_expense'], 2) }}</td>
                                                <td class="text-right {{ $row['closing_balance'] >= 0 ? 'text-primary' : 'text-danger' }}">
                                                    {{ number_format($row['closing_balance'], 2) }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr class="font-weight-bold bg-light">
                                            <td colspan="2">{{ __('Total') }}</td>
                                            <td class="text-right">{{ number_format($totalOpening, 2) }}</td>
                                            <td class="text-right text-success">{{ number_format($totalIncome, 2) }}</td>
                                            <td class="text-right text-danger">{{ number_format($totalExpense, 2) }}</td>
                                            <td class="text-right {{ $totalClosing >= 0 ? 'text-primary' : 'text-danger' }}">
                                                {{ number_format($totalClosing, 2) }}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        @else
                            <p class="text-muted text-center py-3">{{ __('No data found for the selected period.') }}</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
