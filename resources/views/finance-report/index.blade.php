@extends('layouts.master')

@section('title')
    {{ __('Finance Report') }}
@endsection

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">
                {{ __('Finance Report') }}
            </h3>
        </div>

        {{-- Filters --}}
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">{{ __('Filters') }}</h4>
                        <form method="GET" action="{{ route('finance-report.index') }}">
                            <div class="row">
                                <div class="form-group col-md-2">
                                    <label>{{ __('Date From') }}</label>
                                    <input type="date" name="from" class="form-control" value="{{ $from }}">
                                </div>
                                <div class="form-group col-md-2">
                                    <label>{{ __('Date To') }}</label>
                                    <input type="date" name="to" class="form-control" value="{{ $to }}">
                                </div>
                                <div class="form-group col-md-2">
                                    <label>{{ __('Type') }}</label>
                                    <select name="type" class="form-control">
                                        <option value="all" {{ $typeFilter == 'all' ? 'selected' : '' }}>{{ __('All') }}</option>
                                        <option value="income" {{ $typeFilter == 'income' ? 'selected' : '' }}>{{ __('Income') }}</option>
                                        <option value="expense" {{ $typeFilter == 'expense' ? 'selected' : '' }}>{{ __('Expense') }}</option>
                                    </select>
                                </div>
                                <div class="form-group col-md-3">
                                    <label>{{ __('Finance Category') }}</label>
                                    <select name="finance_category_id" class="form-control">
                                        <option value="">{{ __('All') }}</option>
                                        @foreach ($financeCategories as $fc)
                                            <option value="{{ $fc->name }}"
                                                {{ $categoryFilter == $fc->name ? 'selected' : '' }}>
                                                {{ $fc->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-group col-md-3">
                                    <label>&nbsp;</label>
                                    <div>
                                        <button type="submit" class="btn btn-theme">{{ __('Apply') }}</button>
                                        <a href="{{ route('finance-report.index') }}" class="btn btn-secondary ml-2">{{ __('Clear') }}</a>
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
            <div class="col-md-4 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body text-center">
                        <h2 class="text-primary">{{ number_format($totalIncome) }} MMK</h2>
                        <small class="text-muted">{{ __('Total Income') }}</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body text-center">
                        <h2 class="text-danger">{{ number_format($totalExpense) }} MMK</h2>
                        <small class="text-muted">{{ __('Total Expense') }}</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body text-center">
                        <h2 class="{{ $netIncome >= 0 ? 'text-success' : 'text-danger' }}">
                            {{ number_format($netIncome) }} MMK
                        </h2>
                        <small class="text-muted">{{ __('Net Income') }}</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body text-center">
                        <h4 class="text-info">{{ number_format($totalCompulsoryIncome) }} MMK</h4>
                        <small class="text-muted">{{ __('Compulsory Income') }}</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body text-center">
                        <h4 class="text-info">{{ number_format($totalOptionalIncome) }} MMK</h4>
                        <small class="text-muted">{{ __('Optional Income') }}</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body text-center">
                        <h4 class="{{ $currentOutstanding > 0 ? 'text-warning' : 'text-success' }}">
                            {{ number_format($currentOutstanding) }} MMK
                        </h4>
                        <small class="text-muted">{{ __('Current Outstanding') }} <span class="badge badge-secondary">{{ __('Reference') }}</span></small>
                    </div>
                </div>
            </div>
        </div>

        {{-- Category Detail Table --}}
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">
                            {{ __('Category Breakdown') }}
                            @if ($categoryRows->isNotEmpty())
                                <span class="badge badge-info ml-2">{{ $categoryRows->count() }}</span>
                            @endif
                        </h4>

                        @if ($categoryRows->isNotEmpty())
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Category') }}</th>
                                            <th>{{ __('Type') }}</th>
                                            <th>{{ __('Source') }}</th>
                                            <th class="text-right">{{ __('Amount (MMK)') }}</th>
                                            <th class="text-right">{{ __('Transactions') }}</th>
                                            <th class="text-right">{{ __('Percentage') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($categoryRows as $row)
                                            <tr>
                                                <td>{{ $row['category'] }}</td>
                                                <td>
                                                    @if ($row['type'] === 'Income')
                                                        <span class="badge badge-success">{{ __('Income') }}</span>
                                                    @else
                                                        <span class="badge badge-danger">{{ __('Expense') }}</span>
                                                    @endif
                                                </td>
                                                <td>{{ $row['source'] }}</td>
                                                <td class="text-right">{{ number_format($row['amount']) }}</td>
                                                <td class="text-right">{{ $row['count'] }}</td>
                                                <td class="text-right">{{ $row['percentage'] }}%</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr class="font-weight-bold bg-light">
                                            <td colspan="3">{{ __('Total') }}</td>
                                            <td class="text-right">
                                                {{ number_format($categoryRows->sum('amount')) }}
                                            </td>
                                            <td class="text-right">
                                                {{ $categoryRows->sum('count') }}
                                            </td>
                                            <td class="text-right">100%</td>
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
