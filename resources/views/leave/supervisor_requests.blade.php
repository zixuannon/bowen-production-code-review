@extends('layouts.master')

@section('title')
    {{ __('supervisor_leave_requests') }}
@endsection

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">
                {{ __('supervisor_leave_requests') }}
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
                               data-url="{{ route('leave.supervisor.show') }}" data-click-to-select="true"
                               data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100, 200]"
                               data-search="true" data-show-columns="true" data-show-refresh="true" data-fixed-columns="false"
                               data-fixed-number="2" data-fixed-right-number="1" data-trim-on-search="false"
                               data-mobile-responsive="true" data-sort-name="id" data-sort-order="desc"
                               data-maintain-selected="true" data-export-data-type='all'
                               data-query-params="supervisorLeaveQueryParams" data-toolbar="#toolbar"
                               data-export-options='{ "fileName": "supervisor-leave-request-list-<?= date('d-m-y') ?>","ignoreColumn":["operate"]}'
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
                                <th scope="col" data-formatter="supervisorStatusFormatter" data-field="supervisor_status">{{ __('status') }}</th>
                                <th scope="col" data-field="supervisor_comment" data-visible="false">{{ __('supervisor_comment') }}</th>
                                <th scope="col" data-field="supervisor_reviewed_at" data-visible="false">{{ __('reviewed_at') }}</th>
                                <th scope="col" data-field="created_at" >{{ __('created_at') }}</th>
                                <th data-events="supervisorLeaveEvents" scope="col" data-field="operate" data-escape="false">{{ __('action') }}</th>
                            </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Supervisor Approval Modal --}}
        <div class="modal fade" id="supervisorModal" data-backdrop="static" tabindex="-1" role="dialog"
             aria-labelledby="supervisorModalLabel" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="supervisorModalLabel">{{ __('supervisor_approve') . ' / ' . __('supervisor_reject') }}</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true"><i class="fa fa-close"></i></span>
                        </button>
                    </div>
                    <form id="supervisorForm" class="edit-form" action="{{ url('leave/supervisor/status/update') }}" novalidate="novalidate">
                        @csrf
                        <input type="hidden" name="_method" value="PUT">
                        <div class="modal-body">
                            <input type="hidden" name="id" id="supervisor_leave_id">
                            <div class="row">
                                <div class="form-group col-sm-12 col-md-6">
                                    <div class="form-check">
                                        <label class="form-check-label">
                                            <input type="radio" class="form-check-input" name="status" value="approved"> {{ __('supervisor_approve') }} <i class="input-helper"></i></label>
                                    </div>
                                </div>
                                <div class="form-group col-sm-12 col-md-6">
                                    <div class="form-check">
                                        <label class="form-check-label">
                                            <input type="radio" class="form-check-input" name="status" value="rejected"> {{ __('supervisor_reject') }} <i class="input-helper"></i></label>
                                    </div>
                                </div>
                            </div>
                            <div class="row form-group">
                                <div class="col-sm-12 col-md-12">
                                    <label>{{ __('reason') }}</label>
                                    <textarea name="reason" disabled id="supervisor_reason" class="form-control"></textarea>
                                </div>
                            </div>
                            <div class="row form-group">
                                <div class="col-sm-12 col-md-12">
                                    <label>{{ __('supervisor_comment') }}</label>
                                    <textarea name="comment" id="supervisor_comment" class="form-control" placeholder="{{ __('supervisor_comment') }}"></textarea>
                                </div>
                            </div>
                            <div class="row form-group">
                                <div class="col-sm-12 col-md-6">
                                    <label>{{ __('from_date') }}</label>
                                    <input type="text" disabled id="supervisor_from_date" class="form-control">
                                </div>
                                <div class="col-sm-12 col-md-6">
                                    <label>{{ __('to_date') }}</label>
                                    <input type="text" disabled id="supervisor_to_date" class="form-control">
                                </div>
                            </div>
                            <div class="form-group col-sm-12 col-md-12">
                                <label>{{ __('attachments') }}</label>
                                <div id="supervisor_attachment"></div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('Cancel') }}</button>
                            <input class="btn btn-theme" type="submit" value={{ __('submit') }}>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
