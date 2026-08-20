@extends('layouts.master')

@section('title', __('Finance Reports'))

@section('content')
<div class="content-wrapper">
    @include('group-finance.operating._context')
    <div class="page-header"><h3 class="page-title">{{ __('Finance Reports') }}</h3></div>
    <div class="row" data-operating-finance-report>
        <div class="col-md-4"><div class="card"><div class="card-body">{{ __('Operating Income') }}<h4>{{ number_format($register['summary']['operating_income'], 2) }}</h4></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body">{{ __('Operating Expense') }}<h4>{{ number_format($register['summary']['operating_expense'], 2) }}</h4></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body">{{ __('Operating Net') }}<h4>{{ number_format($register['summary']['operating_net'], 2) }}</h4></div></div></div>
    </div>
    <p class="text-muted">{{ __('Internal Transfers') }}: {{ number_format($register['summary']['internal_transfer_amount'], 2) }} — {{ __('excluded from operating income and expense') }}</p>
    <div class="card"><div class="card-body table-responsive"><table class="table"><thead><tr><th>{{ __('Type') }}</th><th class="text-right">{{ __('Income') }}</th><th class="text-right">{{ __('Expense') }}</th><th class="text-right">{{ __('Transactions') }}</th></tr></thead><tbody>
        @foreach($categories as $category)<tr><td>{{ $category['type'] }}</td><td class="text-right">{{ number_format($category['income'], 2) }}</td><td class="text-right">{{ number_format($category['expense'], 2) }}</td><td class="text-right">{{ $category['count'] }}</td></tr>@endforeach
    </tbody></table></div></div>
</div>
@endsection
