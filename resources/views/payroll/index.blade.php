@extends('layouts.master')

@section('title')
    {{ __('payroll') }}
@endsection

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">
                {{ __('manage') . ' ' . __('payroll') }}
            </h3>
        </div>
        <form action="{{ route('payroll.store') }}" method="post" class="create-form" novalidate="novalidate" data-success-function="formSuccessFunction">
            @csrf
            <div class="row">

                <div class="col-md-12 grid-margin stretch-card">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="card-title">
                                {{ __('create') . ' ' . __('payroll') }}
                            </h4>

                            <div class="row">
                                <div class="form-group col-sm-12 col-md-3">
                                    <label>{{ __('select') }} {{ __('month') }} <span
                                            class="text-danger">*</span></label>
                                    {!! Form::select('month', $months, null, ['class' => 'form-control', 'id' => 'month']) !!}
                                </div>

                                <div class="form-group col-sm-12 col-md-3">
                                    <label>{{ __('select') }} {{ __('year') }} <span
                                            class="text-danger">*</span></label>
                                    {!! Form::selectRange(
                                        'year',
                                        $sessionYear,
                                        date('Y', strtotime(Carbon\Carbon::now())),
                                        date('Y', strtotime(Carbon\Carbon::now())),
                                        ['class' => 'form-control', 'id' => 'year'],
                                    ) !!}
                                </div>

                                <div class="form-group col-sm-12 col-md-2 mt-4">
                                    <input class="btn btn-theme" id="search" type="button" value={{ __('search') }}>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-12 grid-margin stretch-card" id="summary-card" style="display:none;">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="card-title">{{ __('monthly_summary') }}</h4>
                            <div class="row">
                                <div class="col-md-2 col-sm-4 mb-3">
                                    <div class="card bg-primary text-white text-center p-2">
                                        <small>{{ __('employees') }}</small>
                                        <h4 id="summary-employees">0</h4>
                                    </div>
                                </div>
                                <div class="col-md-2 col-sm-4 mb-3">
                                    <div class="card bg-success text-white text-center p-2">
                                        <small>{{ __('total_basic_salary') }}</small>
                                        <h5 id="summary-basic-salary">0</h5>
                                    </div>
                                </div>
                                <div class="col-md-2 col-sm-4 mb-3">
                                    <div class="card bg-info text-white text-center p-2">
                                        <small>{{ __('total_allowance') }}</small>
                                        <h5 id="summary-allowance">0</h5>
                                    </div>
                                </div>
                                <div class="col-md-2 col-sm-4 mb-3">
                                    <div class="card bg-warning text-white text-center p-2">
                                        <small>{{ __('total_deduction') }}</small>
                                        <h5 id="summary-deduction">0</h5>
                                    </div>
                                </div>
                                <div class="col-md-2 col-sm-4 mb-3">
                                    <div class="card bg-danger text-white text-center p-2">
                                        <small>{{ __('total_leave_deduction') }}</small>
                                        <h5 id="summary-leave-deduction">0</h5>
                                    </div>
                                </div>
                                <div class="col-md-2 col-sm-4 mb-3">
                                    <div class="card bg-dark text-white text-center p-2">
                                        <small>{{ __('total_net_salary') }}</small>
                                        <h5 id="summary-net-salary">0</h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-12 grid-margin stretch-card">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="card-title">{{ __('list') . ' ' . __('payroll') }}</h4>
                            <div class="row" id="toolbar">
                                <div class="form-group col-sm-12 col-md-3">
                                    <label class="filter-menu">{{ __('date') }} <span class="text-danger">*</span></label>
                                    {!! Form::text('date', null, ['required', 'class' => 'form-control datepicker-popup', 'id' => 'date']) !!}
                                </div>
                            </div>
                            <div class="staff-table">

                                <table aria-describedby="mydesc" class='table' id='table_list'
                                       data-toggle="table" data-url="{{ route('payroll.show', [1]) }}"
                                       data-click-to-select="true" data-side-pagination="server" data-pagination="false"
                                       data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-show-columns="false"
                                       data-show-refresh="true" data-fixed-columns="false" data-fixed-number="2"
                                       data-fixed-right-number="1" data-trim-on-search="false" data-mobile-responsive="true"
                                       data-sort-name="id" data-sort-order="desc" data-maintain-selected="true"
                                       data-export-data-type='all' data-query-params="payrollQueryParams"
                                       data-toolbar="#toolbar"
                                       data-export-options='{ "fileName": "payroll-list-<?= date('d-m-y') ?>"
                                    ,"ignoreColumn":["operate"]}' data-show-export="true" data-escape="true" data-response-handler="responseHandler" data-check-on-init="true">
                                    <thead>
                                        <tr>
                                            <th data-field="state" data-checkbox="true"></th>
                                            <th scope="col" data-field="id" data-sortable="true" data-visible="false">{{ __('id') }}</th>
                                            <th scope="col" data-field="no">{{ __('no.') }}</th>
                                            <th scope="col" data-field="user" data-formatter="StudentNameFormatter">{{ __('name') }}</th>
                                            <th scope="col" data-formatter="salaryStatusFormatter" data-field="status">{{ __('status') }}</th>
                                            <th scope="col" data-field="salary" data-formatter="salaryInputFormatter" data-sortable="false">{{ __('basic_salary') }}</th>
                                            <th scope="col" class="text-wrap" data-field="paid_leaves" data-sortable="false">{{ __('Monthly Allowed Paid Leaves') }}</th>
                                            <th scope="col" class="text-wrap" data-field="total_leaves" data-sortable="false">{{ __('taken_leaves') }}</th>
                                            <th scope="col" class="text-wrap" data-field="salary_deduction" data-sortable="false" data-escape="false">{{ __('salary_deduction') }}</th>
                                            <th scope="col" class="text-wrap" data-field="allowances" data-sortable="false" data-escape="false">{{ __('allowances') }}</th>
                                            {{-- <th scope="col" class="text-wrap" data-field="other_allowances" data-sortable="false" data-escape="false">{{ __('other').' '.__('allowances') }}</th> --}}
                                            <th scope="col" class="text-wrap" data-field="deductions" data-sortable="false" data-escape="false">{{ __('deductions') }}</th>
                                            {{-- <th scope="col" class="text-wrap" data-field="other_deductions" data-sortable="false" data-escape="false">{{ __('other').' '.__('deductions') }}</th> --}}
                                            <th scope="col" data-field="net_salary" data-formatter="netSalaryInputFormatter" data-sortable="false">{{ __('net_salary') }}</th>
                                            <th scope="col" data-field="operate" data-escape="false">{{ __('action') }}</th>
                                        </tr>
                                    </thead>
                                </table>
                                <textarea id="user_id" name="user_id" style="display: none"></textarea>
                                <input type="submit" class="btn btn-theme mt-3 float-right" value="{{ __('submit') }}">

                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection

@section('script')
    <script>
        $('#search').click(function (e) {
            e.preventDefault();
            $('#summary-card').hide();
            $('#table_list').bootstrapTable('refresh');
            $('.staff-table').show();
            let month = $('#month').val();
            let year = $('#year').val();
            $('#table_list').bootstrapTable('uncheckAll');

            var lastDate = getLastDateOfMonth(month, year);
            $('#date').val(lastDate);
        });
        window.onload = $('.staff-table').hide();
    </script>

    <script>
        var $tableList = $('#table_list')
        var selections = []
        var user_list = [];

        function responseHandler(res) {
            // P2-1: Update monthly summary
            if (res.summary) {
                $('#summary-card').show();
                $('#summary-employees').text(res.summary.employee_count);
                $('#summary-basic-salary').text(formatMoney(res.summary.total_basic_salary));
                $('#summary-allowance').text(formatMoney(res.summary.total_allowance));
                $('#summary-deduction').text(formatMoney(res.summary.total_deduction));
                $('#summary-leave-deduction').text(formatMoney(res.summary.total_leave_deduction));
                $('#summary-net-salary').text(formatMoney(res.summary.total_net_salary));
            }
            $.each(res.rows, function (i, row) {
                row.state = $.inArray(row.id, selections) !== -1
            })
            return res
        }

        function formatMoney(value) {
            return Number(value).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 2});
        }

        $(function () {
            $tableList.on('check.bs.table check-all.bs.table uncheck.bs.table uncheck-all.bs.table',
                function (e, rowsAfter, rowsBefore) {
                    user_list = [];
                    var rows = rowsAfter
                    if (e.type === 'uncheck-all') {
                        rows = rowsBefore
                    }
                    var ids = $.map(!$.isArray(rows) ? [rows] : rows, function (row) {
                        return row.id
                    })

                    var func = $.inArray(e.type, ['check', 'check-all']) > -1 ? 'union' : 'difference'
                    selections = window._[func](selections, ids)
                    selections.forEach(element => {
                        user_list.push(element);
                    });
                    $('textarea#user_id').val(user_list);
                })
        })


        function formSuccessFunction(response) {
            setTimeout(() => {
                user_list = [];
                $('#table_list').bootstrapTable('uncheckAll');
                $('.staff-table').hide();
                $('#summary-card').hide();
            }, 2000);
        }
    </script>
@endsection
