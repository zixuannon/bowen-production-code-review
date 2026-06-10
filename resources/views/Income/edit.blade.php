{{-- @dd($fees->installments) --}}
@extends('layouts.master')

@section('title')
    {{ __('Edit Fees')}}
@endsection

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">
                {{ __('Edit Fees')}}
            </h3>
        </div>
        <div class="row">
            @if(isset($student) && $student)
                <div class="col-md-12 grid-margin stretch-card">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="card-title">{{ __('Student Details') }}</h4>
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>{{ __('Student Name') }} : </strong> {{ $student->user->full_name ?? 'N/A' }}</p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>{{ __('Class') }} : </strong> {{ $student->class_section->full_name ?? 'N/A' }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-end mb-3">
                            <a class="btn btn-sm btn-theme" href="{{ route('fees.index') }}">{{ __('back') }}</a>
                        </div>

                        @if($fees->fees_paid_count > 0)
                            <div class="col-12 alert alert-danger">
                                {{__("Certain Fees modification are prohibited because some Parents have already Paid the Fees ")}}
                            </div>
                        @endif
                        @if ($errors->any())
                            <div class="col-12 alert alert-danger">
                                <ul class="mb-0">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        <form id="feesForm" class="common-validation" action="{{ route('fees.update', $fees->id) }}"
                            method="POST" novalidate="novalidate">
                            @csrf
                            <input type="hidden" name="_method" value="PUT" />
                            <div class="border border-secondary rounded-lg mb-2 p-2 mb-3">
                                <div class="col-12 mt-1">
                                    <h4 class="card-title">
                                        {{ __('Fees')}} : {{$fees->class->full_name}}
                                    </h4>
                                    <hr>
                                </div>
                                <div class="row col-12">

                                    <div class="form-group col-sm-12 col-md-6 col-lg-4">
                                        <label>{{ __('Name') }} <span class="text-danger">*</span></label>
                                        {!! Form::text('name', $fees->name, ['placeholder' => __('Name'), 'class' => 'form-control', 'required']) !!}
                                    </div>

                                    <div class="form-group col-sm-12 col-md-6 col-lg-4">
                                        <label>{{ __('Currency') }}</label>
                                        <div class="form-control-plaintext">
                                            {{ __('Current Currency') }} : MMK / K (Kyat)
                                        </div>
                                    </div>

                                </div>
                            </div>
                            <div class="border border-secondary rounded-lg mb-2 p-2 mb-3">
                                <div class="col-12 mt-1">
                                    <h4 class="card-title">
                                        {{ __('Compulsory Fees') }}
                                    </h4>
                                    <hr>
                                </div>
                                <div class="compulsory-fees-types">
                                    <div data-repeater-list="compulsory_fees_type" class="row col-12">
                                        <div class="row col-12 mb-3 compulsory-fee-row" data-repeater-item>
                                            <input type="hidden" name="id" class="fees_class_type_id" />
                                            <div class="form-group col-md-12 col-lg-2">
                                                <select name="fees_type_id" class="form-control fees_type"
                                                    aria-label="Fees Type" required>
                                                    <option value="" hidden="">{{ __('Select Fees Type')}}</option>
                                                    @foreach ($feesTypeData as $feesType)
                                                        <option value="{{ $feesType->id }}">{{ $feesType->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>

                                            {{-- Fee Currency --}}
                                            <div class="form-group col-md-12 col-lg-2">
                                                <label class="small text-muted">{{ __('Currency') }}</label>
                                                <select name="fee_currency" class="form-control fee_currency" aria-label="Currency">
                                                    <option value="MMK" selected>MMK</option>
                                                    <option value="CNY">CNY</option>
                                                    <option value="USD">USD</option>
                                                </select>
                                            </div>

                                            {{-- Fee Original Amount --}}
                                            <div class="form-group col-md-12 col-lg-2">
                                                <label class="small text-muted">{{ __('Original Amount') }}</label>
                                                {!! Form::number('fee_original_amount', null, ['class' => 'form-control fee_original_amount', 'placeholder' => '0.00', 'min' => 0, 'step' => '0.01', 'data-convert' => 'number']) !!}
                                            </div>

                                            {{-- Fee Exchange Rate --}}
                                            <div class="form-group col-md-12 col-lg-2">
                                                <label class="small text-muted">{{ __('Rate to MMK') }}</label>
                                                {!! Form::number('fee_exchange_rate_snapshot', 1, ['class' => 'form-control fee_exchange_rate_snapshot', 'placeholder' => 'Rate', 'min' => 0.0001, 'step' => '0.0001', 'readonly']) !!}
                                            </div>

                                            {{-- Equivalent MMK (calculated display) + Hidden amount fields --}}
                                            <div class="form-group col-md-12 col-lg-2">
                                                <label class="small text-muted">{{ __('MMK Amount') }}</label>
                                                <input type="text" class="form-control equivalent_mmk" placeholder="0.00" readonly>
                                                <input type="hidden" name="fee_amount_mmk" class="fee_amount_mmk_hidden" value="0">
                                                <input type="hidden" name="amount" class="amount" value="0" min="0">
                                            </div>

                                            <div class="col-md-12 col-lg-1 d-flex align-items-end">
                                                <button type="button"
                                                    class="btn btn-inverse-danger btn-icon remove-fees-type mb-3"
                                                    data-repeater-delete>
                                                    <i class="fa fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="col-md-4 pl-0 mb-4">
                                            <button class="btn btn-dark btn-sm add-fees-type" type="button"
                                                data-repeater-create>
                                                <i class="fa fa-plus-circle fa-3x mr-2" aria-hidden="true"></i>
                                                {{__('Add New Data')}}
                                            </button>
                                        </div>
                                    </div>

                                    <div class="col-12 row">
                                        <div class="form-group col-sm-12 col-md-6 col-lg-3">
                                            <label>{{ __('due_date')}} <span class="text-danger">*</span></label>
                                            {{ Form::text('due_date', $fees->due_date, ['id' => 'due_date', 'class' => 'datepicker-popup form-control', 'placeholder' => __('due_date'), 'required', 'autocomplete' => 'off']) }}
                                        </div>
                                        <div class="form-group col-sm-12 col-md-6 col-lg-3">
                                            <label>{{ __('due_charges')}} <span class="text-danger">*</span> <span
                                                    class="text-info small">( {{__('in_percentage')}} )</span></label>
                                            {{ Form::number('due_charges_percentage', $fees->due_charges, ['id' => 'due_charges_percentage', 'class' => 'form-control', 'placeholder' => __('due_charges'), 'min' => 0, 'step' => '0.01']) }}
                                        </div>
                                        <div class="form-group col-sm-12 col-md-6 col-lg-3">
                                            <label>{{ __('due_charges')}} <span class="text-danger">*</span> <span
                                                    class="text-info small">( {{__('Amount')}} )</span></label>
                                            {{ Form::number('due_charges_amount', $fees->due_charges_amount, ['id' => 'due_charges_amount', 'class' => 'form-control', 'placeholder' => __('due_charges'), 'min' => 0, 'step' => '0.01']) }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="border border-secondary rounded-lg mb-2 p-2 mb-3">
                                <div class="col-12 mt-1">
                                    <h4 class="card-title">
                                        {{ __('Fees Installment')}}
                                    </h4>
                                    <hr>
                                </div>
                                <div class="mb-4">
                                    <div class="form-inline col-md-4">
                                        <label>{{__('include_fees_installment')}}</label> <span
                                            class="ml-1 text-danger">*</span>
                                        <div class="ml-4 d-flex">
                                            <div class="form-check form-check-inline">
                                                <label class="form-check-label">
                                                    <input type="radio" name="include_fee_installments"
                                                        class="fees-installment-toggle" value="1"
                                                        {{$fees->include_fee_installments == 1 ? 'checked' : ''}}>
                                                    {{ __('Enable') }}
                                                </label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <label class="form-check-label">
                                                    <input type="radio" name="include_fee_installments"
                                                        id="disable-installment" class="fees-installment-toggle" value="0"
                                                        {{$fees->include_fee_installments == 0 ? 'checked' : ''}}>
                                                    {{ __('Disable') }}
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="fees-installment-repeater"
                                    @if($fees->include_fee_installments == 0) style="display: none" @endif>

                                    <div data-repeater-list="fees_installments">
                                        <div data-repeater-item class="col-12 row">
                                            <input type="hidden" name="id" class="installment_id" />
                                            <div class="form-group col-lg-12 col-xl-3">
                                                <label>{{ __('installment_name') }} <span
                                                        class="text-danger">*</span></label>
                                                {{ Form::text('name', null, ['class' => 'form-control installment-name', 'placeholder' => __('installment') . ' ' . __('name'), 'required']) }}
                                            </div>
                                            <div class="form-group col-lg-12 col-xl-3">
                                                <label>{{ __('amount') }} <span class="text-danger">*</span></label>
                                                {{ Form::text('amount', null, ['class' => 'form-control installment-amount', 'placeholder' => __('amount'), 'required', "data-convert" => "number"]) }}
                                            </div>
                                            <div class="form-group col-lg-12 col-xl-3">
                                                <label>{{ __('due_date') }} <span class="text-danger">*</span></label>
                                                {{ Form::text('due_date', null, ['class' => 'datepicker-popup form-control installment-due-date', 'placeholder' => __('due_date'), 'autocomplete' => 'off', 'required']) }}
                                            </div>
                                            <div class="form-group col-md-12 col-lg-2">
                                                <label>{{ __('Due Charges Type') }} <span
                                                        class="text-danger">*</span></label>
                                                <div>
                                                    <div class="form-check form-check-inline my-0 d-flex">
                                                        <label class="form-check-label mr-2">
                                                            {!! Form::radio('due_charges_type', "fixed", false, ['class' => 'form-check-input fixed_due_charges_type', 'required' => true]) !!}
                                                            {{ __('Fixed Amount') }}
                                                            <i class="input-helper"></i>
                                                        </label>
                                                        <span data-toggle="tooltip" data-placement="top"
                                                            title="{{__("Due Charges will be in fixed amount once the due date is passed")}}"
                                                            class="fa fa-info-circle mb-2"></span>
                                                    </div>
                                                    <div class="form-check form-check-inline my-0 d-flex">
                                                        <label class="form-check-label mr-2">
                                                            {!! Form::radio('due_charges_type', "percentage", true, ['class' => 'form-check-input percentage_due_charges_type', 'required' => true]) !!}
                                                            {{ __('Percentage') }}
                                                            <i class="input-helper"></i>
                                                        </label>
                                                        <span data-toggle="tooltip" data-placement="top"
                                                            title="{{__("Due Charges will be calculated in % on minimum Installment Amount")}}"
                                                            class="fa fa-info-circle mb-2"></span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="form-group col-lg-12 col-xl-3">
                                                <label>{{ __('due_charges') }} <span class="text-danger">*</span></label>
                                                {!! Form::text("due_charges", null, ["class" => "installment-due-charges form-control", "placeholder" => trans('due_charges'), "required" => true, "data-convert" => "number", "min" => 0]) !!}
                                            </div>
                                            <div class="form-group col-lg-12 col-xl-1 mt-4">
                                                <button type="button"
                                                    class="btn btn-inverse-danger btn-icon remove-installment-fee"
                                                    data-repeater-delete>
                                                    <i class="fa fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="col-md-4 pl-0 mb-4 mt-4">
                                            <button class="btn btn-dark btn-sm add-installment" type="button"
                                                data-repeater-create>
                                                <i class="fa fa-plus-circle fa-3x mr-2" aria-hidden="true"></i>
                                                {{__('Add New Data')}}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="border border-secondary rounded-lg mb-2 p-2 mb-3">
                                <div class="col-12 mt-1">
                                    <h4 class="card-title">
                                        {{ __('Optional Fees') }}
                                    </h4>
                                    <hr>
                                </div>
                                <div class="optional-fees-types">
                                    <div data-repeater-list="optional_fees_type" class="row col-12">
                                        <div class="row col-12 mb-3" data-repeater-item>
                                            <input type="hidden" name="id" class="fees_class_type_id" />
                                            <div class="form-group col-md-12 col-lg-4">
                                                <select name="fees_type_id" id="fees_type_id" class="form-control fees_type"
                                                    aria-label="Fees Type" required>
                                                    <option value="" hidden="">{{ __('Select Fees Type')}}</option>
                                                    @foreach ($feesTypeData as $feesType)
                                                        <option value="{{ $feesType->id }}">{{ $feesType->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>

                                            <div class="form-group col-md-12 col-lg-3">
                                                {!! Form::text('amount', null, ['class' => 'form-control amount', 'placeholder' => __('enter') . ' ' . __('fees') . ' ' . __('amount'), 'id' => 'amount', 'required' => true, 'min' => 0, "data-convert" => "number"]) !!}
                                            </div>

                                            <div class="col-md-12 col-lg-1">
                                                <button type="button"
                                                    class="btn btn-inverse-danger btn-icon remove-fees-type"
                                                    data-repeater-delete>
                                                    <i class="fa fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="col-md-4 pl-0 mb-4">
                                            <button class="btn btn-dark btn-sm add-fees-type" type="button"
                                                data-repeater-create>
                                                <i class="fa fa-plus-circle fa-3x mr-2" aria-hidden="true"></i>
                                                {{__('Add New Data')}}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <hr>
                            <input class="btn btn-theme float-right" type="submit" value="{{ __('submit') }}">
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

@endsection
@section('script')
    <script type="application/json" id="fees-data">
    {
        "compulsory_fees": [
            @foreach($fees->compulsory_fees as $index => $type)
                {
                    "id": "{{$type->id}}",
                    "fees_type_id": "{{$type->fees_type_id}}",
                    "amount": "{{$type->amount ?? 0}}",
                    "fee_currency": "{{$type->fee_currency ?? 'MMK'}}",
                    "fee_original_amount": "{{$type->fee_original_amount ?? $type->amount ?? 0}}",
                    "fee_exchange_rate_snapshot": "{{$type->fee_exchange_rate_snapshot ?? 1}}",
                    "fee_amount_mmk": "{{$type->fee_amount_mmk ?? $type->amount ?? 0}}"
                }{{ $index < count($fees->compulsory_fees) - 1 ? ',' : '' }}
            @endforeach
        ],
        "optional_fees": [
            @foreach($fees->optional_fees as $index => $type)
                {
                    "id": "{{$type->id}}",
                    "fees_type_id": "{{$type->fees_type_id}}",
                    "amount": "{{$type->amount}}"
                }{{ $index < count($fees->optional_fees) - 1 ? ',' : '' }}
            @endforeach
        ],
        "installments": [
            @foreach($fees->installments as $index => $installment)
                {
                    "id": "{{$installment->id}}",
                    "name": "{{$installment->name}}",
                    "amount": "{{$installment->installment_amount}}",
                    "due_date": "{{$installment->due_date}}",
                    "due_charges": "{{$installment->due_charges}}",
                    "due_charges_type": "{{$installment->due_charges_type}}"
                }{{ $index < count($fees->installments) - 1 ? ',' : '' }}
            @endforeach
        ],
        "fees_paid_count": {{ $fees->fees_paid_count }},
        "installment_count": {{ count($fees->installments) }}
    }
    </script>

    <script>
        // === Multi-Currency Logic (Compulsory Fees) ===
        (function() {
            // 使用全局 window.currencyConfig 汇率（footer_js.blade.php 已提供），与全站统一
            function getDefaultRates() {
                if (window.currencyConfig && window.currencyConfig.rates) {
                    return window.currencyConfig.rates;
                }
                // Fallback（极端情况下 window.currencyConfig 未加载）
                return { 'MMK': 1, 'CNY': 500, 'USD': 3500 };
            }

            // Calculate equivalent MMK for a given row
            function calcRowMMK($row) {
                var originalAmount = parseFloat($row.find('.fee_original_amount').val()) || 0;
                var exchangeRate = parseFloat($row.find('.fee_exchange_rate_snapshot').val()) || 1;
                var mmkValue = (originalAmount * exchangeRate).toFixed(2);

                $row.find('.equivalent_mmk').val(mmkValue);
                $row.find('.fee_amount_mmk_hidden').val(mmkValue);
                $row.find('.amount').val(mmkValue);
            }

            // Initialize a single row's currency/rate logic
            function initSingleRow($row) {
                var currency = $row.find('.fee_currency').val() || 'MMK';
                if (currency === 'MMK') {
                    $row.find('.fee_exchange_rate_snapshot').val(1).prop('readonly', true);
                } else {
                    $row.find('.fee_exchange_rate_snapshot').prop('readonly', false);
                }
                calcRowMMK($row);
            }

            // Initialize all rows after setList populates the DOM
            window.initCompulsoryFeeRows = function() {
                $('.compulsory-fee-row').each(function() {
                    initSingleRow($(this));
                });
            };

            // Currency change
            $(document).on('change', '.compulsory-fee-row .fee_currency', function() {
                var $row = $(this).closest('.compulsory-fee-row');
                var currency = $(this).val();
                var rates = getDefaultRates();

                if (currency === 'MMK') {
                    $row.find('.fee_exchange_rate_snapshot').val(1).prop('readonly', true);
                } else {
                    $row.find('.fee_exchange_rate_snapshot').val(rates[currency] || 1).prop('readonly', false);
                }
                calcRowMMK($row);
            });

            // Original amount change
            $(document).on('input', '.compulsory-fee-row .fee_original_amount', function() {
                calcRowMMK($(this).closest('.compulsory-fee-row'));
            });

            // Exchange rate change
            $(document).on('input', '.compulsory-fee-row .fee_exchange_rate_snapshot', function() {
                calcRowMMK($(this).closest('.compulsory-fee-row'));
            });
        })();
        // === End Multi-Currency Logic ===

        $(document).ready(function () {
            var feesData = JSON.parse(document.getElementById('fees-data').textContent);

            // === 稳健获取 compulsoryFeesTypeRepeater 实例 ===
            // 优先用全局 const（custom.js 定义），其次 window 属性，最后自行初始化
            var compRepeater = null;
            function getCompRepeater() {
                // 尝试1：全局 const（custom.js 顶层声明）
                try {
                    if (typeof compulsoryFeesTypeRepeater !== 'undefined' &&
                        compulsoryFeesTypeRepeater !== null &&
                        typeof compulsoryFeesTypeRepeater.setList === 'function') {
                        return compulsoryFeesTypeRepeater;
                    }
                } catch (e) {}
                // 尝试2：window 属性
                try {
                    if (window.compulsoryFeesTypeRepeater &&
                        typeof window.compulsoryFeesTypeRepeater.setList === 'function') {
                        return window.compulsoryFeesTypeRepeater;
                    }
                } catch (e) {}
                // 尝试3：fallback - 自行初始化 repeater
                var $container = $('.compulsory-fees-types');
                if ($container.length === 0) return null;
                var instance = $container.repeater({
                    isFirstItemUndeletable: true,
                    initEmpty: true,
                    show: function () {
                        $(this).slideDown();
                        $(this).find('.fees_type').rules("add", {
                            "noDuplicateValues": {
                                parentClass: "fees-class-types",
                                class: "fees_type",
                                value: $(this).find('.fees_type').find("option:selected").text()
                            }
                        });
                        $(this).find('input[data-convert="number"]').removeAttr('type').attr('type', "number");
                        $(this).find('.optional_no').prop('checked', true);
                    },
                    hide: function (deleteElement) {
                        var feesClassTypeID = $(this).find('.fees_class_type_id').val();
                        if (feesClassTypeID) {
                            var delUrl = baseUrl + '/fees/class-type/' + feesClassTypeID;
                            if (typeof showDeletePopupModal === 'function') {
                                showDeletePopupModal(delUrl, {
                                    successCallBack: function () { $(this).slideUp(deleteElement); }
                                });
                            } else {
                                $(this).slideUp(deleteElement);
                            }
                        } else {
                            $(this).slideUp(deleteElement);
                        }
                    }
                });
                // 挂到 window 上供其他脚本复用
                window.compulsoryFeesTypeRepeater = instance;
                return instance;
            }

            compRepeater = getCompRepeater();

            if (compRepeater && typeof compRepeater.setList === 'function') {
                compRepeater.setList(feesData.compulsory_fees);
                // 轮询初始化多币种字段（setList 异步渲染 DOM，需要等待）
                var retryCount = 0;
                var maxRetries = 15;
                var retryInterval = setInterval(function() {
                    retryCount++;
                    var rows = $('.compulsory-fee-row');
                    if (rows.length > 0 && rows.first().find('.fee_currency').length > 0) {
                        clearInterval(retryInterval);
                        if (typeof initCompulsoryFeeRows === 'function') {
                            initCompulsoryFeeRows();
                        }
                    } else if (retryCount >= maxRetries) {
                        clearInterval(retryInterval);
                    }
                }, 100);
            } else {
                console.error('[EditFees] 无法初始化 compulsory repeater，compulsory rows 将不显示');
            }

            // 监听 "Add New Data" 按钮新增行：也初始化多币种字段
            $(document).on('click', '.compulsory-fees-types [data-repeater-create]', function() {
                setTimeout(function() {
                    var $newRows = $('.compulsory-fee-row');
                    if ($newRows.length > 0) {
                        // 初始化所有未初始化的行
                        $newRows.each(function() {
                            var $row = $(this);
                            if ($row.find('.fee_currency').length > 0 &&
                                $row.find('.equivalent_mmk').val() === '') {
                                var currency = $row.find('.fee_currency').val() || 'MMK';
                                if (currency === 'MMK') {
                                    $row.find('.fee_exchange_rate_snapshot').val(1).prop('readonly', true);
                                }
                            }
                        });
                    }
                }, 150);
            });

            if (typeof optionalFeesTypeRepeater !== 'undefined') {
                optionalFeesTypeRepeater.setList(feesData.optional_fees);
            }

            if (typeof feesInstallmentRepeater !== 'undefined') {
                feesInstallmentRepeater.setList(feesData.installments);
            }

            if (feesData.installment_count > 0) {
                $('#disable-installment').attr('disabled', true);
            }

            if (feesData.fees_paid_count > 0) {
                // Make readonly to certain fields as fees have already paid
                $('.fees-installment-toggle,.fixed_due_charges_type,.percentage_due_charges_type').attr('readonly', true).bind('click', function () {
                    return false;
                });

                $('.installment-name, .installment-amount, .installment-due-date,.installment-due-charges,.fees_type,.amount,#due_date,#due_charges_percentage,#due_charges_amount,.fee_currency,.fee_original_amount,.fee_exchange_rate_snapshot,.equivalent_mmk').attr('readonly', true);

                $('.fees_type option:not(:selected)').attr('disabled', true);
                $('.fee_currency option:not(:selected)').attr('disabled', true);

                $('.remove-fees-type,.remove-installment-fee').bind('click', function () {
                    return false;
                });
                $('.add-fees-type,.add-installment').prop('disabled', true);
            }
        });

        $('#feesForm').on('submit', function (e) {
            function toNumber(value) {
                if (value === null || value === undefined) {
                    return 0;
                }
                var normalized = String(value).replace(/[^0-9.\-]/g, '');
                var n = parseFloat(normalized);
                return isNaN(n) ? 0 : n;
            }

            var compulsoryValuesRaw = $('.compulsory-fees-types .amount').toArray().map(function (el) {
                return $(el).val();
            });
            var optionalValuesRaw = $('.optional-fees-types .amount').toArray().map(function (el) {
                return $(el).val();
            });
            var installmentValuesRaw = $('.fees-installment-repeater [data-repeater-item] .installment-amount').toArray().map(function (el) {
                return $(el).val();
            });
            var dueChargesAmountRaw = $('#due_charges_amount').val();

            console.log('[FeesForm submit] compulsoryValuesRaw=', compulsoryValuesRaw);
            console.log('[FeesForm submit] optionalValuesRaw=', optionalValuesRaw);
            console.log('[FeesForm submit] installmentValuesRaw=', installmentValuesRaw);
            console.log('[FeesForm submit] dueChargesAmountRaw=', dueChargesAmountRaw);

            var compulsoryTotal = $('.compulsory-fees-types .amount').toArray().reduce(function (sum, el) {
                return sum + toNumber($(el).val());
            }, 0);

            var installmentsTotal = $('.fees-installment-repeater [data-repeater-item] .installment-amount').toArray().reduce(function (sum, el) {
                return sum + toNumber($(el).val());
            }, 0);

            var isInstallmentEnabled = $('.fees-installment-toggle:checked').val() === '1';
            var diff = installmentsTotal - compulsoryTotal;
            var willPreventDefault = false;

            console.log('[FeesForm submit] compulsoryTotal=', compulsoryTotal);
            console.log('[FeesForm submit] installmentsTotal=', installmentsTotal);
            console.log('[FeesForm submit] isInstallmentEnabled=', isInstallmentEnabled);
            console.log('[FeesForm submit] diff=', diff);

            if (isInstallmentEnabled && Math.abs(diff) > 0.01) {
                willPreventDefault = true;
                e.preventDefault();
                console.log('[FeesForm submit] preventDefault=', willPreventDefault);
                console.log('[FeesForm submit] allowSubmit=', false);

                if (window.Swal && typeof window.Swal.fire === 'function') {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'error',
                        title: 'Installment total must equal compulsory total',
                        text: 'Compulsory: ' + compulsoryTotal.toFixed(2) + ' | Installments: ' + installmentsTotal.toFixed(2),
                        showConfirmButton: false,
                        timer: 2000,
                        timerProgressBar: true,
                    });
                } else {
                    alert('Installment total must equal compulsory total. Compulsory: ' + compulsoryTotal.toFixed(2) + ' | Installments: ' + installmentsTotal.toFixed(2));
                }

                return false;
            }

            console.log('[FeesForm submit] preventDefault=', willPreventDefault);
            console.log('[FeesForm submit] allowSubmit=', true);
        });
    </script>
@endsection
