@extends('layouts.master')

@section('title')
    {{ __('Student Ledger') }} - {{ $student->user->full_name ?? 'Student' }}
@endsection

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">{{ __('Student Ledger') }}</h3>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('student-ledger.index') }}">{{ __('Student Ledger') }}</a></li>
                    <li class="breadcrumb-item active" aria-current="page">{{ $student->user->full_name ?? 'Student' }}</li>
                </ol>
            </nav>
        </div>

        {{-- Student Info Card --}}
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 col-sm-6 mb-2">
                                <strong>{{ __('Student Name') }}:</strong>
                                {{ $student->user->full_name ?? 'N/A' }}
                            </div>
                            <div class="col-md-3 col-sm-6 mb-2">
                                <strong>{{ __('GR No.') }}:</strong>
                                {{ $student->admission_no }}
                            </div>
                            <div class="col-md-3 col-sm-6 mb-2">
                                <strong>{{ __('Class') }}:</strong>
                                {{ $student->class_section->class->name ?? 'N/A' }}
                                @if ($student->class_section->section->name ?? null)
                                    - {{ $student->class_section->section->name }}
                                @endif
                            </div>
                            <div class="col-md-3 col-sm-6 mb-2">
                                <strong>{{ __('Guardian') }}:</strong>
                                {{ $student->guardian->full_name ?? 'N/A' }}
                            </div>
                        </div>
                        <div class="mt-2">
                            <a href="{{ route('student-ledger.index') }}" class="btn btn-sm btn-secondary">
                                &larr; {{ __('Back to Search') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if (! $hasFeeStructure)
            <div class="row">
                <div class="col-md-12 grid-margin stretch-card">
                    <div class="card">
                        <div class="card-body text-center text-muted py-5">
                            <p>{{ __('No fee structure found for this student\'s class and current session year.') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        @else
            {{-- Summary Cards --}}
            <div class="row">
                <div class="col-md-4 col-sm-6 grid-margin stretch-card">
                    <div class="card">
                        <div class="card-body bg-primary text-white rounded">
                            <h3 class="mb-0">{{ number_format($totalCompulsoryExpected, 2) }}</h3>
                            <p class="mb-0">{{ __('Compulsory Expected') }} (MMK)</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6 grid-margin stretch-card">
                    <div class="card">
                        <div class="card-body bg-success text-white rounded">
                            <h3 class="mb-0">{{ number_format($totalCompulsoryPaid, 2) }}</h3>
                            <p class="mb-0">{{ __('Compulsory Paid') }} (MMK)</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6 grid-margin stretch-card">
                    <div class="card">
                        <div class="card-body @if($totalCompulsoryOutstanding > 0) bg-danger @else bg-success @endif text-white rounded">
                            <h3 class="mb-0">{{ number_format($totalCompulsoryOutstanding, 2) }}</h3>
                            <p class="mb-0">{{ __('Compulsory Outstanding') }} (MMK)</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6 grid-margin stretch-card">
                    <div class="card">
                        <div class="card-body bg-info text-white rounded">
                            <h3 class="mb-0">{{ number_format($totalOptionalPaid, 2) }}</h3>
                            <p class="mb-0">{{ __('Optional Paid') }} (MMK)</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6 grid-margin stretch-card">
                    <div class="card">
                        <div class="card-body bg-secondary text-white rounded">
                            <h3 class="mb-0">{{ number_format($totalPaid, 2) }}</h3>
                            <p class="mb-0">{{ __('Total Paid') }} (MMK)</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6 grid-margin stretch-card">
                    <div class="card">
                        <div class="card-body bg-dark text-white rounded">
                            <h3 class="mb-0">
                                {{ $lastPaymentDate ? date('d/m/Y', strtotime($lastPaymentDate)) : 'N/A' }}
                            </h3>
                            <p class="mb-0">{{ __('Last Payment') }}</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Compulsory Expected Table --}}
            <div class="row">
                <div class="col-md-12 grid-margin stretch-card">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="card-title">{{ __('Compulsory Fee Items') }} ({{ __('Expected') }})</h4>
                            @if ($compulsoryExpected->count() > 0)
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>{{ __('Fee Item') }}</th>
                                                <th>{{ __('Report Category') }}</th>
                                                <th>{{ __('Currency') }}</th>
                                                <th class="text-right">{{ __('Original Amount') }}</th>
                                                <th class="text-right">{{ __('Exchange Rate') }}</th>
                                                <th class="text-right">{{ __('Expected MMK') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($compulsoryExpected as $item)
                                                @php
                                                    $expectedMmk = ($item->fee_amount_mmk > 0) ? $item->fee_amount_mmk : $item->amount;
                                                @endphp
                                                <tr>
                                                    <td>{{ $item->fees_type->name ?? 'N/A' }}</td>
                                                    <td>{{ $item->finance_category->name ?? 'N/A' }}</td>
                                                    <td>{{ $item->fee_currency ?? 'MMK' }}</td>
                                                    <td class="text-right">{{ number_format($item->fee_original_amount ?? $expectedMmk, 2) }}</td>
                                                    <td class="text-right">{{ $item->fee_exchange_rate_snapshot ?? '1.0' }}</td>
                                                    <td class="text-right">{{ number_format($expectedMmk, 2) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot class="font-weight-bold">
                                            <tr>
                                                <td colspan="5" class="text-right">{{ __('Total Compulsory Expected') }}</td>
                                                <td class="text-right">{{ number_format($totalCompulsoryExpected, 2) }}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            @else
                                <p class="text-muted">{{ __('No compulsory fee items configured.') }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- Optional Paid Table --}}
            <div class="row">
                <div class="col-md-12 grid-margin stretch-card">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="card-title">{{ __('Optional Fee Payments') }}</h4>
                            @if ($optionalPaidRecords->count() > 0)
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>{{ __('Fee Item') }}</th>
                                                <th>{{ __('Report Category') }}</th>
                                                <th class="text-right">{{ __('Paid MMK') }}</th>
                                                <th>{{ __('Currency') }}</th>
                                                <th class="text-right">{{ __('Original Amount') }}</th>
                                                <th class="text-right">{{ __('Exchange Rate') }}</th>
                                                <th>{{ __('Method') }}</th>
                                                <th>{{ __('Date') }}</th>
                                                <th>{{ __('Status') }}</th>
                                                <th>{{ __('Receipt') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($optionalPaidRecords as $r)
                                                <tr>
                                                    <td>{{ $r->fees_class_type->fees_type->name ?? 'Optional Fee' }}</td>
                                                    <td>{{ $r->fees_class_type->finance_category->name ?? 'N/A' }}</td>
                                                    <td class="text-right">{{ number_format($r->amount, 2) }}</td>
                                                    <td>{{ $r->fees_paid->transaction_currency ?? 'MMK' }}</td>
                                                    <td class="text-right">{{ number_format($r->fees_paid->original_amount ?? $r->amount, 2) }}</td>
                                                    <td class="text-right">{{ $r->fees_paid->exchange_rate_snapshot ?? '1.0' }}</td>
                                                    <td>{{ $r->mode_name }}</td>
                                                    <td>{{ $r->date ? date('d/m/Y', strtotime($r->date)) : 'N/A' }}</td>
                                                    <td>
                                                        <span class="badge badge-success">{{ $r->status }}</span>
                                                    </td>
                                                    <td>
                                                        <a href="{{ route('fees.paid.receipt.pdf', $r->fees_paid_id) }}"
                                                            class="btn btn-sm btn-outline-primary" target="_blank">
                                                            {{ __('View') }}
                                                        </a>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <p class="text-muted">{{ __('No optional fee payments found.') }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- Payment History Table --}}
            <div class="row">
                <div class="col-md-12 grid-margin stretch-card">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="card-title">{{ __('Payment History') }}</h4>
                            @if ($paymentHistory->count() > 0)
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>{{ __('Date') }}</th>
                                                <th>{{ __('Type') }}</th>
                                                <th>{{ __('Fee Item') }}</th>
                                                <th>{{ __('Method') }}</th>
                                                <th>{{ __('Currency') }}</th>
                                                <th class="text-right">{{ __('Original Amount') }}</th>
                                                <th class="text-right">{{ __('Exchange Rate') }}</th>
                                                <th class="text-right">{{ __('MMK Amount') }}</th>
                                                @if ($paymentHistory->where('due_charges', '>', 0)->count() > 0)
                                                    <th class="text-right">{{ __('Late Fee') }}</th>
                                                @endif
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
                                                            <br><small class="text-muted">({{ $ph['installment'] }})</small>
                                                        @endif
                                                    </td>
                                                    <td>{{ $ph['mode_name'] }}</td>
                                                    <td>{{ $ph['currency'] }}</td>
                                                    <td class="text-right">{{ number_format($ph['original_amount'], 2) }}</td>
                                                    <td class="text-right">{{ $ph['exchange_rate'] }}</td>
                                                    <td class="text-right">{{ number_format($ph['mmk_amount'], 2) }}</td>
                                                    @if ($paymentHistory->where('due_charges', '>', 0)->count() > 0)
                                                        <td class="text-right">{{ $ph['due_charges'] > 0 ? number_format($ph['due_charges'], 2) : '-' }}</td>
                                                    @endif
                                                    <td>
                                                        <span class="badge badge-success">{{ $ph['status'] }}</span>
                                                    </td>
                                                    <td>
                                                        <a href="{{ route('fees.paid.receipt.pdf', $ph['fees_paid_id']) }}"
                                                            class="btn btn-sm btn-outline-primary" target="_blank">
                                                            {{ __('View') }}
                                                        </a>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <p class="text-muted">{{ __('No payment history found.') }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
