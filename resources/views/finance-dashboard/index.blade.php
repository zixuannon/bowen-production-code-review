@extends('layouts.master')

@section('title'){{ __('Finance Dashboard') }}@endsection

@section('content')
    <div class="content-wrapper">
        @if(app(\App\Services\CentralFinanceSchoolFinanceNavigationService::class)->usesCentralFinanceDailyWorkspace())
            @include('components.central-finance-legacy-historical-notice')
        @endif
    <div class="page-header">
        <h3 class="page-title">{{ __('Finance Dashboard') }}</h3>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item active">{{ __('Finance Dashboard') }}</li>
            </ol>
        </nav>
    </div>

    {{-- Filter Bar --}}
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('finance-dashboard.index') }}" class="form-inline">
                <div class="form-group mr-2">
                    <label class="mr-2">{{ __('Date From') }}</label>
                    <input type="date" name="from" class="form-control form-control-sm" value="{{ $from }}">
                </div>
                <div class="form-group mr-2">
                    <label class="mr-2">{{ __('Date To') }}</label>
                    <input type="date" name="to" class="form-control form-control-sm" value="{{ $to }}">
                </div>
                <button type="submit" class="btn btn-theme btn-sm mr-2">{{ __('Apply') }}</button>
                <a href="{{ route('finance-dashboard.index') }}" class="btn btn-secondary btn-sm">{{ __('Clear') }}</a>
            </form>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="row">
        <div class="col-md-4 grid-margin stretch-card">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h3 class="mb-0">{{ number_format($totalIncome, 2) }}</h3>
                    <p class="mb-0">{{ __('Total Income') }} (MMK)</p>
                    <small>{{ __('Compulsory') }}: {{ number_format($compulsoryIncome, 2) }} | {{ __('Optional') }}: {{ number_format($optionalIncome, 2) }}</small>
                </div>
            </div>
        </div>
        <div class="col-md-4 grid-margin stretch-card">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <h3 class="mb-0">{{ number_format($totalExpense, 2) }}</h3>
                    <p class="mb-0">{{ __('Total Expense') }} (MMK)</p>
                </div>
            </div>
        </div>
        <div class="col-md-4 grid-margin stretch-card">
            <div class="card @if($netIncome >= 0) bg-success @else bg-danger @endif text-white">
                <div class="card-body">
                    <h3 class="mb-0">{{ number_format($netIncome, 2) }}</h3>
                    <p class="mb-0">{{ __('Net Income') }} (MMK)</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3 grid-margin stretch-card">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h3 class="mb-0">{{ number_format($outstandingOverview['total_outstanding'] ?? 0, 2) }}</h3>
                    <p class="mb-0">{{ __('Outstanding') }} (MMK)</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 grid-margin stretch-card">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h3 class="mb-0">{{ number_format($compulsoryIncome, 2) }}</h3>
                    <p class="mb-0">{{ __('Compulsory Income') }} (MMK)</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 grid-margin stretch-card">
            <div class="card bg-secondary text-white">
                <div class="card-body">
                    <h3 class="mb-0">{{ number_format($optionalIncome, 2) }}</h3>
                    <p class="mb-0">{{ __('Optional Income') }} (MMK)</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 grid-margin stretch-card">
            <div class="card bg-dark text-white">
                <div class="card-body">
                    <h3 class="mb-0">{{ $collectionRate }}%</h3>
                    <p class="mb-0">{{ __('Collection Rate') }}</p>
                    <small>{{ number_format($allCompulsoryPaid, 0) }} / {{ number_format($totalExpected, 0) }}</small>
                </div>
            </div>
        </div>
    </div>

    {{-- Outstanding Overview + Category Breakdown --}}
    <div class="row">
        {{-- Outstanding Overview --}}
        <div class="col-md-6 grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title">{{ __('Outstanding Overview') }}</h4>
                    <table class="table table-bordered table-sm">
                        <tbody>
                            <tr>
                                <th>{{ __('Students With Outstanding') }}</th>
                                <td class="text-right">{{ $outstandingOverview['students_with_outstanding'] ?? 0 }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('Total Expected') }} (MMK)</th>
                                <td class="text-right">{{ number_format($outstandingOverview['total_expected'] ?? 0, 2) }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('Total Compulsory Paid') }} (MMK)</th>
                                <td class="text-right">{{ number_format($outstandingOverview['total_compulsory_paid'] ?? 0, 2) }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('Total Outstanding') }} (MMK)</th>
                                <td class="text-right font-weight-bold @if(($outstandingOverview['total_outstanding'] ?? 0) > 0) text-danger @else text-success @endif">
                                    {{ number_format($outstandingOverview['total_outstanding'] ?? 0, 2) }}
                                </td>
                            </tr>
                            <tr>
                                <th>{{ __('Highest Outstanding Student') }}</th>
                                <td class="text-right">{{ $outstandingOverview['highest_student'] ?? 'N/A' }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('Latest Payment Date') }}</th>
                                <td class="text-right">
                                    {{ ($outstandingOverview['latest_payment_date'] ?? '') ? date('d/m/Y', strtotime($outstandingOverview['latest_payment_date'])) : 'N/A' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Category Breakdown --}}
        <div class="col-md-6 grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title">{{ __('Income by Category') }}</h4>
                    @if (count($categoryBreakdown['income'] ?? []) > 0)
                        <div class="table-responsive" style="max-height:250px;">
                            <table class="table table-bordered table-sm mb-0">
                                <thead><tr>
                                    <th>{{ __('Category') }}</th>
                                    <th class="text-right">{{ __('Amount') }}</th>
                                    <th class="text-right">{{ __('%') }}</th>
                                </tr></thead>
                                <tbody>
                                    @foreach ($categoryBreakdown['income'] as $row)
                                    <tr>
                                        <td>{{ $row['category'] }}</td>
                                        <td class="text-right">{{ number_format($row['amount'], 2) }}</td>
                                        <td class="text-right">{{ $row['percentage'] }}%</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-muted">{{ __('No income data for this period.') }}</p>
                    @endif

                    <h4 class="card-title mt-3">{{ __('Expense by Category') }}</h4>
                    @if (count($categoryBreakdown['expense'] ?? []) > 0)
                        <div class="table-responsive" style="max-height:250px;">
                            <table class="table table-bordered table-sm mb-0">
                                <thead><tr>
                                    <th>{{ __('Category') }}</th>
                                    <th class="text-right">{{ __('Amount') }}</th>
                                    <th class="text-right">{{ __('%') }}</th>
                                </tr></thead>
                                <tbody>
                                    @foreach ($categoryBreakdown['expense'] as $row)
                                    <tr>
                                        <td>{{ $row['category'] }}</td>
                                        <td class="text-right">{{ number_format($row['amount'], 2) }}</td>
                                        <td class="text-right">{{ $row['percentage'] }}%</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-muted">{{ __('No expense data for this period.') }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Recent Payments + Recent Expenses --}}
    <div class="row">
        <div class="col-md-6 grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title">{{ __('Recent Payments') }}</h4>
                    @if (count($recentPayments) > 0)
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm">
                                <thead><tr>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Student') }}</th>
                                    <th>{{ __('Type') }}</th>
                                    <th class="text-right">{{ __('Amount') }}</th>
                                    <th>{{ __('Method') }}</th>
                                    <th>{{ __('Receipt') }}</th>
                                </tr></thead>
                                <tbody>
                                    @foreach ($recentPayments as $p)
                                    <tr>
                                        <td>{{ $p['date'] ? date('d/m/Y', strtotime($p['date'])) : 'N/A' }}</td>
                                        <td>{{ $p['student'] }}</td>
                                        <td>
                                            @if($p['type']==='Compulsory')
                                                <span class="badge badge-primary">{{ __('Compulsory') }}</span>
                                            @else
                                                <span class="badge badge-info">{{ __('Optional') }}</span>
                                            @endif
                                        </td>
                                        <td class="text-right">{{ number_format($p['amount'], 2) }}</td>
                                        <td>{{ $p['method'] }}</td>
                                        <td>#{{ $p['receipt_id'] }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-muted">{{ __('No payments in this period.') }}</p>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-6 grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title">{{ __('Recent Expenses') }}</h4>
                    @if ($recentExpenses->count() > 0)
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm">
                                <thead><tr>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Title') }}</th>
                                    <th>{{ __('Category') }}</th>
                                    <th class="text-right">{{ __('Amount') }}</th>
                                </tr></thead>
                                <tbody>
                                    @foreach ($recentExpenses as $e)
                                    <tr>
                                        <td>{{ $e->date ? date('d/m/Y', strtotime($e->date)) : 'N/A' }}</td>
                                        <td>{{ $e->title }}</td>
                                        <td>{{ $e->finance_category->name ?? '-' }}</td>
                                        <td class="text-right">{{ number_format($e->amount_mmk > 0 ? $e->amount_mmk : $e->amount, 2) }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-muted">{{ __('No expenses in this period.') }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Quick Links --}}
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title">{{ __('Quick Links') }}</h4>
                    <div class="d-flex flex-wrap" style="gap:8px;">
                        <a href="{{ route('finance-report.index') }}" class="btn btn-outline-primary btn-sm">
                            <i class="fa fa-file-text"></i> {{ __('View Finance Report') }}
                        </a>
                        <a href="{{ route('outstanding-fees.index') }}" class="btn btn-outline-info btn-sm">
                            <i class="fa fa-list"></i> {{ __('View Outstanding Fees') }}
                        </a>
                        <a href="{{ route('student-ledger.index') }}" class="btn btn-outline-secondary btn-sm">
                            <i class="fa fa-book"></i> {{ __('View Student Ledger') }}
                        </a>
                        <a href="{{ route('finance-report.export', request()->query()) }}" class="btn btn-outline-success btn-sm">
                            <i class="fa fa-download"></i> {{ __('Export Finance Report') }}
                        </a>
                        <a href="{{ route('outstanding-fees.export') }}" class="btn btn-outline-success btn-sm">
                            <i class="fa fa-download"></i> {{ __('Export Outstanding Fees') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
@endsection
