@extends('layouts.master')

@section('title')
    {{ __('hr_leave_requests') }}
@endsection

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">
                {{ __('hr_leave_requests') }}
            </h3>
        </div>
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">{{ __('staff') . ' ' . __('leaves') }}</h4>
                        {!! Form::hidden('holiday_days', $holiday_days ?? '', ['class' => 'form-control holiday_days']) !!}
                        {!! Form::hidden('public_holiday', $public_holiday ?? '', ['class' => 'form-control public_holiday']) !!}
                        <div class="row" id="toolbar">
                            <div class="form-group col-sm-12 col-md-3">
                                <label for="" class="filter-menu">{{ __('session_year') }}</label>
                                {!! Form::select('session_year_id', $sessionYear, $current_session_year->id, ['class' => 'form-control', 'id' => 'session_year_id']) !!}
                            </div>

                            <div class="form-group col-sm-12 col-md-3">
                                <label for="" class="filter-menu">{{ __('filter') }}</label>
                                {!! Form::select('filter', ['All' => __('all'),'Today' => __("today"), 'Tomorrow' => __('Tomorrow'), 'Upcoming' => __('Upcoming')], 'All', ['class' => 'form-control', 'id' => 'filter_upcoming']) !!}
                            </div>

                            <div class="form-group col-sm-12 col-md-3">
                                <label for="month" class="filter-menu">{{ __('month') }}</label>
                                {!! Form::select('month', $months, null, ['class' => 'form-control',' id' => 'filter_month_id', 'placeholder' => __('all')]) !!}
                            </div>
                        </div>
                        <table aria-describedby="mydesc" class='table' id='table_list' data-toggle="table"
                               data-url="{{ route('leave.hr.show') }}" data-click-to-select="true"
                               data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100, 200]"
                               data-search="true" data-show-columns="true" data-show-refresh="true" data-fixed-columns="false"
                               data-fixed-number="2" data-fixed-right-number="1" data-trim-on-search="false"
                               data-mobile-responsive="true" data-sort-name="id" data-sort-order="desc"
                               data-maintain-selected="true" data-export-data-type='all'
                               data-query-params="hrLeaveQueryParams" data-toolbar="#toolbar"
                               data-export-options='{ "fileName": "hr-leave-request-list-<?= date('d-m-y') ?>","ignoreColumn":["operate"]}'
                               data-show-export="true" data-escape="true">
                            <thead>
                            <tr>
                                <th scope="col" data-field="id" data-sortable="true" data-visible="false">{{ __('id') }}</th>
                                <th scope="col" data-field="no">{{ __('no.') }}</th>
                                <th scope="col" data-field="user" data-formatter="StudentNameFormatter">{{ __('name') }}</th>
                                <th scope="col" data-field="from_date" >{{ __('from_date') }}</th>
                                <th scope="col" data-field="to_date" >{{ __('to_date') }}</th>
                                <th scope="col" data-field="days">{{ __('total') }}</th>
                                <th scope="col" data-formatter="descriptionFormatter" data-events="tableDescriptionEvents" data-field="reason">{{ __('reason') }}</th>
                                <th scope="col" data-formatter="fileFormatter" data-field="files">{{ __('attachments') }}</th>
                                <th scope="col" data-formatter="supervisorStatusFormatter" data-field="supervisor_status">{{ __('supervisor_status') }}</th>
                                <th scope="col" data-field="supervisor_comment" data-visible="false">{{ __('supervisor_comment') }}</th>
                                <th scope="col" data-field="supervisor_reviewed_at" data-visible="false">{{ __('supervisor_reviewed_at') }}</th>
                                <th scope="col" data-field="hr_comment" data-visible="false">{{ __('hr_comment') }}</th>
                                <th scope="col" data-field="hr_reviewed_at" data-visible="false">{{ __('hr_reviewed_at') }}</th>
                                <th scope="col" data-field="status" data-formatter="leaveStatusFormatter">{{ __('status') }}</th>
                                <th scope="col" data-field="created_at" >{{ __('created_at') }}</th>
                            </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
@endsection
