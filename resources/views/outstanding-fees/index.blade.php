@extends('layouts.master')

@section('title')
    {{ __('Outstanding Fees') }}
@endsection

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">
                {{ __('Outstanding Fees') }}
            </h3>
        </div>

        <div class="row">
            {{-- Filters --}}
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">{{ __('Filters') }}</h4>
                        <form method="GET" action="{{ route('outstanding-fees.index') }}">
                            <div class="row">
                                <div class="form-group col-md-3">
                                    <label>{{ __('Search') }}</label>
                                    <input type="text" name="search" class="form-control"
                                        placeholder="{{ __('Name or GR No.') }}"
                                        value="{{ $search ?? '' }}">
                                </div>
                                <div class="form-group col-md-2">
                                    <label>{{ __('Class Section') }}</label>
                                    <select name="class_section_id" class="form-control">
                                        <option value="">{{ __('All') }}</option>
                                        @foreach ($classSections ?? [] as $cs)
                                            <option value="{{ $cs->id }}"
                                                {{ ($classSectionFilter ?? '') == $cs->id ? 'selected' : '' }}>
                                                {{ $cs->class->name ?? '' }}
                                                @if ($cs->section->name ?? null)
                                                    - {{ $cs->section->name }}
                                                @endif
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-group col-md-2">
                                    <label>{{ __('Session Year') }}</label>
                                    <select name="session_year_id" class="form-control">
                                        @foreach ($allSessionYears ?? [] as $sy)
                                            <option value="{{ $sy->id }}"
                                                {{ ($filterSessionYearId ?? '') == $sy->id ? 'selected' : '' }}>
                                                {{ $sy->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-group col-md-2">
                                    <label>{{ __('Status') }}</label>
                                    <select name="status" class="form-control">
                                        <option value="">{{ __('All') }}</option>
                                        <option value="unpaid" {{ ($statusFilter ?? '') == 'unpaid' ? 'selected' : '' }}>
                                            {{ __('Unpaid') }}</option>
                                        <option value="partial" {{ ($statusFilter ?? '') == 'partial' ? 'selected' : '' }}>
                                            {{ __('Partial') }}</option>
                                        <option value="fully_paid" {{ ($statusFilter ?? '') == 'fully_paid' ? 'selected' : '' }}>
                                            {{ __('Fully Paid') }}</option>
                                        <option value="no_fee_structure" {{ ($statusFilter ?? '') == 'no_fee_structure' ? 'selected' : '' }}>
                                            {{ __('No Fee Structure') }}</option>
                                    </select>
                                </div>
                                <div class="form-group col-md-3">
                                    <label>&nbsp;</label>
                                    <div>
                                        <div class="form-check form-check-inline mt-2">
                                            <input type="checkbox" name="outstanding_only" value="1"
                                                class="form-check-input"
                                                {{ ($outstandingOnly ?? false) ? 'checked' : '' }}>
                                            <label class="form-check-label">{{ __('Outstanding Only') }}</label>
                                        </div>
                                        <button type="submit" class="btn btn-theme ml-3">{{ __('Apply') }}</button>
                                        <a href="{{ route('outstanding-fees.index') }}" class="btn btn-secondary ml-2">{{ __('Clear') }}</a>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Summary --}}
            @if (isset($summary) && ($summary['total_students'] ?? 0) > 0)
                <div class="col-md-12 grid-margin stretch-card">
                    <div class="card">
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-3">
                                    <h5 class="text-primary">{{ $summary['total_students'] }}</h5>
                                    <small class="text-muted">{{ __('Total Students') }}</small>
                                </div>
                                <div class="col-md-3">
                                    <h5 class="text-info">{{ number_format($summary['total_expected']) }} MMK</h5>
                                    <small class="text-muted">{{ __('Total Expected') }}</small>
                                </div>
                                <div class="col-md-3">
                                    <h5 class="text-success">{{ number_format($summary['total_paid']) }} MMK</h5>
                                    <small class="text-muted">{{ __('Total Paid') }}</small>
                                </div>
                                <div class="col-md-3">
                                    <h5 class="{{ ($summary['total_outstanding'] ?? 0) > 0 ? 'text-danger' : 'text-success' }}">
                                        {{ number_format($summary['total_outstanding']) }} MMK
                                    </h5>
                                    <small class="text-muted">{{ __('Total Outstanding') }}</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Table --}}
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">
                            {{ __('Outstanding Fees') }}
                            @if (isset($resultRows))
                                <span class="badge badge-info ml-2">{{ $resultRows->count() }}</span>
                            @endif
                        </h4>

                        @if (isset($resultRows) && $resultRows->count() > 0)
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Student Name') }}</th>
                                            <th>{{ __('GR No.') }}</th>
                                            <th>{{ __('Class') }}</th>
                                            <th>{{ __('Guardian') }}</th>
                                            <th>{{ __('Contact') }}</th>
                                            <th>{{ __('Expected') }}</th>
                                            <th>{{ __('Paid') }}</th>
                                            <th>{{ __('Outstanding') }}</th>
                                            <th>{{ __('Last Payment') }}</th>
                                            <th>{{ __('Status') }}</th>
                                            <th>{{ __('Action') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($resultRows as $row)
                                            <tr>
                                                <td>{{ $row['full_name'] }}</td>
                                                <td>{{ $row['admission_no'] }}</td>
                                                <td>
                                                    {{ $row['class_name'] }}
                                                    @if ($row['section_name'])
                                                        - {{ $row['section_name'] }}
                                                    @endif
                                                </td>
                                                <td>{{ $row['guardian_name'] ?: __('N/A') }}</td>
                                                <td>{{ $row['contact'] ?: __('暂无联系方式') }}</td>
                                                <td>{{ number_format($row['compulsory_expected']) }}</td>
                                                <td>{{ number_format($row['compulsory_paid']) }}</td>
                                                <td>
                                                    <span class="{{ $row['outstanding'] > 0 ? 'text-danger font-weight-bold' : 'text-success' }}">
                                                        {{ number_format($row['outstanding']) }}
                                                    </span>
                                                </td>
                                                <td>{{ $row['last_payment_date'] ?: __('暂无付款记录') }}</td>
                                                <td>
                                                    @if ($row['status'] == 'fully_paid')
                                                        <span class="badge badge-success">{{ $row['status_label'] }}</span>
                                                    @elseif ($row['status'] == 'partial')
                                                        <span class="badge badge-warning">{{ $row['status_label'] }}</span>
                                                    @elseif ($row['status'] == 'unpaid')
                                                        <span class="badge badge-danger">{{ $row['status_label'] }}</span>
                                                    @else
                                                        <span class="badge badge-secondary">{{ $row['status_label'] }}</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    <a href="{{ route('student-ledger.show', ['userId' => $row['user_id']]) }}"
                                                        class="btn btn-sm btn-theme">
                                                        {{ __('View Ledger') }}
                                                    </a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="text-muted text-center py-3">{{ __('No students found.') }}</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
