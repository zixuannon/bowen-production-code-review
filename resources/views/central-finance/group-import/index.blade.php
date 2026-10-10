@extends('layouts.master')
@section('title', __('Group Finance Import V3'))
@section('content')
@php($summary = $batch?->school_summary ?? [])
@php($previewReady = $batch && in_array($batch->status, ['previewed', 'completed'], true))
@php($confirmReady = $batch && ($batch->production_eligible ?? true) && $batch->status === 'previewed' && $batch->error_rows === 0 && $batch->conflict_rows === 0)
@php($unidentifiedTotals = $summary['unidentified_totals'] ?? [])
@php($unidentifiedCount = collect($unidentifiedTotals)->sum('count'))
<div class="central-finance-page">
    @include('central-finance.partials.foundation-styles')

    <div class="cf-page-header mb-3">
        <div>
            <p class="cf-page-header__eyebrow">{{ __('Group Finance') }}</p>
            <h1 class="cf-page-header__title">{{ __('Group Finance Import V3') }}</h1>
            <p class="cf-page-header__description">{{ __('Validate a multi-School workbook before confirmation. Preview never creates financial documents.') }}</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('central-finance.group-import.history', request()->only('include_qa_test')) }}">{{ __('Import history') }}</a>
    </div>

    @include('central-finance.partials.data-visibility-toggle')

    <ol class="ui-stepper" aria-label="{{ __('Import progress') }}">
        <li class="ui-stepper__item is-complete"><span class="ui-stepper__number">1</span>{{ __('Download template') }}</li>
        <li class="ui-stepper__item {{ $batch ? 'is-complete' : 'is-active' }}"><span class="ui-stepper__number">2</span>{{ __('Upload workbook') }}</li>
        <li class="ui-stepper__item {{ $previewReady ? 'is-complete' : ($batch ? 'is-active' : '') }}"><span class="ui-stepper__number">3</span>{{ __('Preview and validate') }}</li>
        <li class="ui-stepper__item {{ $batch?->status === 'completed' ? 'is-complete' : ($confirmReady ? 'is-active' : '') }}"><span class="ui-stepper__number">4</span>{{ __('Confirm import') }}</li>
    </ol>

    <div class="row mb-3">
        <div class="col-lg-4 mb-3 mb-lg-0">
            <section class="card h-100" aria-labelledby="group-import-download-title">
                <div class="card-body d-flex flex-column">
                    <span class="badge badge-light align-self-start mb-2">{{ __('Step 1') }}</span>
                    <h2 class="h5" id="group-import-download-title">{{ __('Download template') }}</h2>
                    <p class="text-muted flex-grow-1">{{ __('Start from Template V3. Transaction Date is the actual business date, must use YYYY-MM-DD, and determines financial period reporting. Choose Campus, allocated Account Code and Fund Account Code; names and Account Type fill automatically. Select Unidentified / 待识别 only for a bank credit whose School is not known; it enters the Unidentified Deposit pool, not School income. Use Manual Transaction Identity and Reason only when the bank supplies no reference.') }}</p>
                    <a id="group-import-template-link" class="btn btn-outline-primary" data-template-url="{{ route('central-finance.group-import.template') }}" href="{{ route('central-finance.group-import.template', ['finance_group_id' => $groups->first()->id]) }}">{{ __('group-import.download_template_v3') }}</a>
                </div>
            </section>
        </div>
        <div class="col-lg-8">
            <section class="card h-100" aria-labelledby="group-import-upload-title">
                <div class="card-body">
                    <span class="badge badge-light mb-2">{{ __('Step 2') }}</span>
                    <h2 class="h5" id="group-import-upload-title">{{ __('Upload workbook') }}</h2>
                    <form method="POST" enctype="multipart/form-data" action="{{ route('central-finance.group-import.preview') }}">
                        @csrf
                        <div class="form-group">
                            <label for="group-import-finance-group">{{ __('Finance Group') }}</label>
                            <select id="group-import-finance-group" name="finance_group_id" class="form-control" required>
                                @foreach($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="ui-upload-zone mb-3">
                            <label for="group-import-file" class="ui-upload-zone__title">{{ __('Group Import File') }}</label>
                            <span class="ui-upload-zone__help">{{ __('Accepted files: XLSX, XLS, or CSV. Upload starts validation only and does not post transactions.') }}</span>
                            <input id="group-import-file" class="form-control-file mt-3" type="file" name="group_import" accept=".xlsx,.xls,.csv" required data-ui-file-input>
                            <span class="ui-upload-zone__file" data-ui-file-name data-empty-label="{{ __('No file selected') }}">{{ __('No file selected') }}</span>
                        </div>
                        <button class="btn btn-theme">{{ __('Preview and validate') }}</button>
                    </form>
                </div>
            </section>
        </div>
    </div>

    @if($batch)
        <div class="card">
            <div class="card-body">
                <div class="cf-workspace-toolbar">
                    <div>
                        <span class="badge badge-light mb-2">{{ __('Step 3') }}</span>
                        <h2 class="h5 mb-1">{{ $batch->status === 'completed' ? __('Completed Group Import') : __('Preview result') }}</h2>
                        <p class="text-muted mb-0 cf-break-anywhere">{{ $batch->file_name }} · {{ __('Total rows') }}: {{ $batch->total_rows }}</p>
                    </div>
                    <span class="badge cf-status-badge badge-{{ $batch->status === 'completed' ? 'success' : 'info' }}">{{ __($batch->status) }}</span>
                </div>

                <div class="ui-import-summary" aria-label="{{ __('Validation summary') }}">
                    <div class="ui-import-summary__item border-success"><span>{{ __('Valid') }}</span><strong>{{ max(0, (int) $batch->new_rows - $unidentifiedCount) }}</strong></div>
                    @if($unidentifiedCount > 0)<div class="ui-import-summary__item border-info"><span>{{ __('Unidentified Deposit') }}</span><strong>{{ $unidentifiedCount }}</strong></div>@endif
                    @foreach(['duplicate' => ['Duplicate', 'secondary'], 'error' => ['Error', 'danger'], 'conflict' => ['Conflict', 'warning']] as $key => [$label, $style])
                        <div class="ui-import-summary__item border-{{ $style }}">
                            <span>{{ __($label) }}</span>
                            <strong>{{ $batch->{$key.'_rows'} }}</strong>
                        </div>
                    @endforeach
                </div>

                    @if(!empty($summary['currency_totals']) || !empty($unidentifiedTotals))
                    <div class="mb-3">
                        <h3 class="h6">{{ __('Eligible amounts by currency') }}</h3>
                        <div class="row">@foreach($summary['currency_totals'] ?? [] as $currency => $totals)<div class="col-md-4 mb-2"><div class="cf-summary-card"><span class="cf-summary-card__label">{{ $currency }}</span>@foreach($totals as $type => $total)<span class="d-block"><strong>{{ $type === 'expense' ? __('Expense') : ($type === 'unidentified_deposit' ? __('Unidentified Deposit') : __('Income')) }}</strong> · {{ $total['count'] }} · {{ number_format($total['amount'], 2) }} {{ $currency }}</span>@endforeach</div></div>@endforeach @foreach($unidentifiedTotals as $currency => $total)<div class="col-md-4 mb-2"><div class="cf-summary-card"><span class="cf-summary-card__label">{{ $currency }} · {{ __('Unidentified Deposit') }}</span><span class="d-block">{{ $total['count'] }} · {{ number_format($total['amount'], 2) }} {{ $currency }} · {{ __('Not School income') }}</span></div></div>@endforeach</div>
                    </div>
                @endif

                <div class="mb-3">
                    <h3 class="h6">{{ __('School summary') }}</h3>
                    <div class="row">@forelse($summary['schools'] ?? [] as $schoolId => $school)<div class="col-lg-6 mb-2"><div class="cf-summary-card"><span class="cf-primary-line">{{ $school['label'] ?: $school['code'] }}</span><span class="cf-secondary-line">{{ $school['code'] }}</span><span class="small d-block">{{ __('Valid') }} {{ $school['new'] }} · {{ __('Duplicate') }} {{ $school['duplicate'] }} · {{ __('Conflict') }} {{ $school['conflict'] }} · {{ __('Error') }} {{ $school['error'] }}</span>@foreach($school['currency_totals'] ?? [] as $currency => $totals)<span class="small d-block mt-1"><strong>{{ $currency }}</strong> @foreach($totals as $type => $total) · {{ $type === 'expense' ? __('Expense') : __('Income') }} {{ number_format($total['amount'],2) }} @endforeach</span>@endforeach</div></div>@empty<div class="col-12"><div class="cf-empty-state">{{ __('No routed School summary is available.') }}</div></div>@endforelse</div>
                </div>

                <p class="ui-table-scroll-hint"><i class="fa fa-arrows-h" aria-hidden="true"></i> {{ __('Swipe horizontally to review every validation column.') }}</p>
                <div class="table-responsive ui-responsive-list-wrap">
                    <table class="table table-sm cf-data-table ui-responsive-list ui-mobile-cards">
                        <thead><tr><th>#</th><th>{{ __('School') }}</th><th>{{ __('Type') }}</th><th>{{ __('Bank Date') }}</th><th>{{ __('Bank Reference') }}</th><th>{{ __('Fund Account') }}</th><th>{{ __('Amount / Currency') }}</th><th>{{ __('Status') }}</th><th>{{ __('Validation result') }}</th><th>{{ __('Source') }}</th></tr></thead>
                        <tbody>@forelse($batch->rows as $row)<tr><td data-label="#">{{ $row->row_number }}</td><td data-label="{{ __('School') }}"><span class="ui-cell-primary">{{ $row->normalized_data['school_code'] ?? '—' }}</span></td><td data-label="{{ __('Type') }}">{{ $row->document_type ?: '—' }}</td><td data-label="{{ __('Bank Date') }}">{{ $row->normalized_data['transaction_date'] ?? '—' }}</td><td data-label="{{ __('Bank Reference') }}" class="cf-break-anywhere">{{ $row->normalized_data['bank_reference'] ?? $row->reference_no ?: '—' }}</td><td data-label="{{ __('Fund Account') }}">{{ $row->normalized_data['fund_account_code'] ?? '—' }}</td><td data-label="{{ __('Amount / Currency') }}">{{ $row->normalized_data['amount'] ?? '—' }} {{ $row->normalized_data['currency'] ?? '' }}</td><td data-label="{{ __('Status') }}"><span class="badge cf-status-badge badge-{{ in_array($row->result_status, ['Error','Conflict']) ? 'danger' : ($row->result_status === 'Duplicate' ? 'secondary' : ($row->result_status === 'Unidentified' ? 'info' : 'success')) }}">{{ $row->result_status === 'Unidentified' ? __('Unidentified Deposit') : __($row->result_status) }}</span></td><td data-label="{{ __('Validation result') }}" class="cf-break-anywhere">{{ $row->error_message ?: ($row->result_status === 'Duplicate' ? __('Duplicate: skipped on confirmation.') : ($row->canonical_source_uuid ? __('Canonical source linked.') : ($row->result_status === 'Unidentified' ? __('Will create one physical cash-in in the Unidentified Deposit pool; it is not School income.') : __('Ready for confirmation')))) }}</td><td data-label="">@if($row->canonical_source_id)<a class="btn btn-sm btn-outline-primary" href="{{ route('central-finance.group-import.source', [$batch->token, $row->id]) }}">{{ __('View source') }}</a>@else — @endif</td></tr>@empty<tr><td colspan="10" data-label=""><div class="cf-empty-state">{{ __('No preview rows found.') }}</div></td></tr>@endforelse</tbody>
                    </table>
                </div>
                {{ method_exists($batch->rows, 'links') ? $batch->rows->links() : '' }}

                <section class="ui-form-section mt-3 mb-0" aria-labelledby="group-import-confirm-title">
                    <div class="d-flex flex-wrap justify-content-between align-items-center">
                        <div class="mr-3">
                            <span class="badge badge-light mb-2">{{ __('Step 4') }}</span>
                            <h3 class="h6 mb-1" id="group-import-confirm-title">{{ __('Confirm import') }}</h3>
                            <p class="text-muted small mb-2 mb-md-0">{{ __('Confirmation posts all Valid rows atomically. Duplicate rows are skipped; errors and conflicts must be resolved first.') }}</p>
                        </div>
                        @if($confirmReady)
                            <form method="POST" action="{{ route('central-finance.group-import.confirm', $batch->token) }}" data-lifecycle-confirm data-lifecycle-object="{{ $batch->file_name }}" data-lifecycle-current-status="{{ __('Previewed') }}" data-lifecycle-result="{{ __('All New rows will be posted atomically; Duplicate rows will be skipped.') }}">@csrf<button class="btn btn-theme">{{ __('Confirm Group Import') }}</button></form>
                        @elseif($batch->status === 'completed')
                            <span class="badge badge-success">{{ __('Confirmed') }}</span>
                        @else
                            <button class="btn btn-theme" type="button" disabled>{{ __('Resolve validation issues first') }}</button>
                        @endif
                    </div>
                    @if($batch->status === 'completed')<div class="cf-history-notice mt-3 mb-0"><strong>{{ __('Confirmed by') }}:</strong> {{ $batch->confirmedBy?->full_name ?: '—' }} · <strong>{{ __('Confirmed at') }}:</strong> {{ $batch->confirmed_at?->format('Y-m-d H:i') ?: '—' }}<br>{{ __('New created') }}: {{ $batch->new_rows }} · {{ __('Duplicate skipped') }}: {{ $batch->duplicate_rows }}</div>@elseif(!$confirmReady)<div class="alert alert-warning mt-3 mb-0">{{ __('This batch cannot be confirmed until all errors and conflicts are resolved.') }}</div>@endif
                </section>
            </div>
        </div>
    @endif
</div>
@if($batch)<x-central-finance.lifecycle-confirmation />@endif
@endsection
@section('script')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var group = document.getElementById('group-import-finance-group');
    var link = document.getElementById('group-import-template-link');
    if (!group || !link) return;
    group.addEventListener('change', function () {
        link.href = link.dataset.templateUrl + '?finance_group_id=' + encodeURIComponent(group.value);
    });
});
</script>
@endsection
