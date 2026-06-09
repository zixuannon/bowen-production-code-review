@extends('layouts.master')

@section('title')
    {{ __('expense') }}
@endsection

@php
    // 多币种汇率
    $schoolSettings = getSchoolSettings();
    $usdRate = (float)($schoolSettings['usd_exchange_rate'] ?? 3500);
    $cnyRate = (float)($schoolSettings['cny_exchange_rate'] ?? 500);
@endphp

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">
                {{ __('manage') . ' ' . __('expense') }}
            </h3>
        </div>
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">
                            {{ __('create') . ' ' . __('expense') }}
                        </h4>
                        <form class="pt-3" id="create-form" action="{{ route('expense.store') }}" method="POST"
                            novalidate="novalidate" enctype="multipart/form-data">
                            <div class="row">
                                <div class="form-group col-sm-12 col-md-5">
                                    <label>{{ __('select') }} {{ __('category') }} <span
                                            class="text-danger">*</span></label>
                                    {!! Form::select('category_id', $expenseCategory, null, ['required', 'class' => 'form-control', 'placeholder' => __('select') . ' ' . __('category')]) !!}
                                </div>

                                <div class="form-group col-sm-12 col-md-5">
                                    <label for="title">{{ __('title') }} <span class="text-danger">*</span></label>
                                    <input name="title" id="title" type="text" placeholder="{{ __('title') }}"
                                        class="form-control" required />
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label for="ref_no">{{ __('reference_no') }}</label>
                                    <input name="ref_no" id="ref_no" type="text" placeholder="{{ __('reference_no') }}"
                                        class="form-control" />
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label for="transaction_currency">{{ __('Currency') }} <span class="text-danger">*</span></label>
                                    <select name="transaction_currency" id="transaction_currency" class="form-control" required>
                                        <option value="MMK" selected>MMK</option>
                                        <option value="USD">USD</option>
                                        <option value="CNY">CNY</option>
                                    </select>
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label for="original_amount">{{ __('Original Amount') }} <span class="text-danger">*</span></label>
                                    <input name="original_amount" id="original_amount" type="number" min="0" step="0.01"
                                        placeholder="{{ __('Original Amount') }}" class="form-control" required />
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label for="exchange_rate_snapshot">{{ __('Exchange Rate') }} <span class="text-danger">*</span></label>
                                    <input name="exchange_rate_snapshot" id="exchange_rate_snapshot" type="number" min="0" step="0.0001"
                                        value="1" placeholder="{{ __('Rate to MMK') }}" class="form-control" required />
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label for="amount">{{ __('MMK Equivalent Amount') }} (MMK) <span class="text-danger">*</span></label>
                                    <input name="amount" id="amount" type="number" min="0" step="0.01" placeholder="{{ __('MMK Equivalent') }}"
                                        class="form-control" required readonly />
                                </div>

                                <div class="form-group col-sm-12 col-md-3">
                                    <label for="date">{{ __('date') }} <span class="text-danger">*</span></label>
                                    <input name="date" id="date" type="text" placeholder="{{ __('date') }}"
                                        class="datepicker-popup-no-future form-control" autocomplete="off" required />
                                </div>

                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="description">{{ __('description') }} </label>
                                    <textarea name="description" id="description" placeholder="{{ __('description') }}"
                                        class="form-control"></textarea>
                                </div>

                                <div class="form-group col-sm-12 col-md-3">
                                    <label for="">{{ __('select') }} {{ __('session_year') }}</label>
                                    {!! Form::select('session_year_id', $sessionYear, $current_session_year->id, ['required', 'class' => 'form-control', 'id' => 'session_year']) !!}
                                </div>

                            </div>
                            <div class="alert alert-info py-1 px-3 mb-3" style="font-size:0.85rem;">
                                <i class="fa fa-info-circle mr-1"></i>
                                MMK Equivalent Amount is auto-calculated. Exchange rate can be overridden manually.
                            </div>
                            <input class="btn btn-theme float-right ml-3" id="create-btn" type="submit" value={{ __('submit') }}>
                            <input class="btn btn-secondary float-right" type="reset" value={{ __('reset') }}>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">{{ __('list') . ' ' . __('expense') }}</h4>
                        <div class="row" id="toolbar">

                            <div class="form-group col-sm-12 col-md-4">
                                <label class="filter-menu">{{ __('session_year') }}</label>
                                {!! Form::select('session_year_id', $sessionYear, $current_session_year->id, ['class' => 'form-control', 'id' => 'filter_session_year_id']) !!}
                            </div>

                            <div class="form-group col-sm-12 col-md-4">
                                <label class="filter-menu">{{ __('category') }}</label>
                                {!! Form::select('category_id', $expenseCategory + ['salary' => __('salary')], null, ['class' => 'form-control', 'id' => 'filter_category_id', 'placeholder' => __('all')]) !!}
                            </div>

                            <div class="form-group col-sm-12 col-md-4">
                                <label class="filter-menu"> {{ __('month') }}</label>
                                {!! Form::select('month', $months, null, ['class' => 'form-control', 'id' => 'filter_month', 'placeholder' => __('all')]) !!}
                            </div>

                        </div>

                        <table aria-describedby="mydesc" class='table' id='table_list' data-toggle="table"
                            data-url="{{ route('expense.show', [1]) }}" data-click-to-select="true"
                            data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100, 200]"
                            data-search="true" data-show-columns="true" data-show-refresh="true" data-fixed-columns="false"
                            data-fixed-number="2" data-fixed-right-number="1" data-trim-on-search="false"
                            data-mobile-responsive="true" data-sort-name="date" data-sort-order="desc"
                            data-maintain-selected="true" data-export-data-type='all' data-query-params="ExpenseQueryParams"
                            data-toolbar="#toolbar"
                            data-export-options='{ "fileName": "expense-list-<?= date('d-m-y') ?>" ,"ignoreColumn":["operate"]}'
                            data-show-export="true" data-show-footer="true" data-escape="true">
                            <thead>
                                <tr>
                                    <th scope="col" data-field="id" data-sortable="true" data-visible="false">{{ __('id') }}
                                    </th>
                                    <th scope="col" data-field="no">{{ __('no.') }}</th>
                                    <th scope="col" data-field="ref_no" data-sortable="false">{{ __('reference_no') }}</th>
                                    <th scope="col" data-field="title" data-sortable="false">{{ __('title') }}</th>
                                    <th scope="col" data-field="category.name" data-sortable="false">{{ __('category') }}
                                    </th>
                                    <th scope="col" data-field="description" data-sortable="false">{{ __('description') }}
                                    </th>
                                    <th scope="col" data-field="date" data-sortable="false"
                                        data-footer-formatter="totalFormatter">{{ __('date') }}</th>
                                    <th scope="col" data-field="amount" data-sortable="false"
                                        data-formatter="amountFormatter" data-footer-formatter="totalAmountFormatter">
                                        {{ __('Amount') }} ({{ __('MMK') }})
                                    </th>
                                    <th scope="col" data-field="operate" data-events="expenseEvents" data-escape="false">
                                        {{ __('action') }}
                                    </th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Modal -->
            <div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
                aria-hidden="true">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="exampleModalLabel">{{ __('edit') . ' ' . __('expense') }}</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <form class="pt-3 edit-form" id="" action="{{ url('expense') }}" novalidate="novalidate">
                            @csrf
                            <div class="modal-body">
                                <input type="hidden" name="id" id="edit_id" value="" />
                                <div class="row">

                                    <div class="form-group col-sm-12 col-md-5">
                                        <label>{{ __('select') }} {{ __('category') }} <span
                                                class="text-danger">*</span></label>
                                        {!! Form::select('category_id', $expenseCategory, null, ['required', 'class' => 'form-control', 'placeholder' => __('select') . ' ' . __('category'), 'id' => 'edit_category_id']) !!}
                                    </div>

                                    <div class="form-group col-sm-12 col-md-5">
                                        <label for="edit_title">{{ __('title') }} <span class="text-danger">*</span></label>
                                        <input name="title" id="edit_title" type="text" placeholder="{{ __('title') }}"
                                            class="form-control" required />
                                    </div>

                                    <div class="form-group col-sm-12 col-md-2">
                                        <label for="edit_ref_no">{{ __('reference_no') }}</label>
                                        <input name="ref_no" id="edit_ref_no" type="text"
                                            placeholder="{{ __('reference_no') }}" class="form-control" />
                                    </div>

                                    <div class="form-group col-sm-12 col-md-2">
                                        <label for="edit_currency">{{ __('Currency') }} <span
                                                class="text-danger">*</span></label>
                                        <select name="transaction_currency" id="edit_currency" class="form-control" required>
                                            <option value="MMK">MMK</option>
                                            <option value="USD">USD</option>
                                            <option value="CNY">CNY</option>
                                        </select>
                                    </div>

                                    <div class="form-group col-sm-12 col-md-2">
                                        <label for="edit_original_amount">{{ __('Original Amount') }} <span
                                                class="text-danger">*</span></label>
                                        <input name="original_amount" id="edit_original_amount" type="number" min="0" step="0.01"
                                            placeholder="{{ __('Original Amount') }}" class="form-control" required />
                                    </div>

                                    <div class="form-group col-sm-12 col-md-2">
                                        <label for="edit_exchange_rate">{{ __('Exchange Rate') }} <span
                                                class="text-danger">*</span></label>
                                        <input name="exchange_rate_snapshot" id="edit_exchange_rate" type="number" min="0" step="0.0001"
                                            placeholder="{{ __('Rate to MMK') }}" class="form-control" required />
                                    </div>

                                    <div class="form-group col-sm-12 col-md-2">
                                        <label for="edit_amount">{{ __('MMK Equivalent Amount') }} (MMK) <span
                                                class="text-danger">*</span></label>
                                        <input name="amount" id="edit_amount" type="number" min="0" step="0.01"
                                            placeholder="{{ __('MMK Equivalent') }}"
                                            class="form-control" required readonly />
                                    </div>

                                    <div class="form-group col-sm-12 col-md-3">
                                        <label for="edit_date">{{ __('date') }} <span class="text-danger">*</span></label>
                                        <input name="date" id="edit_date" type="text" placeholder="{{ __('date') }}"
                                            class="datepicker-popup-no-future form-control" required />
                                    </div>


                                    <div class="form-group col-sm-12 col-md-6">
                                        <label for="edit_description">{{ __('description') }}</label>
                                        <textarea name="description" id="edit_description" class="form-control"></textarea>
                                    </div>

                                    <div class="form-group col-sm-12 col-md-3">
                                        <label for="">{{ __('select') }} {{ __('session_year') }}</label>
                                        {!! Form::select('session_year_id', $sessionYear, $current_session_year->id, ['required', 'class' => 'form-control', 'id' => 'edit_session_year_id']) !!}
                                    </div>
                                </div>


                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary"
                                        data-dismiss="modal">{{ __('close') }}</button>
                                    <input class="btn btn-theme" type="submit" value="{{ __('submit') }}" />
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('js')
    <script>
        let sessionYearFullData = @json($sessionYearFullData);
        const USD_RATE = {{ $usdRate }};
        const CNY_RATE = {{ $cnyRate }};

        // ========== 多货币前端逻辑 ==========

        // 创建表单：币种切换
        $('#transaction_currency').on('change', function () {
            var currency = $(this).val();
            if (currency === 'MMK') {
                $('#exchange_rate_snapshot').val(1).prop('readonly', true);
            } else if (currency === 'USD') {
                $('#exchange_rate_snapshot').val(USD_RATE).prop('readonly', false);
            } else if (currency === 'CNY') {
                $('#exchange_rate_snapshot').val(CNY_RATE).prop('readonly', false);
            }
            recalcAmountFromOriginal();
        });

        // 创建表单：original_amount 或 exchange_rate 变化时重算 MMK amount
        $('#original_amount, #exchange_rate_snapshot').on('input', function () {
            recalcAmountFromOriginal();
        });

        function recalcAmountFromOriginal() {
            var currency = $('#transaction_currency').val();
            var originalAmount = parseFloat($('#original_amount').val()) || 0;
            var rate = parseFloat($('#exchange_rate_snapshot').val()) || 1;

            if (currency === 'MMK') {
                // MMK：amount = original_amount
                $('#amount').val(originalAmount > 0 ? originalAmount.toFixed(2) : '');
            } else {
                // USD / CNY：amount = original_amount * rate
                if (originalAmount > 0 && rate > 0) {
                    $('#amount').val((originalAmount * rate).toFixed(2));
                } else {
                    $('#amount').val('');
                }
            }
        }

        // 列表金额格式化器：三行显示
        function amountFormatter(value, row) {
            if (!value && value !== 0) return '-';

            var currency = row.transaction_currency || 'MMK';
            var originalAmount = row.original_amount;
            var rate = row.exchange_rate_snapshot;
            var mmkAmount = row.amount_mmk || value;

            var mmkFormatted = Number(mmkAmount).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0}) + ' K';

            if (!originalAmount && !rate) {
                // 旧数据 fallback
                return '<div class="text-muted" style="font-size:0.8rem;">' + mmkFormatted + '<br><span class="text-secondary">MMK (legacy)</span></div>';
            }

            var rateDisplay = rate ? Number(rate).toFixed(2) : '1.00';
            return mmkFormatted + '<br><span class="text-secondary" style="font-size:0.78rem;">' +
                Number(originalAmount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + currency + ' @ ' + rateDisplay +
                '</span>';
        }

        // 编辑 modal：打开时填充多币种字段（含旧数据 fallback）
        $(document).on('shown.bs.modal', '#editModal', function (event) {
            var button = $(event.relatedTarget);
            var row = button.closest('tr');
            var rowIndex = row.index();
            var table = $('#table_list').bootstrapTable('getData');
            var data = table[rowIndex] || row.data() || {};

            var currency = data.transaction_currency || 'MMK';
            // fallback：旧数据没有 transaction_currency，默认为 MMK
            if (!data.transaction_currency) currency = 'MMK';

            // fallback：原币金额
            var originalAmt = data.original_amount;
            if (!originalAmt && originalAmt !== 0) {
                // 旧数据 fallback：原币 = amount
                originalAmt = data.amount;
            }

            // fallback：汇率
            var rateVal = data.exchange_rate_snapshot;
            if (!rateVal && rateVal !== 0) {
                rateVal = (currency === 'USD') ? USD_RATE : (currency === 'CNY') ? CNY_RATE : 1;
            }

            $('#edit_currency').val(currency).trigger('change.select2');
            $('#edit_exchange_rate').val(rateVal);

            if (currency === 'MMK') {
                $('#edit_exchange_rate').prop('readonly', true);
                $('#edit_original_amount').val(originalAmt > 0 ? originalAmt : '');
                $('#edit_amount').val(originalAmt > 0 ? Number(originalAmt).toFixed(2) : '');
            } else {
                $('#edit_exchange_rate').prop('readonly', false);
                $('#edit_original_amount').val(originalAmt > 0 ? Number(originalAmt).toFixed(2) : '');
                // MMK equivalent = original * rate
                if (originalAmt > 0 && rateVal > 0) {
                    $('#edit_amount').val((originalAmt * rateVal).toFixed(2));
                } else {
                    $('#edit_amount').val('');
                }
            }
        });

        // 编辑 modal：币种切换
        $(document).on('change', '#edit_currency', function () {
            var currency = $(this).val();
            if (currency === 'MMK') {
                $('#edit_exchange_rate').val(1).prop('readonly', true);
            } else {
                $('#edit_exchange_rate').val(currency === 'USD' ? USD_RATE : CNY_RATE).prop('readonly', false);
            }
            recalcEditAmount();
        });

        // 编辑 modal：original_amount 或 exchange_rate 变化时重算 MMK amount
        $(document).on('input', '#edit_original_amount, #edit_exchange_rate', function () {
            recalcEditAmount();
        });

        function recalcEditAmount() {
            var currency = $('#edit_currency').val();
            var original = parseFloat($('#edit_original_amount').val()) || 0;
            var rate = parseFloat($('#edit_exchange_rate').val()) || 1;

            if (currency === 'MMK') {
                $('#edit_amount').val(original > 0 ? original.toFixed(2) : '');
            } else {
                if (original > 0 && rate > 0) {
                    $('#edit_amount').val((original * rate).toFixed(2));
                } else {
                    $('#edit_amount').val('');
                }
            }
        }

        // ========== /多货币前端逻辑 ========== 

        function setDatepickerLimits(sessionYearId) {
            // Find the selected session year object
            let sessionYear = sessionYearFullData.find(s => s.id == sessionYearId);
            if (!sessionYear) return;

            let startDate = new Date(sessionYear.original_start_date);
            let endDate = new Date(sessionYear.original_end_date);
            // Destroy old datepicker if exists
            $('#date').datepicker('destroy');

            // Initialize datepicker with limits
            $('#date').datepicker({
                format: datepickerReplacedFormat,
                autoclose: true,
                todayHighlight: true,
                startDate: startDate,
                endDate: endDate,
                orientation: 'bottom auto',
                rtl: isRTL()
            });
        }

        function setEditDatepickerLimits(sessionYearId, selectedDate = null) {
            // Find the selected session year object
            let sessionYear = sessionYearFullData.find(s => s.id == sessionYearId);
            if (!sessionYear) return;

            let startDate = new Date(sessionYear.original_start_date);
            let endDate = new Date(sessionYear.original_end_date);

            // Destroy old datepicker if exists
            $('#edit_date').datepicker('destroy');

            // Initialize datepicker with limits
            $('#edit_date').datepicker({
                format: datepickerReplacedFormat,
                autoclose: true,
                todayHighlight: true,
                startDate: startDate,
                endDate: endDate,
                orientation: 'bottom auto',
                rtl: isRTL()
            });

            // Set existing date
            if (selectedDate) {
                $('#edit_date').datepicker('setDate', selectedDate);
            }
        }
        function isRTL() {
            var dir = $('html').attr('dir');
            if (dir === 'rtl') {
                return true;
            } else {
                return false;
            }
            return false;
        }

        // On page load
        let initialSessionYearId = $('#session_year').val();
        setDatepickerLimits(initialSessionYearId);

        // On session year change
        $('#session_year').on('change', function () {
            let selectedId = $(this).val();
            setDatepickerLimits(selectedId);
            $('#date').val(''); // Clear previous date
        });
        $('#edit_session_year_id').on('change', function () {
            let selectedId = $(this).val();
            setEditDatepickerLimits(selectedId);
            $('#edit_date').val(''); // clear date when session changes
        });
        // For Create Form
        $('#create-form').on('reset', function () {
            setTimeout(() => {
                let sessionYearId = $('#session_year').val(); // default session year
                setDatepickerLimits(sessionYearId);
                $('#date').val(''); // clear date
            }, 100);
        });

        // For Edit Form
        $('.edit-form').on('reset', function () {
            let sessionYearId = $('#edit_session_year_id').val(); // current session year in edit modal
            setEditDatepickerLimits(sessionYearId);
            $('#edit_date').val(''); // clear date
        });
    </script>
@endsection