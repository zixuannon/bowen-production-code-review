@extends('layouts.master')

@section('title', __('Central Finance'))

@section('content')
<div class="content-wrapper">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
        <div>
            <h4 class="mb-1">{{ __('Central Finance') }}</h4>
            <p class="text-muted mb-0">
                {{ __('Central Finance · ') }}
                @if($school) {{ __('Operating School: ') }}{{ $school->name }} ({{ $school->code }}) @else {{ __('All Schools · Read-only group view') }} @endif
            </p>
        </div>
        <div class="d-flex align-items-center">
            <form method="POST" action="{{ route('central-finance.school.enter') }}" class="mr-2">
                @csrf
                <select name="school_id" class="form-control d-inline-block" onchange="this.form.submit()" aria-label="{{ __('Switch School') }}">
                    <option value="">{{ __('Switch School') }}</option>
                    @foreach($schools as $availableSchool)<option value="{{ $availableSchool->id }}" @selected($school && $school->id === $availableSchool->id)>{{ $availableSchool->name }}</option>@endforeach
                </select>
            </form>
            @if($school)<form method="POST" action="{{ route('central-finance.school.exit') }}">@csrf<button class="btn btn-outline-secondary">{{ __('All Schools') }}</button></form>@endif
        </div>
    </div>

    <nav class="nav nav-pills mb-4" aria-label="{{ __('Central Finance workspace') }}">
        @foreach(['dashboard'=>'Dashboard','receivables'=>'Receivables / Payments','operating'=>'Expense / Other Income / Reimbursement','accounts'=>'Fund Accounts','transfers'=>'Bank Transfer','handovers'=>'Fund Handover','funding'=>'HQ Funding','ledger'=>'Standard Ledger','reports'=>'Reports'] as $key=>$label)
            <a class="nav-link {{ $page === $key ? 'active' : '' }}" href="{{ route('central-finance.'.($key === 'operating' ? 'operations' : $key)) }}">{{ __($label) }}</a>
        @endforeach
    </nav>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if(!$school)<div class="alert alert-info">{{ __('All Schools shows consolidated Central Finance only. Select an authorized School to create or resolve financial documents.') }}</div>@endif
    @if($school && !$canOperate)<div class="alert alert-warning">{{ $cutoverStatus !== 'central' ? __('This School remains in legacy Finance until its approved Central cutover. Central Finance is read-only.') : __('You can view this School, but you do not have Central Finance operating authority.') }}</div>@endif

    @if($page === 'dashboard' || $page === 'reports')
        <div class="row">
            @foreach(['money_in'=>'Money In','money_out'=>'Money Out','operating_income'=>'Operating Income','operating_expense'=>'Operating Expense','operating_net'=>'Operating Net'] as $key=>$label)
                <div class="col-md"><div class="card mb-3"><div class="card-body"><small class="text-muted">{{ __($label) }}</small><h4>{{ number_format($totals[$key], 2) }}</h4></div></div></div>
            @endforeach
        </div>
        <div class="card"><div class="card-body"><h5>{{ __('Central Finance Dashboard') }}</h5><p class="text-muted">{{ __('Totals are derived exclusively from the append-only Central Standard Ledger. Internal transfers remain excluded from operating results.') }}</p></div></div>
    @endif

    @if($page === 'receivables')
        <div class="card mb-3"><div class="card-body"><h5>{{ __('Student Receivables / Payments') }}</h5><p class="text-muted">{{ __('Student identity is read only from Central Student Financial Profiles synchronized from the School tenant.') }}</p>
        @if($canOperate)<form method="POST" action="{{ route('central-finance.payments.store') }}" class="row">@csrf
            <div class="col-md-3"><select name="receivable_id" class="form-control" required><option value="">{{ __('Receivable') }}</option>@foreach($receivables as $r)<option value="{{ $r->id }}">{{ $r->description }} · {{ $r->amount_due - $r->amount_paid }}</option>@endforeach</select></div>
            <div class="col-md-2"><select name="fund_account_id" class="form-control" required><option value="">{{ __('Fund Account') }}</option>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->account_name }}</option>@endforeach</select></div>
            <div class="col-md-2"><input name="amount" type="number" step="0.01" min="0.01" class="form-control" placeholder="{{ __('Amount') }}" required></div><div class="col-md-2"><input name="payment_method" class="form-control" value="Cash" required></div><div class="col-md-2"><input name="payment_reference" class="form-control" placeholder="{{ __('Reference') }}"></div><div class="col-md-1"><button class="btn btn-theme">{{ __('Collect') }}</button></div>
        </form>@endif</div></div>
        <div class="card"><div class="card-body"><table class="table"><thead><tr><th>{{ __('Student') }}</th><th>{{ __('Description') }}</th><th>{{ __('Due') }}</th><th>{{ __('Paid') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>@foreach($receivables as $r)<tr><td>{{ optional($profiles->firstWhere('id',$r->student_profile_id))->student_name }}</td><td>{{ $r->description }}</td><td>{{ number_format($r->amount_due,2) }}</td><td>{{ number_format($r->amount_paid,2) }}</td><td>{{ $r->status }}</td></tr>@endforeach</tbody></table></div></div>
    @endif

    @if($page === 'operating')
        <div class="row">
            @if($canOperate)
            <div class="col-lg-6"><div class="card mb-3"><div class="card-body"><h5>{{ __('Expense') }}</h5><form method="POST" action="{{ route('central-finance.expenses.store') }}">@csrf @include('central-finance.partials.operation-form',['categories'=>$expenseCategories,'actionLabel'=>'Record Expense','payer'=>false])</form></div></div></div>
            <div class="col-lg-6"><div class="card mb-3"><div class="card-body"><h5>{{ __('Other Income') }}</h5><form method="POST" action="{{ route('central-finance.other-income.store') }}">@csrf @include('central-finance.partials.operation-form',['categories'=>$incomeCategories,'actionLabel'=>'Record Other Income','payer'=>true])</form></div></div></div>
            <div class="col-lg-6"><div class="card mb-3"><div class="card-body"><h5>{{ __('Reimbursement') }}</h5><form method="POST" action="{{ route('central-finance.reimbursements.store') }}">@csrf <select name="category_id" class="form-control mb-2" required><option value="">{{ __('Expense Category') }}</option>@foreach($expenseCategories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select><input name="amount" type="number" step="0.01" class="form-control mb-2" placeholder="{{ __('Amount') }}" required><input name="currency" class="form-control mb-2" value="MMK" required><input name="reference_no" class="form-control mb-2" placeholder="{{ __('Reference') }}"><textarea name="description" class="form-control mb-2" placeholder="{{ __('Description') }}"></textarea><button class="btn btn-theme">{{ __('Submit Reimbursement') }}</button></form></div></div></div>
            @endif
            <div class="col-lg-6"><div class="card mb-3"><div class="card-body"><h5>{{ __('Documents') }}</h5><p>{{ __('Expenses') }}: {{ $expenses->count() }} · {{ __('Other Income') }}: {{ $otherIncomes->count() }} · {{ __('Reimbursements') }}: {{ $reimbursements->count() }}</p>@foreach($reimbursements->where('status','pending') as $r)<div class="border p-2 mb-2">{{ $r->reference_no }} · {{ $r->amount }} @if($canOperate)<form class="mt-2" method="POST" action="{{ route('central-finance.reimbursements.approve',$r->id) }}">@csrf <select name="fund_account_id" required>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->account_name }}</option>@endforeach</select><input name="payment_method" value="Cash" required><input name="reason" placeholder="{{ __('Approval reason') }}" required><button class="btn btn-sm btn-theme">{{ __('Approve') }}</button></form>@endif</div>@endforeach</div></div></div>
        </div>
    @endif

    @if($page === 'accounts')
        @if($canConfigureAccounts)
            <div class="card mb-3"><div class="card-body"><h5>{{ __('Central Fund Account setup') }}</h5>
                <p class="text-muted mb-3">{{ __('Only Head Finance may create an opening balance. It is audited and does not create Money In, Operating Income, or a Ledger entry.') }}</p>
                <form method="POST" action="{{ route('central-finance.accounts.store') }}" class="row">@csrf
                    <div class="col-md-2"><input name="account_code" class="form-control" placeholder="{{ __('Account code') }}" required></div>
                    <div class="col-md-2"><input name="account_name" class="form-control" placeholder="{{ __('Account name') }}" required></div>
                    <div class="col-md-1"><input name="currency" class="form-control" value="MMK" maxlength="3" required></div>
                    <div class="col-md-2"><input name="opening_balance" type="number" step="0.0001" min="0" class="form-control" placeholder="{{ __('Opening balance') }}" required></div>
                    <div class="col-md-2"><input name="opening_balance_date" type="date" class="form-control" required></div>
                    <div class="col-md-3"><input name="opening_reason" class="form-control" placeholder="{{ __('Signed opening-balance reference / reason') }}" required></div>
                    <div class="col-12 mt-2"><label>{{ __('Authorized Central Finance users') }}</label><div class="d-flex flex-wrap">@foreach($schoolUsers as $user)<label class="mr-3"><input type="checkbox" name="authorized_user_ids[]" value="{{ $user->id }}" @checked($user->id === $actor->id)> {{ $user->first_name }} {{ $user->last_name }}</label>@endforeach</div></div>
                    <div class="col-12 mt-2"><button class="btn btn-theme">{{ __('Create audited Fund Account') }}</button></div>
                </form>
            </div></div>
            <div class="card mb-3"><div class="card-body"><h5>{{ __('Cutover state') }}</h5><p>{{ __('Current state') }}: <strong>{{ $cutoverStatus }}</strong></p>
                <form method="POST" action="{{ route('central-finance.cutover-state') }}" class="form-inline">@csrf
                    <select name="status" class="form-control mr-2"><option value="ready">ready</option><option value="central">central</option><option value="legacy">legacy</option></select><button class="btn btn-outline-primary">{{ __('Update cutover state') }}</button>
                </form>
            </div></div>
        @endif
        <div class="card"><div class="card-body"><h5>{{ __('Fund Accounts') }}</h5><table class="table"><thead><tr><th>{{ __('Account') }}</th><th>{{ __('Owner') }}</th><th>{{ __('Currency') }}</th><th>{{ __('Money In') }}</th><th>{{ __('Money Out') }}</th><th>{{ __('Balance') }}</th>@if($canConfigureAccounts)<th>{{ __('Configuration') }}</th>@endif</tr></thead><tbody>@foreach($accounts as $a)<tr><td>{{ $a->account_name }}</td><td>{{ $a->owner_type === 'hq' ? 'HQ' : $school?->name }}</td><td>{{ $a->currency }}</td><td>{{ number_format($ledger->where('fund_account_id',$a->id)->sum('money_in'),2) }}</td><td>{{ number_format($ledger->where('fund_account_id',$a->id)->sum('money_out'),2) }}</td><td>{{ number_format($a->opening_balance + $ledger->where('fund_account_id',$a->id)->sum('money_in') - $ledger->where('fund_account_id',$a->id)->sum('money_out'),2) }}</td>@if($canConfigureAccounts)<td>
            @if($a->owner_type === 'school' && $school && $a->school_id === $school->id)<details><summary>{{ __('Assignments / opening adjustment') }}</summary>
                <form method="POST" action="{{ route('central-finance.accounts.assignments',$a->id) }}" class="mt-2">@csrf @foreach($schoolUsers as $user)<label class="mr-2"><input type="checkbox" name="authorized_user_ids[]" value="{{ $user->id }}" @checked($a->authorizedUsers->contains('id',$user->id))> {{ $user->first_name }}</label>@endforeach <button class="btn btn-sm btn-outline-primary">{{ __('Save') }}</button></form>
                <form method="POST" action="{{ route('central-finance.accounts.opening-adjustments',$a->id) }}" class="mt-2">@csrf <input name="amount" type="number" step="0.0001" class="form-control mb-1" placeholder="{{ __('Signed adjustment') }}" required><input name="effective_date" type="date" class="form-control mb-1" required><input name="reason" class="form-control mb-1" placeholder="{{ __('Adjustment reason') }}" required><button class="btn btn-sm btn-outline-primary">{{ __('Record audited adjustment') }}</button></form>
            </details>@endif
        </td>@endif</tr>@endforeach</tbody></table></div></div>
    @endif

    @if(in_array($page,['transfers','handovers','funding'],true))
        <div class="card mb-3"><div class="card-body"><h5>{{ $page === 'transfers' ? __('Bank Transfer') : ($page === 'handovers' ? __('Fund Handover') : __('HQ ↔ School Funding')) }}</h5>
        @if($canOperate)
            <form method="POST" action="{{ route('central-finance.'.$page.'.store') }}" class="row">@csrf
                @if($page === 'handovers')<div class="col-md-2"><select name="receiver_user_id" class="form-control" required><option value="">{{ __('Receiver') }}</option>@foreach($schoolUsers as $u)<option value="{{ $u->id }}">{{ $u->first_name }} {{ $u->last_name }}</option>@endforeach</select></div>@endif
                <div class="col-md-2"><select name="source_account_id" class="form-control" required><option value="">{{ __('Source Account') }}</option>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->account_name }}</option>@endforeach</select></div><div class="col-md-2"><select name="destination_account_id" class="form-control" required><option value="">{{ __('Destination Account') }}</option>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->account_name }}</option>@endforeach</select></div><div class="col-md-2"><input name="amount" type="number" step="0.01" min="0.01" class="form-control" placeholder="{{ __('Amount') }}" required></div><div class="col-md-2"><input name="reference_no" class="form-control" placeholder="{{ __('Reference') }}"></div><div class="col-md-2"><button class="btn btn-theme">{{ __('Submit') }}</button></div>
            </form>
        @endif</div></div>
        @php($documents = $page === 'handovers' ? $handovers : ($page === 'funding' ? $fundingRequests : collect()))
        @if($page !== 'transfers')
            <div class="card"><div class="card-body"><table class="table"><thead><tr><th>{{ __('Reference') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Status') }}</th><th>{{ __('Action') }}</th></tr></thead><tbody>
            @foreach($documents as $document)
                <tr><td>{{ $document->reference_no }}</td><td>{{ number_format($document->amount,2) }}</td><td>{{ $document->status }}</td><td>
                @if($canOperate && $document->status === 'pending')
                    @php($routeParameter = $page === 'handovers' ? ['handover' => $document->id] : ['funding' => $document->id])
                    @foreach(['confirm'=>'Confirm','reject'=>'Reject','cancel'=>'Cancel'] as $action=>$label)
                        <form method="POST" action="{{ route('central-finance.'.$page.'.resolve', array_merge($routeParameter, ['action' => $action])) }}" class="d-inline">
                            @csrf
                            @if($action !== 'confirm')<input name="reason" value="{{ __('Workflow decision') }}" type="hidden">@endif
                            <button class="btn btn-sm btn-outline-secondary">{{ __($label) }}</button>
                        </form>
                    @endforeach
                @endif
                </td></tr>
            @endforeach
            </tbody></table></div></div>
        @endif
    @endif

    @if($page === 'ledger')
        <div class="card"><div class="card-body"><h5>{{ __('Standard Ledger') }}</h5><table class="table"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('School') }}</th><th>{{ __('Source') }}</th><th>{{ __('Fund Account') }}</th><th>{{ __('Money In') }}</th><th>{{ __('Money Out') }}</th><th>{{ __('Operating Income') }}</th><th>{{ __('Operating Expense') }}</th></tr></thead><tbody>@foreach($ledger as $entry)<tr><td>{{ $entry->entry_date }}</td><td>{{ $entry->school_id }}</td><td>{{ $entry->source_type }} · {{ $entry->reference_no }}</td><td>{{ $entry->fund_account_id }}</td><td>{{ number_format($entry->money_in,2) }}</td><td>{{ number_format($entry->money_out,2) }}</td><td>{{ number_format($entry->operating_income,2) }}</td><td>{{ number_format($entry->operating_expense,2) }}</td></tr>@endforeach</tbody></table></div></div>
    @endif
</div>
@endsection
