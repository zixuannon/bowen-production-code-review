@extends('layouts.master')

@section('title')
    {{ __('Student Ledger') }}
@endsection

@section('content')
    <div class="content-wrapper">
        @if(app(\App\Services\CentralFinanceSchoolFinanceNavigationService::class)->usesCentralFinanceDailyWorkspace())
            @include('components.central-finance-legacy-historical-notice')
        @endif
        <div class="page-header">
            <h3 class="page-title">
                {{ __('Student Finance') }}
            </h3>
        </div>

        {{-- Sub-navigation tabs --}}
        <div class="mb-3">
            <a href="{{ route('outstanding-fees.index') }}" class="btn btn-outline-primary">{{ __('Outstanding Fees') }}</a>
            <a href="{{ route('student-ledger.index') }}" class="btn btn-primary">{{ __('Student Ledger') }}</a>
        </div>

        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">{{ __('Search Student') }}</h4>
                        <form method="GET" action="{{ route('student-ledger.index') }}" class="ui-filter-bar">
                            <div class="row">
                                <div class="form-group col-md-8">
                                    <input type="text" name="search" class="form-control"
                                        placeholder="{{ __('Search by name or admission no.') }}"
                                        value="{{ $search ?? '' }}">
                                </div>
                                <div class="form-group col-md-4">
                                    <button type="submit" class="btn btn-theme">{{ __('Search') }}</button>
                                    @if ($search)
                                        <a href="{{ route('student-ledger.index') }}" class="btn btn-secondary ml-2">{{ __('Clear') }}</a>
                                    @endif
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            @if ($search)
                <div class="col-md-12 grid-margin stretch-card">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="card-title">{{ __('Search Results') }} ({{ $students->count() }})</h4>
                            @if ($students->count() > 0)
                                <div class="table-responsive ui-responsive-list-wrap">
                                    <table class="table table-hover ui-responsive-list ui-mobile-cards">
                                        <thead>
                                            <tr>
                                                <th>{{ __('Name') }}</th>
                                                <th>{{ __('GR No.') }}</th>
                                                <th>{{ __('Class') }}</th>
                                                <th>{{ __('Guardian') }}</th>
                                                <th>{{ __('Action') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($students as $stu)
                                                <tr>
                                                    <td data-label="{{ __('Name') }}"><span class="ui-cell-primary">{{ $stu->user->full_name ?? 'N/A' }}</span></td>
                                                    <td data-label="{{ __('GR No.') }}">{{ $stu->admission_no }}</td>
                                                    <td data-label="{{ __('Class') }}">
                                                        {{ $stu->class_section->class->name ?? 'N/A' }}
                                                        @if ($stu->class_section->section->name ?? null)
                                                            - {{ $stu->class_section->section->name }}
                                                        @endif
                                                    </td>
                                                    <td data-label="{{ __('Guardian') }}">{{ $stu->guardian->full_name ?? 'N/A' }}</td>
                                                    <td data-label="">
                                                        <a href="{{ route('student-ledger.show', ['userId' => $stu->user_id]) }}"
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
                                <x-ui-empty-state :message="__('No students found.')" />
                            @endif
                        </div>
                    </div>
                </div>
            @else
                <div class="col-md-12 grid-margin stretch-card">
                    <div class="card">
                        <div class="card-body text-center text-muted py-5">
                            <p>{{ __('Enter student name or admission number to search.') }}</p>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
