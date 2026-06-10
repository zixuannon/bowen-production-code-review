@extends('layouts.master')

@section('title')
    {{ __('Pay Compulsory Fees') }}
@endsection

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">
                {{ __('Pay Compulsory Fees') }}
            </h3>
        </div>

        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-header d-flex justify-content-end align-items-center">
                        <a href="{{ route('fees.paid.index') }}" class="btn btn-theme float-right">Back</a>
                    </div>
                    <div class="card-body d-flex justify-content-center">
                        <form class="pt-3 create-form form-validation col-sm-12 col-md-8" method="post" action="{{ route('fees.compulsory.store') }}" data-success-function="successFunction" novalidate="novalidate">
                            <input type="hidden" name="fees_id" id="compulsory-fees-id" value="{{$fees->id}}"/>
                            <input type="hidden" name="student_id" id="student-id" value="{{$student->id}}"/>
                            <input type="hidden" name="parent_id" id="parent-id" value="{{$student->student->guardian_id}}"/>
                            <input type="hidden" name="installment_mode" id="installment-mode" value="0"/>
                            <input type="hidden" name="total_amount" id="total-amount" value="{{$fees->total_compulsory_fees}}"/>
                            <input type="hidden" id="total_compulsory_fees" name="total_compulsory_fees" value="{{$fees->total_compulsory_fees}}">
                            <input type="hidden" id="remaining_amount" value="{{$fees->remaining_amount}}">
                            <input type="hidden" id="total_installment_amount" value="0">
                            <input type="hidden" name="due_charges_amount" value="{{ $due_charges ?? 0 }}">
                            <h4>{{$student->full_name.':-'.$student->student->class_section->full_name}}</h4><br>
                            @php
                                $total_compulsory_fees = $fees->total_compulsory_fees;
                            @endphp
                            <div class="form-group">
                                <label for="payment-date">{{ __('date') }} <span class="text-danger">*</span></label>
                                <input id="payment-date" type="text" name="date" class="datepicker-popup paid-date form-control" placeholder="{{ __('date') }}" autocomplete="off" required>
                            </div>

                            <div class="form-group">
                                <label for="transaction-currency">{{ __('Currency') }} / {{ __('货币') }}</label>
                                <select id="transaction-currency" name="transaction_currency" class="form-control">
                                    <option value="MMK" selected>MMK</option>
                                    <option value="USD">USD</option>
                                    <option value="CNY">CNY</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="original-amount">{{ __('Original Amount') }} / {{ __('原币金额') }}</label>
                                <input type="number" id="original-amount" name="original_amount" class="form-control" step="0.01" min="0" placeholder="{{ __('Original Amount') }}">
                            </div>

                            <div class="form-group">
                                <label for="exchange-rate">{{ __('Exchange Rate') }} / {{ __('汇率') }}</label>
                                <input type="number" id="exchange-rate" name="exchange_rate_snapshot" class="form-control" step="0.0001" min="0" value="1" readonly>
                            </div>

                            <hr>
                            <div class="form-group col-sm-12 col-md-12">
                                <div class="compulsory-fees-content">
                                    <table class="table">
                                        <tbody>
                                        @foreach($fees->compulsory_fees as $compulsoryFee)
                                            <tr>
                                                <td class="text-left"></td>
                                                <td colspan="2" class="text-left">
                                                    <label>{{$compulsoryFee->fees_type_name}}</label>
                                                </td>
                                                <td class="text-right">{{ format_money($compulsoryFee->amount) }}</td>
                                            </tr>
                                        @endforeach
                                        @if(count($fees->installments))
                                            <tr class="pay-in-installment-row">
                                                <td class="text-left"></td>
                                                <td colspan="2" class="text-left">
                                                    <label for="pay-in-installment-chk">{{__("Pay in installment")}}</label>
                                                </td>
                                                <td class="text-right">
                                                    <input type="checkbox" id="pay-in-installment-chk" class="form-check-input pay-in-installment">
                                                </td>
                                            </tr>
                                        @endif

                                        @foreach($fees->installments as $key=>$installment)
                                            <tr class="installment_rows" style="display: none;">
                                                @if(!empty($installment->is_paid))
                                                    {{--If installment is paid--}}
                                                    <td>
                                                        <span class="remove-installment-fees-paid text-left" data-id="{{$installment->is_paid->id}}">
                                                            <i class="fa fa-times text-danger" style="cursor:pointer" aria-hidden="true"></i>
                                                        </span>
                                                    </td>

                                                    <td colspan="2" class="text-left">
                                                        <label>{{$installment->name}}<br>
                                                            <small class="text-success">{{__("paid_on")}} : {{$installment->is_paid->date}}</small>
                                                            @if(!empty($installment->is_paid->advance_fees))
                                                                <br>
                                                                @foreach($installment->is_paid->advance_fees as $advance)
                                                                    <br>{{__("Advance")}} ({{date('Y-m-d',strtotime($advance['created_at']))}})
                                                                @endforeach
                                                            @endif
                                                        </label>
                                                    </td>

                                                    <td class="text-right">
                                                        @php
                                                            $advance_payment = 0;
                                                            if (!empty($installment->is_paid->advance_fees)) {
                                                                foreach($installment->is_paid->advance_fees as $advance)
                                                                {
                                                                    $advance_payment += $advance['amount'];
                                                                }
                                                            }
                                                        @endphp
                                                        <label>{{ format_money($installment->is_paid->amount - $advance_payment) }}
                                                            <br><small>{{ format_money($installment->is_paid->due_charges) }}</small><br>

                                                            @if(!empty($installment->is_paid->advance_fees))
                                                                @foreach($installment->is_paid->advance_fees as $advance)
                                                                    <br>{{ format_money($advance['amount']) }}
                                                                @endforeach
                                                            @endif
                                                            <hr>
                                                            <br>{{ format_money($installment->is_paid->amount + $installment->is_paid->due_charges) }}


                                                        </label>

                                                    </td>
                                                @else
                                                    {{--If Installment is not Paid--}}
                                                    <td>
                                                        <input type="checkbox" id="installment-fees-{{$key}}" name="installment_fees[{{$key}}][id]" class="installment-checkbox {{($installment->due_charges_amount > 0) ? 'default-checked-installment' : ''}}" value="{{$installment->id}}" data-amount="{{$installment->total_amount}}" aria-label=""
                                                            {{--                                                            {!! ($installment->due_charges_amount > 0) ? "checked  onclick=\"return false\"" : ''!!}--}}
                                                        />
                                                        <input type="hidden" name="installment_fees[{{$key}}][due_charges]" class="due-charges-amount" value="{{$installment->due_charges_amount}}" disabled/>
                                                        <input type="hidden" name="installment_fees[{{$key}}][amount]" class="installment-amount" value="{{$installment->minimum_amount}}" disabled/>
                                                    </td>

                                                    <td colspan="2" class="text-left">
                                                        <label for="installment-fees-{{$key}}">{{$installment->name}}<br>
                                                            <small class="{{$installment->due_charges_amount > 0 ? "text-danger" : "text-success"}}">{{__("Due date on").' '.$installment->due_date}}</small>
                                                            <br><small class="{{$installment->due_charges_amount > 0 ? "text-danger" : "text-success"}}">
                                                                {{__("Charges").' '.$installment->due_charges}}
                                                                {{$installment->due_charges_type=="percentage" ? "%" : $currencySymbol}}
                                                            </small>
                                                        </label>
                                                    </td>

                                                    <td class="text-right">
                                                        {{--<input type="number" value="{{round($installment->minimum_amount,2)}}" min="{{round($installment->minimum_amount,2)}}" max="{{round($installment->maximum_amount,2)}}" class="ml-auto form-control col-5 text-right custom-installment-amount" aria-label="" disabled {{($installment->due_charges_amount > 0) ? "readonly" : ''}}/><br>--}}

                                                        <span class="final-installment-amount">{{ format_money($installment->minimum_amount) }}</span>
                                                        <br>
                                                        <label>
                                                            <small>{{ format_money($installment->due_charges_amount) }}</small><br>
                                                            <hr>
                                                            <span class="final-installment-amount">{{ format_money($installment->total_amount) }}</span>
                                                        </label>
                                                    </td>
                                                @endif
                                            </tr>
                                        @endforeach
                                        <tr class="installment_rows" style="display: none;">
                                            <td class="text-left"></td>
                                            <td colspan="2" class="text-left"><label>{{__("enter_amount")}}</label></td>
                                            <td class="justify-content-end row">
                                                <input type="number" id="advance" name="advance" aria-label="" class="form-control enter_amount col-6 text-right " min="0" max="{{$fees->remaining_amount}}" value="{{$fees->remaining_amount}}" {{!$oneInstallmentPaid ? "disabled" : ""}} placeholder="{{ __('enter_amount') }}"/>
                                            </td>
                                        </tr>

                                        @if ($due_charges && !count($fees->installments))
                                            <tr>
                                                <td class="text-left"></td>
                                                <th colspan="2">
                                                    {{ __('due_charges') }} 
                                                    <span class="text-small text-danger">({{ $fees->due_date }})</span>
                                                </th>
                                                <td class="text-right">{{ format_money($due_charges) }}</td>
                                            </tr>
                                        @endif

                                        <tr>
                                            <td class="text-left"></td>
                                            <th colspan="2" class="text-left"><label>{{__("Total Amount")}}</label></th>
                                            <th class="text-right"><span id="total_amount_text">{{ format_money($fees->total_compulsory_fees + $due_charges) }}</span></th>
                                        </tr>

                                        @if ($student->fees_paid)
                                            <tr class="without_installment_enter_amount">
                                                <th colspan="4">{{ __('fees_paid_records') }}</th>
                                            </tr>
                                            <tr class="without_installment_enter_amount">
                                                <th>{{ __('date') }}</th>
                                                <th colspan="2">{{ __('cheque_no') }}</th>
                                                <th class="text-right">{{ __('Amount') }}</th>
                                            </tr>
                                            @foreach ($student->fees_paid->compulsory_fee as $fees)
                                                <tr class="without_installment_enter_amount">
                                                    <td>
                                                        <span class="remove-installment-fees-paid text-left" title="{{ __('delete') }}" data-id="{{ $fees->id }}">
                                                            <i class="fa fa-times text-danger" style="cursor:pointer" aria-hidden="true"></i>
                                                        </span>

                                                        {{ $fees->date }}
                                                    </td>
                                                    <td colspan="2">
                                                        {{ $fees->cheque_no }}
                                                    </td>
                                                    <td class="text-right">
                                                        {{ format_money($fees->amount) }}
                                                    </td>
                                                </tr>
                                            @endforeach
                                        @endif
                                        
                                        @if (!$isFullyPaid && $installment_status == 0)
                                        <tr class="without_installment_enter_amount">
                                            <td class="text-left"></td>
                                            <th colspan="2" class="text-left"><label>{{__("enter_amount")}} <span class="text-danger">*</span></label></th>
                                            <td class="text-right"><span id="total_amount_text">
                                                @if ($student->fees_paid)
                                                    <div class="input-group">
                                                        <div class="input-group-prepend">
                                                            <span class="input-group-text">{{ $currencySymbol }}</span>
                                                        </div>
                                                        <input type="number" name="enter_amount" min="1" class="form-control" max="{{ ($total_compulsory_fees - $student->fees_paid->compulsory_fee_sum_amount + $due_charges) }}" id="enter_amount" value="{{ ($total_compulsory_fees - $student->fees_paid->compulsory_fee_sum_amount + $due_charges) }}" placeholder="{{ __('enter_amount') }}">
                                                    </div>
                                                @else
                                                    <div class="input-group">
                                                        <div class="input-group-prepend">
                                                            <span class="input-group-text">{{ $currencySymbol }}</span>
                                                        </div>
                                                        <input type="number" name="enter_amount" min="1" class="form-control" max="{{ $total_compulsory_fees + $due_charges }}" id="enter_amount" value="{{ $total_compulsory_fees + $due_charges }}" placeholder="{{ __('enter_amount') }}">
                                                    </div>
                                                @endif
                                                
                                            </td>
                                        </tr>
                                        @endif
                                        

                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <hr>
                            @if (!$isFullyPaid)
                                <div class="row mode-container">
                                    <div class="form-group col-sm-12 col-md-12">
                                        <label>{{ __('Mode') }} <span class="text-danger">*</span></label><br>
                                        <div class="d-flex flex-wrap">
                                            <div class="form-check form-check-inline">
                                                <label class="form-check-label">
                                                    <input type="radio" name="mode" class="cash-compulsory-mode mode" value="1" checked>
                                                    {{ __('cash') }}
                                                </label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <label class="form-check-label">
                                                    <input type="radio" name="mode" class="mode" value="2">
                                                    KBZ Pay
                                                </label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <label class="form-check-label">
                                                    <input type="radio" name="mode" class="mode" value="3">
                                                    Quick Pay
                                                </label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <label class="form-check-label">
                                                    <input type="radio" name="mode" class="mode" value="4">
                                                    KBZ Bank
                                                </label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <label class="form-check-label">
                                                    <input type="radio" name="mode" class="mode" value="5">
                                                    AYA Bank
                                                </label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <label class="form-check-label">
                                                    <input type="radio" name="mode" class="mode" value="6">
                                                    YOMA BANK
                                                </label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <label class="form-check-label">
                                                    <input type="radio" name="mode" class="mode" value="7">
                                                    CB Bank
                                                </label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <label class="form-check-label">
                                                    <input type="radio" name="mode" class="mode" value="8">
                                                    Wechat Pay
                                                </label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <label class="form-check-label">
                                                    <input type="radio" name="mode" class="mode" value="9">
                                                    Ali Pay
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="alert alert-info mt-3">
                                    <i class="fa fa-info-circle"></i> {{ __('Payment is saved as MMK equivalent. Original currency and exchange rate are also saved.') }}
                                </div>
                                <input class="btn btn-theme float-right" type="submit" value={{ __('pay') }} />
                            @endif
                            
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

        @if($fees->include_fee_installments)
        setTimeout(() => {
            $('#advance').trigger('change');
        }, 1000);
        @endif
        

        // @if($student->fees_paid && $student->fees_paid->is_used_installment)
        // $('.pay-in-installment').trigger('click').attr("disabled", true);
        // @endif

        @if($student->fees_paid)
        $('.pay-in-installment').trigger('click').attr("disabled", true);
        @endif

        // === Multi-Currency Logic ===
        @php
            $usdDefaultRate = getDefaultExchangeRate('USD');
            $cnyDefaultRate = getDefaultExchangeRate('CNY');
        @endphp

        (function() {
            var $currency = $('#transaction-currency');
            var $originalAmount = $('#original-amount');
            var $exchangeRate = $('#exchange-rate');
            var $enterAmount = $('#enter_amount');
            var $totalAmount = $('#total-amount');

            var defaultUsdRate = {{ $usdDefaultRate }};
            var defaultCnyRate = {{ $cnyDefaultRate }};

            // Init: copy enter_amount (MMK) to original_amount
            function initMultiCurrency() {
                if (!$enterAmount.length) return;
                var val = parseFloat($enterAmount.val()) || 0;
                if (val > 0 && ($originalAmount.val() === '' || $originalAmount.val() === '0')) {
                    $originalAmount.val(val.toFixed(2));
                }
            }

            // Calculate MMK equivalent: original_amount * exchange_rate
            function calcMMKFromOriginal() {
                if ($currency.val() === 'MMK') return;
                var original = parseFloat($originalAmount.val()) || 0;
                var rate = parseFloat($exchangeRate.val()) || 0;
                var mmk = (original * rate).toFixed(2);
                if ($enterAmount.length) $enterAmount.val(mmk);
                if ($totalAmount.length) $totalAmount.val(mmk);
            }

            // In MMK mode: sync original_amount = enter_amount
            function syncOriginalFromEnter() {
                if ($currency.val() !== 'MMK') return;
                if (!$enterAmount.length) return;
                var val = parseFloat($enterAmount.val()) || 0;
                $originalAmount.val(val.toFixed(2));
            }

            // Currency switch
            $currency.on('change', function() {
                var cur = $(this).val();
                if (cur === 'MMK') {
                    $exchangeRate.val('1').prop('readonly', true);
                    syncOriginalFromEnter();
                } else {
                    $exchangeRate.prop('readonly', false);
                    if (cur === 'USD') $exchangeRate.val(defaultUsdRate);
                    if (cur === 'CNY') $exchangeRate.val(defaultCnyRate);
                    calcMMKFromOriginal();
                }
            });

            // original_amount change -> recalc enter_amount
            $originalAmount.on('input', function() {
                calcMMKFromOriginal();
            });

            // exchange_rate change -> recalc enter_amount
            $exchangeRate.on('input', function() {
                calcMMKFromOriginal();
            });

            // enter_amount change (user edits MMK directly) -> sync original
            $enterAmount.on('input', function() {
                syncOriginalFromEnter();
            });

            initMultiCurrency();
        })();
        // === End Multi-Currency Logic ===

        function successFunction() {
            window.location.href = "{{route('fees.paid.index')}}";
        }
    </script>
@endsection
