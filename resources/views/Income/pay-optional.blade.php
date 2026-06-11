@extends('layouts.master')

@section('title')
    {{ __('Pay Optional Fees') }}
@endsection

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">
                {{ __('Pay Optional Fees') }}
            </h3>
        </div>

        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-header d-flex justify-content-end align-items-center">
                        <a href="{{ route('fees.paid.index') }}" class="btn btn-theme float-right">Back</a>
                    </div>
                    <div class="card-body d-flex justify-content-center">
                        <form class="pt-3 create-form form-validation col-sm-12 col-md-5" method="post" action="{{ route('fees.optional.store') }}" novalidate="novalidate" data-success-function="formSuccessFunction">
                            <input type="hidden" name="fees_id" id="optional-fees-id" value="{{$fees->id}}"/>
                            <input type="hidden" name="student_id" id="student-id" value="{{$student->id}}"/>
                            <input type="hidden" name="class_id" id="class-id" value="{{$student->student->class_section->class_id}}"/>
                            <h4>{{$student->full_name.' :- '.$student->student->class_section->full_name}}</h4><br>
                            <div class="form-group">
                                <label for="payment-date">{{ __('date') }} <span class="text-danger">*</span></label>
                                <input id="payment-date" type="text" name="date" class="datepicker-popup paid-date form-control" placeholder="{{ __('date') }}" autocomplete="off" required>
                            </div>

                            <hr>
                            <div class="form-group col-sm-12 col-md-12">
                                <div class="optional-fees-content">
                                    <table class="table">
                                        <tbody>
                                        @foreach($optionalFeesData as $key =>$optionalFee)
                                            <tr>
                                                <td class="text-left">
                                                    @if(count($optionalFee->optional_fees_paid))
                                                        <span data-id="{{ $optionalFee->optional_fees_paid[0]['id']}}" class="text-danger remove-paid-optional-fees" style="cursor: pointer;"><i class="fa fa-times"></i></span>
                                                    @else
                                                        <input style="cursor: pointer;" type="checkbox" class="optional-fee-payment" id="optional-{{ $optionalFee->id }}" data-amount="{{ $optionalFee->amount }}" name="fees_class_type[{{ $key }}][id]" value="{{ $optionalFee->id }}">
                                                    @endif
                                                </td>
                                                <td colspan="2" class="text-left">
                                                    <label style="cursor: pointer;" for="optional-{{ $optionalFee->id }}">{{$optionalFee->fees_type_name}}</label>
                                                </td>
                                                <td style="cursor: default;" class="text-right">
                                                    {{ number_format($optionalFee->amount) }} K
                                                    {!! Form::hidden('fees_class_type['.$key.'][amount]', $optionalFee->amount) !!}
                                                </td>
                                            </tr>
                                        @endforeach

                                        <tr id="optional-total-amount-to-pay" style="display: none">
                                            <td class="text-left"></td>
                                            <td colspan="2" class="text-left"><label>{{__("Total Amount")}}</label></td>
                                            <td class="text-right" id="optional-total-amount"></td>
                                            {!! Form::hidden('total_amount',null, ["id" => "form-total-optional-amount"]) !!}
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            {{-- Multi-Currency Payment Section --}}
                            <div id="multi-currency-section" style="display: none;">
                                <hr>
                                <div class="form-group col-sm-12 col-md-12">
                                    <label>{{ __('Payment Currency') }}</label>
                                    <div class="row">
                                        <div class="form-group col-md-6">
                                            <select id="transaction_currency_select" class="form-control" aria-label="Payment Currency">
                                                <option value="MMK" selected>MMK</option>
                                                <option value="CNY">CNY</option>
                                                <option value="USD">USD</option>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label class="small text-muted">{{ __('Exchange Rate (to MMK)') }}</label>
                                            <input type="text" id="exchange_rate_input" class="form-control" value="1" inputmode="decimal" pattern="[0-9.]*" readonly>
                                        </div>
                                    </div>
                                    <div class="row mt-2">
                                        <div class="form-group col-md-6">
                                            <label class="small text-muted">{{ __('Original Payment Amount') }}</label>
                                            <div class="input-group">
                                                <input type="text" id="original_amount_display" class="form-control" placeholder="0.00" readonly>
                                                <div class="input-group-text" id="original_amount_currency_suffix">MMK</div>
                                            </div>
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label class="small text-muted">{{ __('MMK Equivalent') }}</label>
                                            <div class="input-group">
                                                <input type="text" id="amount_mmk_display" class="form-control" placeholder="0.00" readonly>
                                                <div class="input-group-text">K</div>
                                            </div>
                                        </div>
                                    </div>
                                    {{-- Hidden fields for form submission --}}
                                    <input type="hidden" name="transaction_currency" id="hidden_transaction_currency" value="MMK">
                                    <input type="hidden" name="original_amount" id="hidden_original_amount" value="0">
                                    <input type="hidden" name="exchange_rate_snapshot" id="hidden_exchange_rate_snapshot" value="1">
                                    <input type="hidden" name="amount_mmk" id="hidden_amount_mmk" value="0">
                                </div>
                            </div>
                            {{-- End Multi-Currency --}}
                            <hr>
                            <div class="row mode-container">
                                <div class="form-group col-sm-12 col-md-12">
                                    <label>{{ __('Mode') }} <span class="text-danger">*</span></label><br>
                                    <div class="d-flex">
                                        <div class="form-check form-check-inline">
                                            <label class="form-check-label">
                                                <input type="radio" name="mode" class="cash-compulsory-mode  mode" value="1" checked>
                                                {{ __('cash') }}
                                            </label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <label class="form-check-label">
                                                <input type="radio" name="mode" class="cheque-compulsory-mode mode" value="2">
                                                {{ __('cheque') }}
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group cheque-no-container" style="display: none">
                                <label for="cheque_no">{{ __('cheque_no') }} <span class="text-danger">*</span></label>
                                <input type="number" id="cheque_no" name="cheque_no" placeholder="{{ __('cheque_no') }}" class="form-control cheque-no" required/>
                            </div>
                            <input class="btn btn-theme float-right" type="submit" id="pay-button" disabled value={{ __('pay') }} />
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

@endsection
@section('js')
    <script>
        $('#payment-date').datepicker({
            format: "dd-mm-yyyy",
            rtl: isRTL()
        }).datepicker("setDate", 'now');

        // 从 footer 全局配置读取汇率默认值
        var exchangeRates = { MMK: 1, CNY: 500, USD: 3500 };
        try {
            var configEl = document.getElementById('currency-config-json');
            if (configEl) {
                var config = JSON.parse(configEl.textContent);
                if (config.rates) {
                    exchangeRates = config.rates;
                }
            }
        } catch(e) {}

        var totalAmount = 0;

        function formatAmountK(value) {
            return value.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',') + ' K';
        }

        // 更新多币种字段显示
        function updateMultiCurrencyFields() {
            if (totalAmount <= 0) {
                $('#multi-currency-section').hide();
                return;
            }
            $('#multi-currency-section').show();

            var currency = $('#transaction_currency_select').val();
            var rate = parseFloat($('#exchange_rate_input').val()) || 1;
            if (rate <= 0) rate = 1;
            var originalAmount = (totalAmount / rate).toFixed(2);

            // 更新显示字段
            $('#original_amount_display').val(originalAmount);
            $('#amount_mmk_display').val(totalAmount.toFixed(2));
            $('#original_amount_currency_suffix').text(currency);

            // 更新 hidden 字段
            $('#hidden_transaction_currency').val(currency);
            $('#hidden_original_amount').val(originalAmount);
            $('#hidden_exchange_rate_snapshot').val(rate);
            $('#hidden_amount_mmk').val(totalAmount.toFixed(2));
        }

        // 币种切换
        $('#transaction_currency_select').on('change', function () {
            var currency = $(this).val();
            var rateInput = $('#exchange_rate_input');

            if (currency === 'MMK') {
                rateInput.val(1).prop('readonly', true);
            } else {
                rateInput.val(exchangeRates[currency] || 1).prop('readonly', false);
            }
            updateMultiCurrencyFields();
        });

        // 汇率输入
        $('#exchange_rate_input').on('input', function () {
            updateMultiCurrencyFields();
        });

        // Optional Fee 勾选逻辑（保留原有逻辑 + 增加多币种联动）
        $('.optional-fee-payment').on('click', function () {
            totalAmount += $(this).is(':checked') ? $(this).data("amount") : -$(this).data("amount");
            if (totalAmount > 0) {
                $('#pay-button').removeAttr('disabled');
                $('#optional-total-amount-to-pay').show().find('#optional-total-amount').html(formatAmountK(totalAmount));
                $('#optional-total-amount-to-pay').show().find('#form-total-optional-amount').val(totalAmount);
                updateMultiCurrencyFields();
            } else {
                $('#pay-button').attr('disabled', true);
                $('#optional-total-amount-to-pay').hide().find('#optional-total-amount').html(formatAmountK(totalAmount));
                $('#optional-total-amount-to-pay').hide().find('#form-total-optional-amount').val(totalAmount);
                $('#multi-currency-section').hide();
            }
        });

        function formSuccessFunction() {
            setTimeout(function () {
                window.location.reload();
            }, 1000);
        }
    </script>
@endsection
