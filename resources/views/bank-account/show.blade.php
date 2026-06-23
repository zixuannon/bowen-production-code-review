@extends('layouts.master')

@section('title')
    {{ $bankAccount->account_name }} - {{ __('Bank Account Detail') }}
@endsection

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">
                <a href="{{ route('bank-accounts.index') }}" class="text-muted">{{ __('Bank Accounts') }}</a>
                <i class="mdi mdi-chevron-right"></i>
                {{ $bankAccount->account_name }}
            </h3>
        </div>

        {{-- Account Info Card --}}
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h4 class="card-title mb-1">{{ $bankAccount->account_name }}</h4>
                                <p class="text-muted mb-2">
                                    {{ $bankAccount->bank_name }}
                                    @if ($bankAccount->account_number)
                                        | {{ $bankAccount->account_number }}
                                    @endif
                                </p>
                                <span class="badge badge-primary">{{ $bankAccount->account_type }}</span>
                                <span class="badge badge-info">{{ $bankAccount->currency }}</span>
                                @if ($bankAccount->is_active)
                                    <span class="badge badge-success">{{ __('Active') }}</span>
                                @else
                                    <span class="badge badge-danger">{{ __('Inactive') }}</span>
                                @endif
                                @if ($bankAccount->is_default)
                                    <span class="badge badge-warning">{{ __('Default') }}</span>
                                @endif
                            </div>
                            <div class="col-md-4 text-right">
                                <a href="{{ route('bank-accounts.index') }}" class="btn btn-secondary btn-sm">
                                    <i class="fa fa-arrow-left"></i> {{ __('Back to List') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Summary Cards --}}
        <div class="row">
            <div class="col-md-3 grid-margin stretch-card">
                <div class="card card-body bg-light">
                    <h6 class="text-muted mb-1">{{ __('Opening Balance') }}</h6>
                    <h3 class="mb-0">{{ number_format($bankAccount->opening_balance, 2) }} {{ $bankAccount->currency }}</h3>
                    @if ($bankAccount->opening_balance_date)
                        <small class="text-muted">{{ $bankAccount->opening_balance_date }}</small>
                    @endif
                </div>
            </div>

            <div class="col-md-3 grid-margin stretch-card">
                <div class="card card-body bg-success-light">
                    <h6 class="text-muted mb-1">{{ __('Total Income') }}</h6>
                    <h3 class="mb-0 text-success">{{ number_format($totalIncome, 2) }} {{ $bankAccount->currency }}</h3>
                    <small class="text-muted">
                        {{ __('Compulsory') }}: {{ number_format($totalCompulsory, 2) }}
                        | {{ __('Optional') }}: {{ number_format($totalOptional, 2) }}
                    </small>
                </div>
            </div>

            <div class="col-md-3 grid-margin stretch-card">
                <div class="card card-body bg-danger-light">
                    <h6 class="text-muted mb-1">{{ __('Total Expenses') }}</h6>
                    <h3 class="mb-0 text-danger">{{ number_format($totalExpenses, 2) }} {{ $bankAccount->currency }}</h3>
                </div>
            </div>

            <div class="col-md-3 grid-margin stretch-card">
                <div class="card card-body bg-primary-light">
                    <h6 class="text-muted mb-1">{{ __('Current Balance') }}</h6>
                    <h3 class="mb-0 text-primary">{{ number_format($currentBalance, 2) }} {{ $bankAccount->currency }}</h3>
                    <small class="text-muted">{{ __('Opening + Income - Expenses') }}</small>
                </div>
            </div>
        </div>

        {{-- Compulsory Fee Income --}}
        <div class="row">
            <div class="col-md-6 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">{{ __('Compulsory Fee Income') }}
                            <span class="badge badge-success float-right">{{ number_format($totalCompulsory, 2) }}</span>
                        </h4>
                        @if ($compulsoryFees->isEmpty())
                            <p class="text-muted text-center py-3">{{ __('No compulsory fee transactions for this account.') }}</p>
                        @else
                            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Date') }}</th>
                                            <th>{{ __('Student') }}</th>
                                            <th>{{ __('Amount') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($compulsoryFees as $fee)
                                            <tr>
                                                <td>{{ $fee->date }}</td>
                                                <td>{{ $fee->student->full_name ?? '-' }}</td>
                                                <td class="text-right">{{ number_format($fee->amount, 2) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if ($compulsoryFees->count() >= 100)
                                <p class="text-muted text-center small">{{ __('Showing latest 100 records.') }}</p>
                            @endif
                        @endif
                    </div>
                </div>
            </div>

            {{-- Optional Fee Income --}}
            <div class="col-md-6 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">{{ __('Optional Fee Income') }}
                            <span class="badge badge-info float-right">{{ number_format($totalOptional, 2) }}</span>
                        </h4>
                        @if ($optionalFees->isEmpty())
                            <p class="text-muted text-center py-3">{{ __('No optional fee transactions for this account.') }}</p>
                        @else
                            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Date') }}</th>
                                            <th>{{ __('Student') }}</th>
                                            <th>{{ __('Amount') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($optionalFees as $fee)
                                            <tr>
                                                <td>{{ $fee->date }}</td>
                                                <td>{{ $fee->student->full_name ?? '-' }}</td>
                                                <td class="text-right">{{ number_format($fee->amount, 2) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if ($optionalFees->count() >= 100)
                                <p class="text-muted text-center small">{{ __('Showing latest 100 records.') }}</p>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Expenses --}}
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">{{ __('Expenses') }}
                            <span class="badge badge-danger float-right">{{ number_format($totalExpenses, 2) }}</span>
                        </h4>
                        @if ($expenses->isEmpty())
                            <p class="text-muted text-center py-3">{{ __('No expense transactions for this account.') }}</p>
                        @else
                            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Date') }}</th>
                                            <th>{{ __('Title') }}</th>
                                            <th>{{ __('Category') }}</th>
                                            <th>{{ __('Amount') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($expenses as $expense)
                                            <tr>
                                                <td>{{ $expense->date }}</td>
                                                <td>{{ $expense->title }}</td>
                                                <td>
                                                    @if ($expense->staff_id)
                                                        <span class="badge badge-warning">{{ __('Salary') }}</span>
                                                    @else
                                                        {{ $expense->category->name ?? '-' }}
                                                    @endif
                                                </td>
                                                <td class="text-right">{{ number_format($expense->amount, 2) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if ($expenses->count() >= 100)
                                <p class="text-muted text-center small">{{ __('Showing latest 100 records.') }}</p>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
