<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Salary Slip || {{ $schoolSetting['school_name'] }}</title>
    <link rel="shortcut icon" href="{{$schoolSetting['favicon'] ?? url('assets/vertical-logo.svg') }}"/>

    <style>
        @font-face {
            font-family: 'NotoSansSC';
            src: url("{{ public_path('assets/fonts/NotoSansSC-Regular.ttf') }}") format('truetype');
            font-weight: normal;
            font-style: normal;
        }
        @font-face {
            font-family: 'NotoSansSC';
            src: url("{{ public_path('assets/fonts/NotoSansSC-Bold.ttf') }}") format('truetype');
            font-weight: bold;
            font-style: normal;
        }
        * {
            font-family: 'NotoSansSC', 'DejaVu Sans', sans-serif;
        }
        th, strong, .uppercase, .label, .table-footer {
            font-family: 'NotoSansSC', 'DejaVu Sans', sans-serif;
            font-weight: bold;
        }
        body {
            font-size: 12px;
            color: #222;
            line-height: 1.45;
        }
        .body {
            padding: 6px 10px;
        }
        .bilingual-title {
            font-size: 14px;
            font-weight: bold;
        }
        .money-text {
            white-space: nowrap;
            word-break: keep-all;
            letter-spacing: normal;
        }
        .en-small {
            color: #777;
            font-size: 10px;
            font-weight: normal;
        }
        .full-width {
            width: 100%;
        }

        .text-left {
            text-align: left;
        }

        .text-right {
            text-align: right;
        }

        .title-heading {
            background-color: rgb(191, 191, 191);
            padding: 5px 0px;
            border-radius: 8px;
        }

        .salary-title {
            font-size: 18px;
            padding: 10px 0px;
        }

        .salary-month-title {
            font-size: 12px;
            color: #666;
            font-weight: bold;
        }

        .salary-month {
            font-size: 18px;
            font-weight: bold;
        }

        .school-name {
            font-size: 19px;
            font-weight: bold;
        }

        .school-address {
            font-size: 12px;
            color: #666;
            padding: 0px 3px;
        }

        .end-header {
            border: 0;
            border-top: 1px solid #999;
            margin: 14px 0 22px 0;
        }

        .row {
            display: table;
            width: 100%;
            table-layout: fixed;
            border-collapse: separate;
            border-spacing: 10px 0;
        }

        .col-md-6 {
            position: static;
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }

        .employee-detail {
            width: 54%;
            border: 1px solid #D9D9D9;
            border-radius: 8px;
            background-color: #FAFAFA;
            padding: 8px 10px;
        }

        .net-salary {
            border: 1px solid #D9D9D9;
            border-radius: 8px;
            width: 42%;
        }

        .employee-detail table tr th,
        .employee-detail table tr td {
            padding: 5px 4px;
            vertical-align: top;
        }

        .label {
            color: #555;
            font-weight: bold;
            width: 105px;
            white-space: nowrap;
        }

        table {
            border-collapse: collapse;
            border: none;
        }

        .net-salary tr th,
        .net-salary tr td {
            padding: 5px 10px;
            vertical-align: middle;
        }

        .net-salary-amount {
            font-size: 20px;
            font-weight: bold;
            letter-spacing: normal;
            border-left: 3px solid #5FD068;
            padding-left: 10px;
            white-space: nowrap;
        }

        .net-salary-lable {
            font-size: 11px;
            color: #666;
            margin: 4px 0 0 0;
        }

        .net-salary-div {
            padding-bottom: 2px;
        }

        .net-salary-hr {
            border: 0;
            border-top: 1px solid #aaa;
            margin: 6px 0 0 0;
        }

        .net-amount-cell {
            background-color: #F3FBF5;
            border-radius: 8px 8px 0 0;
            padding: 8px 10px;
        }

        .salary-detail {
            margin-top: 16px;
            border: 1px solid #D9D9D9;
            border-radius: 8px;
        }

        .salary-detail table tr th,
        .salary-detail table tr td {
            padding: 8px 10px;
            vertical-align: middle;
        }

        .salary-detail table tr:first-child th {
            background-color: #F7F7F7;
            border-bottom: 1px solid #D9D9D9;
        }

        .uppercase {
            text-transform: none;
        }

        .salary-detail table tr .table-footer {
            background-color: #F0F0F0;
            font-weight: bold;
        }

        .total-payable {
            margin-top: 14px;
            border: 1px solid #D9D9D9;
            border-radius: 8px;
        }

        .total-payable table tr th {
            padding: 10px 12px;
        }

        .total-balance {
            font-size: 12px;
            color: gray;
            font-style: normal;
            margin-top: 0px;
        }

        .payable-amount {
            background-color: #F3FBF5;
            border-radius: 0 8px 8px 0;
            font-size: 16px;
            font-weight: bold;
            white-space: nowrap;
        }

        .paid-leaves {
            color: #777;
            font-size: 14px;
        }

        .school-name-address {
            padding-left: 20px;
        }

        .row-col {
            display: table;
            width: 100%;
            table-layout: fixed;
        }

        .col-6 {
            display: table-cell;
            width: 50%;
            box-sizing: border-box;
        }
        .item-col {
            width: 34%;
        }
        .amount-col {
            width: 16%;
            text-align: right;
            white-space: nowrap;
        }
    </style>
</head>

<body>
    <div class="body">
        {{-- Header --}}
        <table class="full-width">
            <tr>
                <th class="text-left" width="50">
                    
                    @if ($schoolSetting['horizontal_logo'] ?? '')
                        <img class="school-logo" height="50" src="{{ public_path('storage/') . $schoolSetting['horizontal_logo'] }}" alt="">                    
                    @else
                        <img height="40" src="{{ public_path('assets/horizontal-logo2.svg') }}" alt="">
                    @endif

                </th>
                <th class="text-left school-name-address">
                    <div class="school-name">
                        {{ $schoolSetting['school_name'] }}
                    </div>
                    <div class="school-address">
                        {{ $schoolSetting['school_address'] }}
                    </div>
                </th>
                <th class="text-right" width="140">
                    <div class="salary-month-title">
                        工资月份 <span class="en-small">/ Salary Month</span>
                    </div>
                    <div class="salary-month">
                        {{ $salary->title }}
                    </div>
                </th>
            </tr>
        </table>

        <hr class="end-header">

        @php
            $lwp = 0;
            $lwp_amount = 0;
            $allowance = 0;
            $deduction = 0;
        @endphp

        @if ($salary->paid_leaves < $total_leaves && $allow_leaves !== null)
            @php
                $lwp = number_format($total_leaves - $salary->paid_leaves, 2);
            @endphp
        @endif
        
        <div class="row">
            <div class="col-md-6 employee-detail">
                {{-- Employee info. --}}
                <table class="full-width">
                    <tr>
                        <th colspan="2" class="text-left bilingual-title">
                            员工信息 <span class="en-small">/ Employee Summary</span>
                        </th>
                    </tr>
                    <tr>
                        <th class="text-left label">
                            员工姓名 <span class="en-small">/ Name</span>
                        </th>
                        <td class="text-left">
                            : {{ $salary->staff->user->full_name }}
                        </td>
                    </tr>
                    <tr>
                        <th class="text-left label">
                            员工编号 <span class="en-small">/ ID</span>
                        </th>
                        <td class="text-left">
                            : {{ $salary->staff->id }}
                        </td>
                    </tr>
                    <tr>
                        <th class="text-left label">
                            发薪月份 <span class="en-small">/ Month</span>
                        </th>
                        <td class="text-left">
                            : {{ $salary->title }}
                        </td>
                    </tr>
                    <tr>
                        <th class="text-left label">
                            日期 <span class="en-small">/ Date</span>
                        </th>
                        <td class="text-left">
                            : {{ Str::before($salary->date, ' ') }}
                        </td>
                    </tr>

                </table>
            </div>

            <div class="col-md-6 net-salary">
                {{-- Salary --}}
                <table class="full-width net-salary-div">
                    <tr>
                        <th colspan="2" class="net-amount-cell">
                            <div class="net-salary-amount">
                                <span class="money-text">{{ format_money($salary->amount) }}</span>
                                <p class="net-salary-lable">实发工资 <span class="en-small">/ Net Salary</span></p>
                            </div>
                            <hr class="net-salary-hr">
                        </th>
                    </tr>
                    <tr>
                        <th class="label">
                            出勤天数 <span class="en-small">/ Paid Days</span>
                        </th>
                        <td>
                            : {{ $days - $lwp }}
                        </td>
                    </tr>
                    <tr>
                        <th class="label">
                            无薪假 <span class="en-small">/ LWP Days</span>
                        </th>
                        <td>
                            : {{ $lwp }}
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        {{-- Salary details --}}
        <div class="row salary-detail">
            <table class="full-width">
                <tr>
                    <th class="text-left item-col bilingual-title">
                        收入项目 <span class="en-small">/ Earnings</span>
                    </th>
                    <th class="amount-col bilingual-title">
                        金额 <span class="en-small">/ Amount</span>
                    </th>
                    <th class="text-left item-col bilingual-title">
                        扣款项目 <span class="en-small">/ Deductions</span>
                    </th>
                    <th class="amount-col bilingual-title">
                        金额 <span class="en-small">/ Amount</span>
                    </th>
                </tr>
                <tr>
                    <td class="text-left item-col">
                        基本工资 <span class="en-small">/ Basic Salary</span>
                    </td>
                    <td class="amount-col">
                        <span class="money-text">{{ format_money($salary->basic_salary) }}</span>
                    </td>
                    <td class="text-left item-col">
                        无薪假扣款 <span class="en-small">/ Leave Without Pay</span>
                        <br>
                        <span class="paid-leaves">带薪假 <span class="en-small">/ Paid Leaves</span> : {{ $salary->paid_leaves }}</span>
                    </td>
                    <td class="amount-col">
                        @if ($lwp)
                            @php
                                $lwp_amount = $days > 0 ? ($salary->basic_salary / $days) * $lwp : 0;
                            @endphp
                            <span class="money-text">{{ format_money($lwp_amount) }}</span>
                        @else
                            <span class="money-text">{{ format_money(0) }}</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td colspan="2">
                        @foreach ($salary->staff_payroll as $payroll)
                            @if ($payroll->payroll_setting->type == 'allowance')
                                <div class="row-col">
                                    <div class="col-6">
                                        <span class="text-left">
                                            {{ $payroll->payroll_setting->name }} {{ $payroll->percentage ? '('.$payroll->percentage.' %)' : '' }}
                                        </span>
                                    </div>
                                    <div class="col-6 text-right">
                                        <span class="text-right">
                                            @if ($payroll->amount)
                                                <span class="money-text">{{ format_money($payroll->amount) }}</span>
                                                @php
                                                    $allowance += $payroll->amount;
                                                @endphp
                                            @else
                                            @php
                                                $allowance += ($salary->basic_salary * $payroll->percentage) / 100;
                                            @endphp
                                                <span class="money-text">{{ format_money(($salary->basic_salary * $payroll->percentage) / 100) }}</span>
                                            @endif
                                            
                                        </span>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </td>

                    <td colspan="2">
                        @foreach ($salary->staff_payroll as $payroll)
                            @if ($payroll->payroll_setting->type == 'deduction')
                                <div class="row-col">
                                    <div class="col-6">
                                        <span class="text-left">
                                            {{ $payroll->payroll_setting->name }} {{ $payroll->percentage ? '('.$payroll->percentage.' %)' : '' }}
                                        </span>
                                    </div>
                                    <div class="col-6 text-right">
                                        <span class="text-right">
                                            @if ($payroll->amount)
                                                <span class="money-text">{{ format_money($payroll->amount) }}</span>
                                                @php
                                                    $deduction += $payroll->amount;
                                                @endphp
                                            @else
                                            @php
                                                $deduction += ($salary->basic_salary * $payroll->percentage) / 100;
                                            @endphp
                                                <span class="money-text">{{ format_money(($salary->basic_salary * $payroll->percentage) / 100) }}</span>
                                            @endif
                                            
                                        </span>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </td>
                </tr>
                <tr>
                    <td class="text-left">
                        其他补贴 <span class="en-small">/ Other Allowances</span>
                    </td>
                    <td class="text-right">
                        @if ($salary->amount > ($salary->basic_salary + $allowance - $lwp_amount - $deduction))
                            <span class="money-text">{{ format_money($salary->amount - ($salary->basic_salary + $allowance - $deduction - $lwp_amount)) }}</span>
                            @php
                                $allowance += $salary->amount - ($salary->basic_salary + $allowance - $deduction - $lwp_amount);
                            @endphp
                        @else
                            <span class="money-text">{{ format_money(0) }}</span>
                        @endif
                    </td>

                    <td class="text-left">
                        其他扣款 <span class="en-small">/ Other Deductions</span>
                    </td>
                    <td class="text-right">
                        @if ($salary->amount < ($salary->basic_salary + $allowance - $lwp_amount - $deduction))
                            <span class="money-text">{{ format_money(($salary->basic_salary + $allowance - $deduction - $lwp_amount) - $salary->amount) }}</span>
                            @php
                                $deduction += ($salary->basic_salary + $allowance - $deduction - $lwp_amount) - $salary->amount;
                            @endphp
                        @else
                            <span class="money-text">{{ format_money(0) }}</span>
                        @endif
                    </td>
                </tr>

                <tr>
                    <th class="text-left table-footer" style="border-bottom-left-radius: 8px">
                        应发合计 <span class="en-small">/ Gross Earnings</span>
                    </th>
                    <td class="text-right table-footer">
                        <span class="money-text">{{ format_money($salary->basic_salary + $allowance) }}</span>
                    </td>
                    <th class="text-left table-footer">
                        扣款合计 <span class="en-small">/ Total Deductions</span>
                    </th>
                    <td class="text-right table-footer" style="border-bottom-right-radius: 8px">
                        <span class="money-text">{{ format_money($lwp_amount + $deduction) }}</span>
                    </td>
                </tr>
            </table>
        </div>

        <div class="row total-payable">
            <table class="full-width">
                <tr>
                    <th class="text-left bilingual-title">
                        实发工资合计 <span class="en-small">/ Total Net Payable</span>
                        <span class="text-muted total-balance"><br>
                            应发合计 - 扣款合计 <span class="en-small">/ Gross Earnings - Total Deductions</span>
                        </span>
                    </th>
                    <th class="text-right payable-amount">
                        <span class="money-text">{{ format_money($salary->amount) }}</span>
                    </th>
                </tr>
            </table>
        </div>


    </div>
</body>

</html>
