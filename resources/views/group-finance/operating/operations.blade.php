@extends('layouts.master')

@section('title', __('Finance Operations'))

@section('content')
<div class="content-wrapper" data-operating-finance-operations>
    @include('group-finance.operating._context')
    <div class="page-header"><h3 class="page-title">{{ __('Finance Operations') }}</h3></div>
    <p class="text-muted">{{ __('Only Expense, Other Income, and Student Fee collection are available in this operating context. Transfers and Handovers remain unavailable.') }}</p>
    <div class="row">
        <div class="col-md-4"><div class="card"><div class="card-body"><h4>{{ __('Receive Money') }}</h4>
            <form method="POST" action="{{ route('group-finance.operating.receive-money.store') }}" data-operating-receive-money-form>@csrf
                <input class="form-control mb-2" name="date" type="date" value="{{ now()->toDateString() }}" required>
                <input class="form-control mb-2" name="payer" placeholder="{{ __('Payer') }}" required>
                <input class="form-control mb-2" name="description" placeholder="{{ __('Description') }}" required>
                <input class="form-control mb-2" name="amount" type="number" min="0.01" step="0.01" placeholder="{{ __('Amount') }}" required>
                <select class="form-control mb-2" name="payment_method" required><option value="Cash">Cash</option><option value="KBZ Pay">KBZ Pay</option><option value="KBZ Bank">KBZ Bank</option></select>
                <select class="form-control mb-2" name="bank_account_id" required>@foreach($options['accounts'] as $account)<option value="{{ $account['id'] }}">{{ $account['account_name'] }}</option>@endforeach</select>
                <input class="form-control mb-2" name="reference_no" placeholder="{{ __('Reference') }}">
                <button class="btn btn-primary" type="submit">{{ __('Receive Money') }}</button>
            </form>
        </div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body"><h4>{{ __('Expense') }}</h4>
            <form method="POST" action="{{ route('group-finance.operating.expense.store') }}" data-operating-expense-form>@csrf
                <input class="form-control mb-2" name="date" type="date" value="{{ now()->toDateString() }}" required>
                <input class="form-control mb-2" name="title" placeholder="{{ __('Title') }}" required>
                <input class="form-control mb-2" name="description" placeholder="{{ __('Description') }}">
                <input class="form-control mb-2" name="amount" type="number" min="0.01" step="0.01" placeholder="{{ __('Amount') }}" required>
                <input type="hidden" name="transaction_currency" value="MMK"><input type="hidden" name="original_amount" value=""><input type="hidden" name="exchange_rate_snapshot" value="1">
                <select class="form-control mb-2" name="category_id" required>@foreach($options['categories'] as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                <select class="form-control mb-2" name="session_year_id" required>@foreach($options['session_years'] as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                <select class="form-control mb-2" name="bank_account_id" required>@foreach($options['accounts'] as $account)<option value="{{ $account['id'] }}">{{ $account['account_name'] }}</option>@endforeach</select>
                <input class="form-control mb-2" name="ref_no" placeholder="{{ __('Reference') }}">
                <button class="btn btn-primary" type="submit">{{ __('Create Expense') }}</button>
            </form>
        </div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body"><h4>{{ __('Student Fee') }}</h4>
            <form method="POST" action="{{ route('group-finance.operating.student-fee.store') }}" data-operating-student-fee-form>@csrf
                <input class="form-control mb-2" name="date" type="date" value="{{ now()->toDateString() }}" required>
                <select class="form-control mb-2" name="fees_id" required>@foreach($options['fees'] as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                <select class="form-control mb-2" name="student_id" required>@foreach($options['students'] as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                <input class="form-control mb-2" name="enter_amount" type="number" min="0.01" step="0.01" placeholder="{{ __('Amount') }}" required>
                <input type="hidden" name="installment_mode" value="0"><input type="hidden" name="mode" value="Cash"><input type="hidden" name="transaction_currency" value="MMK">
                <select class="form-control mb-2" name="bank_account_id" required>@foreach($options['accounts'] as $account)<option value="{{ $account['id'] }}">{{ $account['account_name'] }}</option>@endforeach</select>
                <input class="form-control mb-2" name="reference_no" placeholder="{{ __('Reference') }}">
                <button class="btn btn-primary" type="submit">{{ __('Receive Student Fee') }}</button>
            </form>
        </div></div></div>
    </div>
</div>
@endsection
