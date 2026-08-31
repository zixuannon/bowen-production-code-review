<div class="card">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
            <div>
                <h5 class="mb-1">{{ __('账户流水') }} · {{ $accountReport->account_name }}</h5>
                <small class="text-muted">{{ __('Running balance uses the audited opening balance plus canonical Central Ledger entries.') }}</small>
            </div>
            <div class="mt-2 mt-md-0">
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('central-finance.accounts.statement.export', array_merge(request()->query(), ['fundAccount' => $accountReport->id, 'format' => 'csv'])) }}">CSV</a>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('central-finance.accounts.statement.export', array_merge(request()->query(), ['fundAccount' => $accountReport->id, 'format' => 'xlsx'])) }}">XLSX</a>
            </div>
        </div>
        <form method="GET" action="{{ route('central-finance.accounts.statements') }}" class="form-row mb-3">
            <div class="col-md-3 mb-2"><select name="fund_account_id" class="form-control">@foreach($accounts as $account)<option value="{{ $account->id }}" @selected($account->id === $accountReport->id)>{{ $account->account_name }} · {{ $account->account_code }} · {{ $account->currency }}</option>@endforeach</select></div>
            <div class="col-md-2 mb-2"><input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control"></div>
            <div class="col-md-2 mb-2"><input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control"></div>
            <div class="col-md-2 mb-2"><input name="reference" value="{{ $filters['reference'] ?? '' }}" class="form-control" placeholder="{{ __('Reference') }}"></div>
            <div class="col-md-2 mb-2"><select name="source" class="form-control"><option value="">{{ __('All sources') }}</option>@foreach($statementEntries->pluck('source_type')->unique()->sort() as $source)<option value="{{ $source }}" @selected(($filters['source'] ?? '') === $source)>{{ app(\App\Services\CentralFinanceLedgerPresentationService::class)->sourceLabel($source) }}</option>@endforeach</select></div>
            <div class="col-md-1 mb-2"><button class="btn btn-outline-primary btn-block">{{ __('Filter') }}</button></div>
        </form>
        <div class="row mb-3"><div class="col-md-4"><small class="text-muted">{{ __('Opening Balance') }}</small><strong class="d-block">{{ number_format($statementOpeningBalance, 2) }} {{ $accountReport->currency }}</strong></div><div class="col-md-4"><small class="text-muted">{{ __('Money In') }} / {{ __('Money Out') }}</small><strong class="d-block">{{ number_format($statementTotals['money_in'], 2) }} / {{ number_format($statementTotals['money_out'], 2) }} {{ $accountReport->currency }}</strong></div><div class="col-md-4"><small class="text-muted">{{ __('Closing Balance') }}</small><strong class="d-block">{{ number_format(app(\App\Services\CentralFinanceFundAccountBalanceService::class)->currentBalance($accountReport), 2) }} {{ $accountReport->currency }}</strong></div></div>
        <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Source') }}</th><th>{{ __('Description') }}</th><th>{{ __('Money In') }}</th><th>{{ __('Money Out') }}</th><th>{{ __('Running Balance') }}</th><th>{{ __('Currency') }}</th><th>{{ __('Operator') }}</th><th>{{ __('Reference') }}</th></tr></thead><tbody>@forelse($statementEntries as $entry)<tr><td>{{ $entry->entry_date }}</td><td><a href="{{ route('central-finance.ledger.source', $entry->id) }}">{{ $entry->readable_source }}</a></td><td>@if($entry->funding_leg ?? null)<strong>{{ $entry->funding_leg === 'incoming' ? __('Incoming Funding from HQ') : __('Transfer to School') }}</strong><span class="d-block small text-muted">{{ $entry->funding_source_account_label }} → {{ $entry->funding_destination_account_label }}</span>@else{{ $entry->memo ?: '—' }}@endif</td><td>{{ number_format($entry->money_in, 2) }}</td><td>{{ number_format($entry->money_out, 2) }}</td><td>{{ number_format($entry->running_balance, 2) }}</td><td>{{ $entry->currency }}</td><td>{{ optional($operators->firstWhere('id', $entry->created_by))->full_name ?: '—' }}</td><td><a href="{{ route('central-finance.ledger.show', $entry->id) }}">{{ $entry->reference_no ?: $entry->readable_document_number }}</a></td></tr>@empty<tr><td colspan="9" class="text-muted">{{ __('No canonical Ledger entries match these filters.') }}</td></tr>@endforelse</tbody></table></div>
        <div class="mt-3">{{ $statementEntries->links() }}</div>
    </div>
</div>
