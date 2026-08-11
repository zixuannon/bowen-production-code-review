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
                        <form id="finance-report-filter" method="GET" action="{{ route('finance-report.index') }}">
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
                                        <a href="{{ route('finance-report.export', request()->query()) }}" class="btn btn-success ml-2">
                                            <i class="fa fa-download"></i> {{ __('Export') }}
                                        </a>
                                    </div>
                                </div>
                            </div>
                            {{-- Quick Date Buttons --}}
                            <div class="row mt-2">
                                <div class="form-group col-md-12 mb-0">
                                    <label class="mr-2 mb-0">{{ __('Quick Date') }}:</label>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-primary" onclick="setQuickDate('today')">{{ __('Today') }}</button>
                                        <button type="button" class="btn btn-outline-primary" onclick="setQuickDate('this_month')">{{ __('This Month') }}</button>
                                        <button type="button" class="btn btn-outline-primary" onclick="setQuickDate('last_month')">{{ __('Last Month') }}</button>
                                        <button type="button" class="btn btn-outline-primary" onclick="setQuickDate('this_quarter')">{{ __('This Quarter') }}</button>
                                        <button type="button" class="btn btn-outline-primary" onclick="setQuickDate('this_year')">{{ __('This Year') }}</button>
                                    </div>
                                </div>
                            </div>
                        </form>
                        @if ($hasFilter)
                            <div class="mt-2">
                                <span class="badge badge-info mr-1">{{ __('Filters active') }}</span>
                                <small class="text-muted">
                                    {{ __('Summary cards reflect current filters. Outstanding is always school-wide reference.') }}
                                </small>
                            </div>
                        @endif
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
                        <small class="text-muted">
                            {{ __('Total Income') }}
                            @if ($typeFilter === 'expense')
                                <span class="badge badge-light">{{ __('filtered') }}</span>
                            @endif
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-4 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body text-center">
                        <h2 class="text-danger">{{ number_format($totalExpense) }} MMK</h2>
                        <small class="text-muted">
                            {{ __('Total Expense') }}
                            @if ($typeFilter === 'income')
                                <span class="badge badge-light">{{ __('filtered') }}</span>
                            @endif
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-4 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body text-center">
                        <h2 class="{{ $netIncome >= 0 ? 'text-success' : 'text-danger' }}">
                            {{ number_format($netIncome) }} MMK
                        </h2>
                        <small class="text-muted">
                            {{ __('Net Income') }}
                            @if ($hasFilter)
                                <span class="badge badge-light">{{ __('filtered') }}</span>
                            @endif
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body text-center">
                        <h4 class="text-info">{{ number_format($totalCompulsoryIncome) }} MMK</h4>
                        <small class="text-muted">
                            {{ __('Compulsory Income') }}
                            @if ($typeFilter === 'expense')
                                <span class="badge badge-light">{{ __('filtered') }}</span>
                            @endif
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-4 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body text-center">
                        <h4 class="text-info">{{ number_format($totalOptionalIncome) }} MMK</h4>
                        <small class="text-muted">
                            {{ __('Optional Income') }}
                            @if ($typeFilter === 'expense')
                                <span class="badge badge-light">{{ __('filtered') }}</span>
                            @endif
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-4 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body text-center">
                        @if ($hasSchoolWideOutstandingAccess)
                            <h4 class="{{ $currentOutstanding > 0 ? 'text-warning' : 'text-success' }}">
                                {{ number_format($currentOutstanding) }} MMK
                            </h4>
                        @else
                            <h4 class="text-muted">{{ __('Not available') }}</h4>
                        @endif
                        <small class="text-muted">
                            {{ __('Current Outstanding') }}
                            <span class="badge badge-secondary">{{ __('Reference') }}</span>
                            @if (! $hasSchoolWideOutstandingAccess)
                                <span class="badge badge-light">{{ __('account-scoped role') }}</span>
                            @elseif ($hasFilter)
                                <span class="badge badge-light">{{ __('all students') }}</span>
                            @endif
                        </small>
                        @if (! $hasSchoolWideOutstandingAccess)
                            <div class="mt-1">
                                <small class="text-muted font-italic">
                                    {{ __('Outstanding is school-wide and is not available to account-scoped roles.') }}
                                </small>
                            </div>
                        @elseif ($hasFilter)
                            <div class="mt-1">
                                <small class="text-muted font-italic">
                                    {{ __('Outstanding is school-wide reference, not affected by filters.') }}
                                </small>
                            </div>
                        @endif
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

    <script>
        function setQuickDate(type) {
            var today = new Date();
            var y = today.getFullYear();
            var m = today.getMonth(); // 0-indexed (Jan=0)
            var d = today.getDate();

            var from = '', to = '';

            function pad(n) { return String(n).padStart(2, '0'); }
            function fmt(year, month, day) { return year + '-' + pad(month) + '-' + pad(day); }

            switch (type) {
                case 'today':
                    from = fmt(y, m + 1, d);
                    to   = fmt(y, m + 1, d);
                    break;
                case 'this_month':
                    from = fmt(y, m + 1, 1);
                    to   = fmt(y, m + 1, d);
                    break;
                case 'last_month':
                    var firstOfLast = new Date(y, m - 1, 1);
                    var lastOfLast  = new Date(y, m, 0);
                    from = fmt(firstOfLast.getFullYear(), firstOfLast.getMonth() + 1, 1);
                    to   = fmt(lastOfLast.getFullYear(), lastOfLast.getMonth() + 1, lastOfLast.getDate());
                    break;
                case 'this_quarter':
                    var qStart = Math.floor(m / 3) * 3;
                    from = fmt(y, qStart + 1, 1);
                    to   = fmt(y, m + 1, d);
                    break;
                case 'this_year':
                    from = fmt(y, 1, 1);
                    to   = fmt(y, m + 1, d);
                    break;
            }

            document.querySelector('input[name="from"]').value = from;
            document.querySelector('input[name="to"]').value = to;
            document.getElementById('finance-report-filter').submit();
        }
    </script>
@endsection
