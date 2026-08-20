@extends('layouts.master')

@section('title', __('Transactions'))

@section('content')
<div class="content-wrapper">
    @include('group-finance.operating._context')
    <div class="page-header"><h3 class="page-title">{{ __('Transactions') }}</h3></div>
    <div class="card"><div class="card-body">
        <form method="GET" class="row align-items-end mb-3" data-operating-transactions-filter>
            <div class="form-group col-md-3"><label>{{ __('From') }}</label><input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control"></div>
            <div class="form-group col-md-3"><label>{{ __('To') }}</label><input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control"></div>
            <div class="form-group col-md-3"><label>{{ __('Fund Account') }}</label><select name="bank_account_id" class="form-control"><option value="">{{ __('All') }}</option>@foreach($accounts as $account)<option value="{{ $account['id'] }}" @selected((string)($filters['bank_account_id'] ?? '') === (string)$account['id'])>{{ $account['account_name'] }}</option>@endforeach</select></div>
            <div class="form-group col-md-3"><button class="btn btn-primary" type="submit">{{ __('Filter') }}</button></div>
        </form>
        <div class="table-responsive"><table class="table" data-operating-transactions><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Reference') }}</th><th>{{ __('Type') }}</th><th>{{ __('Fund Account') }}</th><th class="text-right">{{ __('Money In') }}</th><th class="text-right">{{ __('Money Out') }}</th></tr></thead><tbody>
            @forelse ($register['rows'] as $row)
                <tr><td>{{ $row['posting_date'] }}</td><td>{{ $row['reference_no'] ?: '-' }}</td><td>{{ $row['transaction_class'] }}</td><td>{{ $row['fund_account_name'] ?: ($row['from_fund_account_name'] . ' → ' . $row['to_fund_account_name']) }}</td><td class="text-right">{{ $row['money_in'] ? number_format($row['money_in'], 2) : '-' }}</td><td class="text-right">{{ $row['money_out'] ? number_format($row['money_out'], 2) : '-' }}</td></tr>
            @empty
                <tr><td colspan="6" class="text-center">{{ __('No transactions found.') }}</td></tr>
            @endforelse
        </tbody></table></div>
    </div></div>
</div>
@endsection
