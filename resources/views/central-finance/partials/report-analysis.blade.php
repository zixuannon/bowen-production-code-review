<div class="card mt-3"><div class="card-body">
    <h5>{{ __('Report filters') }}</h5>
    <form method="GET" class="form-row cf-filter-bar">
        <div class="col-md-3 mb-2"><label>{{ __('From') }}</label><input type="date" name="from" class="form-control" value="{{ $filters['from'] ?? '' }}"></div>
        <div class="col-md-3 mb-2"><label>{{ __('To') }}</label><input type="date" name="to" class="form-control" value="{{ $filters['to'] ?? '' }}"></div>
        @if(!$school)<div class="col-md-3 mb-2"><label>{{ __('School') }}</label><select name="school_id" class="form-control"><option value="">{{ __('All Schools') }}</option>@foreach($schools as $availableSchool)<option value="{{ $availableSchool->id }}" @selected(($filters['school_id'] ?? null) == $availableSchool->id)>{{ $availableSchool->name }}</option>@endforeach</select></div>@endif
        <div class="col-md-3 mb-2"><label>{{ __('Currency') }}</label><select name="currency" class="form-control"><option value="">{{ __('All currencies') }}</option>@foreach(\App\Support\CentralFinanceCurrency::ALLOWED as $currency)<option value="{{ $currency }}" @selected(($filters['currency'] ?? '') === $currency)>{{ $currency }}</option>@endforeach</select></div>
        <div class="col-md-3 mb-2 d-flex align-items-end"><button class="btn btn-outline-primary btn-block">{{ __('Apply filters') }}</button></div>
    </form>
</div></div>

<div class="card mt-3"><div class="card-body">
    <h5>{{ __('School comparison') }}</h5>
    <div class="table-responsive"><table class="table table-sm cf-data-table cf-mobile-card-table mb-0"><thead><tr><th>{{ __('School') }}</th><th>{{ __('Currency') }}</th><th>{{ __('Money In') }}</th><th>{{ __('Money Out') }}</th><th>{{ __('Operating Income') }}</th><th>{{ __('Operating Expense') }}</th><th>{{ __('Net') }}</th><th>{{ __('Drill-down') }}</th></tr></thead><tbody>@forelse($reportSchoolComparison as $row)<tr><td data-label="{{ __('School') }}">{{ $schoolNames[$row['school_id']] ?? '—' }}</td><td data-label="{{ __('Currency') }}">{{ $row['currency'] }}</td><td data-label="{{ __('Money In') }}">{{ number_format($row['money_in'], 2) }}</td><td data-label="{{ __('Money Out') }}">{{ number_format($row['money_out'], 2) }}</td><td data-label="{{ __('Operating Income') }}">{{ number_format($row['income'], 2) }}</td><td data-label="{{ __('Operating Expense') }}">{{ number_format($row['expense'], 2) }}</td><td data-label="{{ __('Net') }}">{{ number_format($row['income'] - $row['expense'], 2) }}</td><td data-label=""><a class="btn btn-sm btn-outline-primary" href="{{ route('central-finance.ledger', array_filter(['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null, 'school_id' => $row['school_id'], 'currency' => $row['currency']])) }}">{{ __('Open Ledger') }}</a></td></tr>@empty<tr><td colspan="8"><div class="cf-empty-state">{{ __('No Ledger activity matches this date range.') }}</div></td></tr>@endforelse</tbody></table></div>
</div></div>

<div class="row mt-3">
    <div class="col-xl-6 mb-3"><div class="card h-100"><div class="card-body"><h5>{{ __('Income / expense trend') }}</h5><div class="table-responsive"><table class="table table-sm cf-mobile-card-table mb-0"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Currency') }}</th><th>{{ __('Income') }}</th><th>{{ __('Expense') }}</th><th>{{ __('Net') }}</th></tr></thead><tbody>@forelse($reportTrend as $row)<tr><td data-label="{{ __('Date') }}">{{ $row['date'] }}</td><td data-label="{{ __('Currency') }}">{{ $row['currency'] }}</td><td data-label="{{ __('Income') }}">{{ number_format($row['income'], 2) }}</td><td data-label="{{ __('Expense') }}">{{ number_format($row['expense'], 2) }}</td><td data-label="{{ __('Net') }}">{{ number_format($row['income'] - $row['expense'], 2) }}</td></tr>@empty<tr><td colspan="5"><div class="cf-empty-state">{{ __('No trend data for this range.') }}</div></td></tr>@endforelse</tbody></table></div></div></div></div>
    <div class="col-xl-6 mb-3"><div class="card h-100"><div class="card-body">
        <h5>{{ __('Chart of Accounts analysis') }}</h5>
        <p class="text-muted small">{{ __('Cash movement by account; Asset, Liability and Equity are not operating income or expense.') }}</p>
        <div class="table-responsive"><table class="table table-sm cf-mobile-card-table mb-0">
            <thead><tr><th>{{ __('School') }}</th><th>{{ __('Account Code') }}</th><th>{{ __('Account Type') }}</th><th>{{ __('Account Name') }}</th><th>{{ __('Currency') }}</th><th>{{ __('Incoming') }}</th><th>{{ __('Outgoing') }}</th><th>{{ __('Net Movement') }}</th><th>{{ __('Drill-down') }}</th></tr></thead>
            <tbody>@forelse($reportCategoryAnalysis as $row)
                <tr>
                    <td data-label="{{ __('School') }}">{{ $schoolNames[$row['school_id']] ?? '—' }}</td>
                    <td data-label="{{ __('Account Code') }}">{{ $row['category_code'] ?? '—' }}</td>
                    <td data-label="{{ __('Account Type') }}">{{ __(ucfirst($row['account_type'] ?? '')) }}</td>
                    <td data-label="{{ __('Account Name') }}">{{ $row['category'] }}</td>
                    <td data-label="{{ __('Currency') }}">{{ $row['currency'] }}</td>
                    <td data-label="{{ __('Incoming') }}">{{ number_format($row['money_in'], 2) }}</td>
                    <td data-label="{{ __('Outgoing') }}">{{ number_format($row['money_out'], 2) }}</td>
                    <td data-label="{{ __('Net Movement') }}">{{ number_format($row['net_movement'], 2) }}</td>
                    <td data-label=""><a class="btn btn-sm btn-outline-primary" href="{{ route('central-finance.ledger', array_filter(['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null, 'school_id' => $row['school_id'], 'currency' => $row['currency'], 'category_id' => $row['category_id']])) }}">{{ __('Open Ledger') }}</a></td>
                </tr>
            @empty<tr><td colspan="9"><div class="cf-empty-state">{{ __('No account activity for this range.') }}</div></td></tr>@endforelse</tbody>
        </table></div>
    </div></div></div>
</div>
