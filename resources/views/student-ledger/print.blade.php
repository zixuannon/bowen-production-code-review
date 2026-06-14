<!DOCTYPE html>
@php $lang = Session::get('language'); @endphp
<html lang="{{ $lang->code ?? 'en' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Student Ledger') }} - {{ $student->user->full_name ?? 'Student' }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 13px;
            color: #333;
            line-height: 1.5;
            padding: 30px 25px;
        }

        /* ── Print Button (hidden on print) ── */
        .print-toolbar {
            text-align: right;
            margin-bottom: 20px;
        }
        .print-toolbar .btn-print {
            display: inline-block;
            padding: 8px 20px;
            background: #007bff;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            border: none;
            cursor: pointer;
        }
        .print-toolbar .btn-print:hover {
            background: #0056b3;
        }

        /* ── Header ── */
        .header {
            text-align: center;
            margin-bottom: 25px;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
        }
        .header h1 {
            font-size: 20px;
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        .header h2 {
            font-size: 15px;
            font-weight: normal;
            color: #555;
        }
        .header .print-date {
            font-size: 11px;
            color: #888;
            margin-top: 5px;
        }

        /* ── Student Info Box ── */
        .info-box {
            background: #f9f9f9;
            border: 1px solid #ddd;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px 30px;
        }
        .info-box .info-item {
            flex: 1 1 200px;
            font-size: 13px;
        }
        .info-box .info-item strong {
            display: inline-block;
            min-width: 110px;
        }

        /* ── Summary Cards ── */
        .summary-row {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .summary-card {
            flex: 1 1 150px;
            border: 1px solid #ccc;
            padding: 10px 14px;
            text-align: center;
            border-radius: 3px;
        }
        .summary-card .amount {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 3px;
        }
        .summary-card .label {
            font-size: 11px;
            color: #666;
        }
        .summary-card.outstanding {
            border-left: 4px solid #dc3545;
        }

        /* ── Section Title ── */
        .section-title {
            font-size: 14px;
            font-weight: bold;
            margin: 20px 0 10px;
            border-bottom: 1px solid #999;
            padding-bottom: 4px;
        }

        /* ── Tables ── */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }
        table thead th {
            background: #eee;
            border: 1px solid #ccc;
            padding: 6px 8px;
            font-size: 12px;
            text-align: left;
        }
        table tbody td {
            border: 1px solid #ccc;
            padding: 5px 8px;
            font-size: 12px;
        }
        table .text-right { text-align: right; }
        table .text-center { text-align: center; }
        table tfoot td {
            font-weight: bold;
            background: #f5f5f5;
        }

        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-primary { background: #cce5ff; color: #004085; }
        .badge-info { background: #d1ecf1; color: #0c5460; }

        .note {
            font-size: 11px;
            color: #888;
            margin-top: 20px;
            font-style: italic;
            border-top: 1px solid #eee;
            padding-top: 10px;
        }

        /* ── Print Styles ── */
        @media print {
            body {
                padding: 10px 15px;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .print-toolbar { display: none; }
            .info-box { background: #fff; }
            table thead th { background: #eee !important; }
            .summary-card { border-color: #999; }
            @page {
                size: A4;
                margin: 10mm;
            }
        }
    </style>
</head>
<body>

    {{-- Print Button --}}
    <div class="print-toolbar">
        <button class="btn-print" onclick="window.print()">
            <i class="fa fa-print"></i> {{ __('Print') }}
        </button>
    </div>

    {{-- Header --}}
    <div class="header">
        <h1>{{ $school->name ?? config('app.name') }}</h1>
        <h2>{{ __('Student Ledger / Statement') }}</h2>
        <div class="print-date">{{ __('Printed') }}: {{ now()->format('d/m/Y h:i A') }}</div>
    </div>

    {{-- Student Information --}}
    <div class="info-box">
        <div class="info-item">
            <strong>{{ __('Student Name') }}:</strong>
            {{ $student->user->full_name ?? 'N/A' }}
        </div>
        <div class="info-item">
            <strong>{{ __('Admission No') }}:</strong>
            {{ $student->admission_no }}
        </div>
        <div class="info-item">
            <strong>{{ __('Class / Section') }}:</strong>
            {{ $student->class_section->class->name ?? 'N/A' }}
            @if ($student->class_section->section->name ?? null)
                - {{ $student->class_section->section->name }}
            @endif
        </div>
        <div class="info-item">
            <strong>{{ __('Session Year') }}:</strong>
            {{ $sessionYear->name ?? 'N/A' }}
        </div>
        <div class="info-item">
            <strong>{{ __('Contact') }}:</strong>
            {{ $student->guardian->mobile ?? $student->user->mobile ?? 'N/A' }}
        </div>
        <div class="info-item">
            <strong>{{ __('Guardian') }}:</strong>
            {{ $student->guardian->full_name ?? 'N/A' }}
        </div>
    </div>

    {{-- Summary --}}
    <div class="summary-row">
        <div class="summary-card">
            <div class="amount">{{ number_format($totalCompulsoryExpected, 2) }}</div>
            <div class="label">{{ __('Expected (MMK)') }}</div>
        </div>
        <div class="summary-card">
            <div class="amount">{{ number_format($totalCompulsoryPaid, 2) }}</div>
            <div class="label">{{ __('Compulsory Paid (MMK)') }}</div>
        </div>
        <div class="summary-card outstanding">
            <div class="amount">{{ number_format($totalCompulsoryOutstanding, 2) }}</div>
            <div class="label">{{ __('Outstanding (MMK)') }}</div>
        </div>
        <div class="summary-card">
            <div class="amount">{{ number_format($totalOptionalPaid, 2) }}</div>
            <div class="label">{{ __('Optional Paid (MMK)') }}</div>
        </div>
        <div class="summary-card">
            <div class="amount">{{ $lastPaymentDate ? date('d/m/Y', strtotime($lastPaymentDate)) : 'N/A' }}</div>
            <div class="label">{{ __('Last Payment') }}</div>
        </div>
    </div>

    @if (!$hasFeeStructure)
        <p style="text-align:center; color:#888; padding:30px;">
            {{ __('No fee structure found for this student\'s class and current session year.') }}
        </p>
    @else

        {{-- Compulsory Fees --}}
        <div class="section-title">{{ __('Compulsory Fee Items') }}</div>
        @if ($compulsoryExpected->count() > 0)
            <table>
                <thead>
                    <tr>
                        <th>{{ __('Fee Item') }}</th>
                        <th>{{ __('Category') }}</th>
                        <th>{{ __('Currency') }}</th>
                        <th class="text-right">{{ __('Original Amount') }}</th>
                        <th class="text-right">{{ __('Exch. Rate') }}</th>
                        <th class="text-right">{{ __('MMK Amount') }}</th>
                        <th class="text-center">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($compulsoryExpected as $item)
                        @php $expectedMmk = ($item->fee_amount_mmk > 0) ? $item->fee_amount_mmk : $item->amount; @endphp
                        <tr>
                            <td>{{ $item->fees_type->name ?? 'N/A' }}</td>
                            <td>{{ $item->finance_category->name ?? '-' }}</td>
                            <td>{{ $item->fee_currency ?? 'MMK' }}</td>
                            <td class="text-right">{{ number_format($item->fee_original_amount ?? $expectedMmk, 2) }}</td>
                            <td class="text-right">{{ $item->fee_exchange_rate_snapshot ?? '1.0' }}</td>
                            <td class="text-right">{{ number_format($expectedMmk, 2) }}</td>
                            <td class="text-center"><span class="badge badge-primary">{{ __('Expected') }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" class="text-right">{{ __('Total Compulsory Expected') }}</td>
                        <td class="text-right">{{ number_format($totalCompulsoryExpected, 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        @else
            <p style="color:#888; font-style:italic;">{{ __('No compulsory fee items configured.') }}</p>
        @endif

        {{-- Optional Paid --}}
        <div class="section-title">{{ __('Optional Fee Payments') }}</div>
        @if ($optionalPaidRecords->count() > 0)
            <table>
                <thead>
                    <tr>
                        <th>{{ __('Fee Item') }}</th>
                        <th>{{ __('Category') }}</th>
                        <th class="text-right">{{ __('Paid MMK') }}</th>
                        <th>{{ __('Currency') }}</th>
                        <th class="text-right">{{ __('Original Amount') }}</th>
                        <th class="text-right">{{ __('Exch. Rate') }}</th>
                        <th>{{ __('Method') }}</th>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($optionalPaidRecords as $r)
                        <tr>
                            <td>{{ $r->fees_class_type->fees_type->name ?? 'Optional Fee' }}</td>
                            <td>{{ $r->fees_class_type->finance_category->name ?? '-' }}</td>
                            <td class="text-right">{{ number_format($r->amount, 2) }}</td>
                            <td>{{ $r->fees_paid->transaction_currency ?? 'MMK' }}</td>
                            <td class="text-right">{{ number_format($r->fees_paid->original_amount ?? $r->amount, 2) }}</td>
                            <td class="text-right">{{ $r->fees_paid->exchange_rate_snapshot ?? '1.0' }}</td>
                            <td>{{ $r->mode_name }}</td>
                            <td>{{ $r->date ? date('d/m/Y', strtotime($r->date)) : 'N/A' }}</td>
                            <td><span class="badge badge-success">{{ $r->status }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p style="color:#888; font-style:italic;">{{ __('No optional fee payments found.') }}</p>
        @endif

        {{-- Payment History --}}
        <div class="section-title">{{ __('Payment History') }}</div>
        @if ($paymentHistory->count() > 0)
            <table>
                <thead>
                    <tr>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Type') }}</th>
                        <th>{{ __('Fee Item') }}</th>
                        <th>{{ __('Method') }}</th>
                        <th>{{ __('Currency') }}</th>
                        <th class="text-right">{{ __('Original') }}</th>
                        <th class="text-right">{{ __('Rate') }}</th>
                        <th class="text-right">{{ __('MMK') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Receipt') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($paymentHistory as $ph)
                        <tr>
                            <td>{{ $ph['date'] ? date('d/m/Y', strtotime($ph['date'])) : 'N/A' }}</td>
                            <td>
                                @if ($ph['type'] === 'Compulsory')
                                    <span class="badge badge-primary">{{ __('Compulsory') }}</span>
                                @else
                                    <span class="badge badge-info">{{ __('Optional') }}</span>
                                @endif
                            </td>
                            <td>
                                {{ $ph['fee_item'] }}
                                @if ($ph['installment'])
                                    <br><small>({{ $ph['installment'] }})</small>
                                @endif
                            </td>
                            <td>{{ $ph['mode_name'] }}</td>
                            <td>{{ $ph['currency'] }}</td>
                            <td class="text-right">{{ number_format($ph['original_amount'], 2) }}</td>
                            <td class="text-right">{{ $ph['exchange_rate'] }}</td>
                            <td class="text-right">{{ number_format($ph['mmk_amount'], 2) }}</td>
                            <td><span class="badge badge-success">{{ $ph['status'] }}</span></td>
                            <td>#{{ $ph['fees_paid_id'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p style="color:#888; font-style:italic;">{{ __('No payment history found.') }}</p>
        @endif

    @endif

    {{-- Note --}}
    <div class="note">
        {{ __('Note') }}: {{ __('Optional fees are shown for reference only and are not deducted from outstanding compulsory fees.') }}
    </div>

</body>
</html>
