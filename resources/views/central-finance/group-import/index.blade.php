@extends('layouts.master')
@section('title', __('Group Finance Import'))
@section('content')
<div class="central-finance-page">
  @include('central-finance.partials.foundation-styles')
  <div class="cf-page-header mb-3"><div><h1 class="h4 mb-1">{{ __('Group Finance Import Preview') }}</h1><p class="text-muted mb-0">{{ __('Validate a multi-School workbook before confirmation. Preview never creates financial documents.') }}</p></div><a id="group-import-template-link" class="btn btn-outline-primary" data-template-url="{{ route('central-finance.group-import.template') }}" href="{{ route('central-finance.group-import.template', ['finance_group_id' => $groups->first()->id]) }}">{{ __('group-import.download_template_v21') }}</a></div>
  <div class="card central-finance-form-card mb-3"><div class="card-body"><form method="POST" enctype="multipart/form-data" action="{{ route('central-finance.group-import.preview') }}"><div class="form-row align-items-end">@csrf <div class="form-group col-md-4"><label>{{ __('Finance Group') }}</label><select id="group-import-finance-group" name="finance_group_id" class="form-control" required>@foreach($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach</select></div><div class="form-group col-md-5"><label>{{ __('Group Import File') }}</label><input class="form-control-file" type="file" name="group_import" accept=".xlsx,.xls,.csv" required></div><div class="form-group col-md-3"><button class="btn btn-theme btn-block">{{ __('Preview Import') }}</button></div></div></form></div></div>
  @if($batch)
    @php($summary = $batch->school_summary ?? [])
    <div class="card"><div class="card-body">
      <div class="cf-workspace-toolbar"><div><h2 class="h5 mb-1">{{ $batch->status === 'completed' ? __('Completed Group Import') : __('Preview result') }}</h2><p class="text-muted mb-0 cf-break-anywhere">{{ $batch->file_name }} · {{ __('Total rows') }}: {{ $batch->total_rows }}</p></div><span class="badge cf-status-badge badge-{{ $batch->status === 'completed' ? 'success' : 'info' }}">{{ __($batch->status) }}</span></div>
      <div class="row mb-3">@foreach(['new'=>'primary','duplicate'=>'secondary','conflict'=>'warning','error'=>'danger'] as $key => $style)<div class="col-6 col-lg-3 mb-2"><div class="cf-summary-card"><span class="cf-summary-card__label">{{ __(ucfirst($key)) }}</span><strong class="cf-summary-card__value">{{ $batch->{$key.'_rows'} }}</strong></div></div>@endforeach</div>
      @if(!empty($summary['currency_totals']))<div class="mb-3"><h3 class="h6">{{ __('Eligible amounts by currency') }}</h3><div class="row">@foreach($summary['currency_totals'] as $currency => $totals)<div class="col-md-4 mb-2"><div class="cf-summary-card"><span class="cf-summary-card__label">{{ $currency }}</span>@foreach($totals as $type => $total)<span class="d-block"><strong>{{ $type === 'expense' ? __('Expense') : __('Other Income') }}</strong> · {{ $total['count'] }} · {{ number_format($total['amount'], 2) }} {{ $currency }}</span>@endforeach</div></div>@endforeach</div></div>@endif
      <div class="mb-3"><h3 class="h6">{{ __('School summary') }}</h3><div class="row">@forelse($summary['schools'] ?? [] as $schoolId => $school)<div class="col-lg-6 mb-2"><div class="cf-summary-card"><span class="cf-primary-line">{{ $school['label'] ?: $school['code'] }}</span><span class="cf-secondary-line">{{ $school['code'] }}</span><span class="small d-block">{{ __('New') }} {{ $school['new'] }} · {{ __('Duplicate') }} {{ $school['duplicate'] }} · {{ __('Conflict') }} {{ $school['conflict'] }} · {{ __('Error') }} {{ $school['error'] }}</span>@foreach($school['currency_totals'] ?? [] as $currency => $totals)<span class="small d-block mt-1"><strong>{{ $currency }}</strong> @foreach($totals as $type => $total) · {{ $type === 'expense' ? __('Expense') : __('Other Income') }} {{ number_format($total['amount'],2) }} @endforeach</span>@endforeach</div></div>@empty<div class="col-12"><div class="cf-empty-state">{{ __('No routed School summary is available.') }}</div></div>@endforelse</div></div>
      @if($batch->status === 'previewed' && $batch->error_rows === 0 && $batch->conflict_rows === 0)
        <form method="POST" action="{{ route('central-finance.group-import.confirm', $batch->token) }}" class="mb-3" data-lifecycle-confirm data-lifecycle-object="{{ $batch->file_name }}" data-lifecycle-current-status="{{ __('Previewed') }}" data-lifecycle-result="{{ __('All New rows will be posted atomically; Duplicate rows will be skipped.') }}">@csrf<button class="btn btn-theme">{{ __('Confirm Group Import') }}</button></form>
      @elseif($batch->status === 'completed')
        <div class="cf-history-notice mb-3"><strong>{{ __('Confirmed by') }}:</strong> {{ $batch->confirmedBy?->full_name ?: '—' }} · <strong>{{ __('Confirmed at') }}:</strong> {{ $batch->confirmed_at?->format('Y-m-d H:i') ?: '—' }}<br>{{ __('New created') }}: {{ $batch->new_rows }} · {{ __('Duplicate skipped') }}: {{ $batch->duplicate_rows }}</div>
      @else
        <div class="alert alert-warning mb-3">{{ __('This batch cannot be confirmed until all errors and conflicts are resolved.') }}</div>
      @endif
      <div class="table-responsive"><table class="table table-sm cf-data-table cf-mobile-card-table"><thead><tr><th>#</th><th>{{ __('School') }}</th><th>{{ __('Type') }}</th><th>{{ __('Reference') }}</th><th>{{ __('Status') }}</th><th>{{ __('Validation result') }}</th><th>{{ __('Source') }}</th></tr></thead><tbody>@forelse($batch->rows as $row)<tr><td data-label="#">{{ $row->row_number }}</td><td data-label="{{ __('School') }}">{{ $row->normalized_data['school_code'] ?? '—' }}</td><td data-label="{{ __('Type') }}">{{ $row->document_type ?: '—' }}</td><td data-label="{{ __('Reference') }}" class="cf-break-anywhere">{{ $row->reference_no ?: '—' }}</td><td data-label="{{ __('Status') }}"><span class="badge cf-status-badge badge-{{ in_array($row->result_status, ['Error','Conflict']) ? 'danger' : ($row->result_status === 'Duplicate' ? 'secondary' : 'success') }}">{{ __($row->result_status) }}</span></td><td data-label="{{ __('Validation result') }}" class="cf-break-anywhere">{{ $row->error_message ?: ($row->result_status === 'Duplicate' ? __('Duplicate: skipped on confirmation.') : ($row->canonical_source_uuid ? __('Canonical source linked.') : __('Ready for confirmation'))) }}</td><td data-label="{{ __('Source') }}">@if($row->canonical_source_id)<a class="btn btn-sm btn-outline-primary" href="{{ route('central-finance.group-import.source', [$batch->token, $row->id]) }}">{{ __('View source') }}</a>@else — @endif</td></tr>@empty<tr><td colspan="7"><div class="cf-empty-state">{{ __('No preview rows found.') }}</div></td></tr>@endforelse</tbody></table></div>{{ method_exists($batch->rows, 'links') ? $batch->rows->links() : '' }}
    </div></div>
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
