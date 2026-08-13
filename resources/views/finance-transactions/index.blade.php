@extends('layouts.master')

@section('title', __('Transactions'))

@section('content')
<div class="content-wrapper">
    <div class="page-header d-flex justify-content-between align-items-center">
        <h3 class="page-title">{{ __('Transactions') }}</h3>
        <div>
            @if($canReceive)
                <button type="button" class="btn btn-success" data-toggle="modal" data-target="#receiveMoneyModal"><i class="fa fa-plus"></i> {{ __('Receive Money') }}</button>
            @endif
            @if($canExpense)
                <a class="btn btn-outline-danger" href="{{ route('expense.index') }}">{{ __('Expense') }}</a>
                <a class="btn btn-outline-primary" href="{{ route('expense.index') }}#expenseImportModal">{{ __('Import') }}</a>
            @endif
        </div>
    </div>

    <div class="row">
        @foreach(['money_in' => ['Money In', 'success'], 'money_out' => ['Money Out', 'danger'], 'net_movement' => ['Net Movement', 'primary']] as $key => [$label, $class])
            <div class="col-md-4 grid-margin stretch-card"><div class="card"><div class="card-body"><small>{{ __($label) }}</small><h3 class="text-{{ $class }}">{{ number_format($summary[$key], 2) }}</h3></div></div></div>
        @endforeach
    </div>
    <p class="text-muted small mb-3">{{ __('Operating income: :income | Operating expense: :expense | Internal movement: :in in / :out out. Internal transfers are excluded from operating income and expense.', ['income' => number_format($summary['operating_income'], 2), 'expense' => number_format($summary['operating_expense'], 2), 'in' => number_format($summary['internal_in'], 2), 'out' => number_format($summary['internal_out'], 2)]) }}</p>

    <div class="card"><div class="card-body">
        <form method="GET" class="row align-items-end mb-3">
            <div class="form-group col-md-2"><label>{{ __('From') }}</label><input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control"></div>
            <div class="form-group col-md-2"><label>{{ __('To') }}</label><input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control"></div>
            <div class="form-group col-md-2"><label>{{ __('Type') }}</label><select name="type" class="form-control"><option value="">{{ __('All') }}</option>@foreach(\App\Services\FinanceTransactionRegisterService::TYPES as $type)<option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ __(str_replace('_', ' ', $type)) }}</option>@endforeach</select></div>
            <div class="form-group col-md-2"><label>{{ __('Fund Account') }}</label><select name="bank_account_id" class="form-control"><option value="">{{ __('All') }}</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected((string)($filters['bank_account_id'] ?? '') === (string)$account->id)>{{ $account->account_name }}</option>@endforeach</select></div>
            <div class="form-group col-md-2"><label>{{ __('Reference') }}</label><input name="reference" value="{{ $filters['reference'] ?? '' }}" class="form-control"></div>
            <div class="form-group col-md-2"><label>{{ __('Search') }}</label><input name="keyword" value="{{ $filters['keyword'] ?? '' }}" class="form-control"></div>
            <div class="col-12"><button class="btn btn-primary">{{ __('Filter') }}</button> <a class="btn btn-light" href="{{ route('finance-transactions.index') }}">{{ __('Clear') }}</a></div>
        </form>
        <div class="table-responsive"><table class="table table-striped"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Reference') }}</th><th>{{ __('Type') }}</th><th>{{ __('Student / Payee') }}</th><th>{{ __('Description') }}</th><th>{{ __('Payment Method') }}</th><th>{{ __('Fund Account') }}</th><th class="text-right">{{ __('Money In') }}</th><th class="text-right">{{ __('Money Out') }}</th><th>{{ __('Status') }}</th></tr></thead>
        <tbody>@forelse($rows as $row)<tr><td>{{ $row['date'] }}</td><td>{{ $row['reference'] ?: '-' }}</td><td>{{ __(str_replace('_', ' ', $row['transaction_type'])) }}</td><td>{{ $row['counterparty'] }}</td><td>{{ $row['description'] }}</td><td>{{ $row['payment_method'] }}</td><td>{{ $row['fund_account'] }}</td><td class="text-right text-success">{{ $row['money_in'] ? number_format($row['money_in'], 2) : '-' }}</td><td class="text-right text-danger">{{ $row['money_out'] ? number_format($row['money_out'], 2) : '-' }}</td><td><span class="badge badge-success">{{ __('Completed') }}</span></td></tr>@empty<tr><td colspan="10" class="text-center">{{ __('No transactions found.') }}</td></tr>@endforelse</tbody></table></div>
    </div></div>
</div>

@if($canReceive)
<div class="modal fade" id="receiveMoneyModal" tabindex="-1"><div class="modal-dialog"><form id="receive-money-form" method="POST" action="{{ route('finance-transactions.receive') }}" class="modal-content">@csrf
    <div class="modal-header"><h5 class="modal-title">{{ __('Receive Money') }}</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
    <div class="modal-body"><div id="receive-money-errors" class="alert alert-danger d-none"></div>
        <div class="form-group"><label>{{ __('Date') }} *</label><input type="date" name="date" value="{{ now()->toDateString() }}" class="form-control" required></div>
        <div class="form-group"><label>{{ __('Payer / Source') }} *</label><input name="payer" class="form-control" required></div>
        <div class="form-group"><label>{{ __('Description') }} *</label><input name="description" class="form-control" required></div>
        <div class="form-group"><label>{{ __('Amount') }} *</label><input type="number" min="0.01" step="0.01" name="amount" class="form-control" required></div>
        <div class="form-group"><label>{{ __('Payment Method') }} *</label><select name="payment_method" class="form-control" required>@foreach(\App\Services\FeesPaymentService::PAYMENT_METHODS as $method)<option value="{{ $method }}">{{ $method }}</option>@endforeach</select></div>
        <div class="form-group"><label>{{ __('Fund Account') }} *</label><select name="bank_account_id" class="form-control" required><option value="">{{ __('Select') }}</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->account_name }}</option>@endforeach</select></div>
        <div class="form-group"><label>{{ __('Reference No.') }}</label><input name="reference_no" class="form-control"></div><div class="form-group"><label>{{ __('Remark') }}</label><textarea name="remark" class="form-control"></textarea></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">{{ __('Cancel') }}</button><button class="btn btn-success">{{ __('Receive Money') }}</button></div>
</form></div></div>
@endif
@endsection

@section('js')
@if($canReceive)
<script>document.getElementById('receive-money-form').addEventListener('submit',async function(e){e.preventDefault();const f=e.currentTarget,box=document.getElementById('receive-money-errors');box.classList.add('d-none');const r=await fetch(f.action,{method:'POST',headers:{'X-CSRF-TOKEN':f.querySelector('[name=_token]').value,'Accept':'application/json'},body:new FormData(f)});const data=await r.json();if(!r.ok||data.error){box.textContent=data.message||Object.values(data.errors||{}).flat().join(' ');box.classList.remove('d-none');return;}window.location.reload();});</script>
@endif
@endsection
