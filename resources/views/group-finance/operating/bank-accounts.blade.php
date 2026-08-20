@extends('layouts.master')

@section('title', __('Bank Accounts'))

@section('content')
<div class="content-wrapper">
    @include('group-finance.operating._context')
    <div class="page-header"><h3 class="page-title">{{ __('Bank Accounts') }}</h3></div>
    <div class="card"><div class="card-body table-responsive">
        <table class="table" data-operating-bank-accounts><thead><tr>
            <th>{{ __('Account Name') }}</th><th>{{ __('Currency') }}</th><th class="text-right">{{ __('Money In') }}</th><th class="text-right">{{ __('Money Out') }}</th><th class="text-right">{{ __('Current Balance') }}</th>
        </tr></thead><tbody>
            @forelse ($accounts as $account)
                <tr><td>{{ $account['account_name'] }}</td><td>{{ $account['currency'] }}</td><td class="text-right">{{ number_format($account['money_in'], 2) }}</td><td class="text-right">{{ number_format($account['money_out'], 2) }}</td><td class="text-right">{{ number_format($account['current_balance'], 2) }}</td></tr>
            @empty
                <tr><td colspan="5" class="text-center">{{ __('No authorized Fund Accounts') }}</td></tr>
            @endforelse
        </tbody></table>
    </div></div>
</div>
@endsection
