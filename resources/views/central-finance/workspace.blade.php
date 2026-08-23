@extends('layouts.master')

@section('title', __('Central Finance'))

@section('css')
<style>
    .central-finance-context { border: 1px solid #e4e7ed; border-radius: .45rem; background: #fff; padding: .65rem .85rem; }
    .central-finance-context .form-control { min-width: 190px; }
    .central-finance-nav { position: sticky; top: 80px; }
    .central-finance-nav .nav-link { color: #4b5563; padding: .52rem .7rem; border-radius: .35rem; }
    .central-finance-nav .nav-link.active { background: #1f5f87; color: #fff; }
    .central-finance-nav .nav-label { color: #8a94a6; font-size: .72rem; font-weight: 700; letter-spacing: .04em; margin: .9rem .7rem .3rem; text-transform: uppercase; }
    .central-finance-form-card { max-width: 760px; }
    .central-finance-form-card .form-control { min-height: 38px; }
    @media (max-width: 991.98px) { .central-finance-nav { position: static; margin-bottom: 1rem; } .central-finance-nav .nav { flex-direction: row !important; flex-wrap: wrap; } }
    @media (max-width: 575.98px) { .central-finance-context .form-control { min-width: 0; width: 100%; } }
</style>
@endsection

@section('content')
@php($operation = in_array(request('operation'), ['expense', 'income', 'reimbursement'], true) ? request('operation') : 'expense')
@php($showWriteForm = !$school || $canOperate)
@php($writeDisabled = !$school)
@php($writeAvailabilityMessage = __('Select an authorized School before recording a Central Finance transaction.'))
<div class="content-wrapper">
    <h1 class="sr-only">{{ __('Central Finance') }}</h1>
    <div class="central-finance-context d-flex flex-wrap align-items-center justify-content-between mb-3">
        <div class="mr-3 mb-2 mb-md-0">
            <small class="text-muted d-block">{{ __('Central Finance') }}</small>
            <strong>@if($school)<span class="sr-only">{{ __('Operating School:') }}</span>{{ __('当前操作校区：').$school->name }}@else{{ __('All Schools · Read-only') }}@endif</strong>
        </div>
        <div class="d-flex flex-wrap align-items-center">
            <form method="POST" action="{{ route('central-finance.school.enter') }}" class="mr-2 mb-1">@csrf
                <select name="school_id" class="form-control form-control-sm" onchange="this.form.submit()" aria-label="{{ __('Switch School') }}">
                    <option value="">{{ __('切换校区') }}</option>
                    @foreach($schools as $availableSchool)<option value="{{ $availableSchool->id }}" @selected($school && $school->id === $availableSchool->id)>{{ $availableSchool->name }}</option>@endforeach
                </select>
            </form>
            @if($school)<form method="POST" action="{{ route('central-finance.school.exit') }}" class="mb-1">@csrf<button class="btn btn-sm btn-outline-secondary">{{ __('返回全部校区') }}</button></form>@endif
        </div>
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if(!$school)<div class="alert alert-info">{{ __('All Schools shows consolidated Central Finance only. Select an authorized School to create or resolve financial documents.') }}</div>@endif
    @if($school && !$canOperate)<div class="alert alert-warning">{{ $cutoverStatus !== 'central' ? __('This School remains in legacy Finance until its approved Central cutover. Central Finance is read-only.') : __('You can view this School, but you do not have Central Finance operating authority.') }}</div>@endif

    <div class="row">
        <aside class="col-lg-3 col-xl-2">
            <div class="card"><div class="card-body p-2 central-finance-nav">
                <nav class="nav nav-pills flex-column" aria-label="{{ __('Central Finance navigation') }}">
                    <span class="nav-label">{{ __('财务总览') }}</span>
                    <a class="nav-link {{ $page === 'dashboard' ? 'active' : '' }}" href="{{ route('central-finance.dashboard') }}">{{ __('财务总览') }}</a>
                    <span class="nav-label">{{ __('收支管理') }}</span>
                    <a class="nav-link {{ $page === 'receivables' ? 'active' : '' }}" href="{{ route('central-finance.receivables') }}">{{ __('学生收费') }}</a>
                    <a class="nav-link {{ $page === 'operating' && $operation === 'income' ? 'active' : '' }}" href="{{ route('central-finance.operations', ['operation' => 'income']) }}">{{ __('其他收入') }}</a>
                    <a class="nav-link {{ $page === 'operating' && $operation === 'expense' ? 'active' : '' }}" href="{{ route('central-finance.operations', ['operation' => 'expense']) }}">{{ __('支出管理') }}</a>
                    <a class="nav-link {{ $page === 'operating' && $operation === 'reimbursement' ? 'active' : '' }}" href="{{ route('central-finance.operations', ['operation' => 'reimbursement']) }}">{{ __('报销申请') }}</a>
                    <span class="nav-label">{{ __('资金管理') }}</span>
                    <a class="nav-link {{ $page === 'accounts' ? 'active' : '' }}" href="{{ route('central-finance.accounts') }}">{{ __('银行账户') }}</a>
                    <a class="nav-link {{ $page === 'transfers' ? 'active' : '' }}" href="{{ route('central-finance.transfers') }}">{{ __('银行转账') }}</a>
                    <a class="nav-link {{ $page === 'handovers' ? 'active' : '' }}" href="{{ route('central-finance.handovers') }}">{{ __('资金交接') }}</a>
                    <a class="nav-link {{ $page === 'funding' ? 'active' : '' }}" href="{{ route('central-finance.funding') }}">{{ __('总部拨款') }}</a>
                    <span class="nav-label">{{ __('财务报表') }}</span>
                    <a class="nav-link {{ $page === 'reports' ? 'active' : '' }}" href="{{ route('central-finance.reports') }}">{{ __('财务报表') }}</a>
                    <a class="nav-link {{ $page === 'ledger' ? 'active' : '' }}" href="{{ route('central-finance.ledger') }}">{{ __('Standard Ledger') }}</a>
                    @if($canConfigureAccounts)<span class="nav-label">{{ __('Central Finance 设置') }}</span><a class="nav-link {{ $page === 'accounts' ? 'active' : '' }}" href="{{ route('central-finance.accounts') }}#central-finance-setup">{{ __('账户与开账设置') }}</a>@endif
                </nav>
            </div></div>
        </aside>

        <main class="col-lg-9 col-xl-10">
            @if(in_array($page, ['dashboard', 'reports'], true))
                <div class="row">
                    @foreach(['money_in'=>'Money In','money_out'=>'Money Out','operating_income'=>'Operating Income','operating_expense'=>'Operating Expense','operating_net'=>'Operating Net'] as $key=>$label)
                        <div class="col-sm-6 col-xl"><div class="card mb-3"><div class="card-body"><small class="text-muted">{{ __($label) }}</small><h4 class="mb-0">{{ number_format($totals[$key], 2) }}</h4></div></div></div>
                    @endforeach
                </div>
                <div class="card"><div class="card-body"><h5>{{ $page === 'reports' ? __('财务报表') : __('财务总览') }}</h5><p class="text-muted mb-0">{{ __('Totals are derived exclusively from the append-only Central Standard Ledger. Internal transfers remain excluded from operating results.') }}</p></div></div>
            @endif

            @if($page === 'receivables')
                <div class="card central-finance-form-card mb-3"><div class="card-body"><h5>{{ __('学生收费') }}</h5><p class="text-muted">{{ __('Student identity is read only from Central Student Financial Profiles synchronized from the School tenant.') }}</p>
                    @if($showWriteForm)
                        @if($writeDisabled)<p class="text-muted mb-3">{{ $writeAvailabilityMessage }}</p>@endif
                        <form method="POST" action="{{ route('central-finance.payments.store') }}">@csrf<fieldset @disabled($writeDisabled)>
                        <div class="form-group"><label>{{ __('Receivable') }}</label><select name="receivable_id" class="form-control" required><option value="">{{ __('Receivable') }}</option>@foreach($receivables as $r)<option value="{{ $r->id }}">{{ $r->description }} · {{ $r->amount_due - $r->amount_paid }}</option>@endforeach</select></div>
                        <div class="form-group"><label>{{ __('Fund Account') }}</label><select name="fund_account_id" class="form-control" required><option value="">{{ __('Fund Account') }}</option>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->account_name }}</option>@endforeach</select></div>
                        <div class="form-row"><div class="form-group col-md-4"><label>{{ __('Amount') }}</label><input name="amount" type="number" step="0.01" min="0.01" class="form-control" required></div><div class="form-group col-md-4"><label>{{ __('Payment method') }}</label><input name="payment_method" class="form-control" value="Cash" required></div><div class="form-group col-md-4"><label>{{ __('Reference') }}</label><input name="payment_reference" class="form-control"></div></div>
                        <button class="btn btn-theme">{{ __('Collect') }}</button>
                        </fieldset></form>
                    @endif
                </div></div>
                <div class="card"><div class="card-body"><h5>{{ __('Receivables') }}</h5><div class="table-responsive"><table class="table mb-0"><thead><tr><th>{{ __('Student') }}</th><th>{{ __('Description') }}</th><th>{{ __('Due') }}</th><th>{{ __('Paid') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>@foreach($receivables as $r)<tr><td>{{ optional($profiles->firstWhere('id',$r->student_profile_id))->student_name }}</td><td>{{ $r->description }}</td><td>{{ number_format($r->amount_due,2) }}</td><td>{{ number_format($r->amount_paid,2) }}</td><td>{{ $r->status }}</td></tr>@endforeach</tbody></table></div></div></div>
            @endif

            @if($page === 'operating')
                @if($operation === 'expense')
                    <div class="card central-finance-form-card"><div class="card-body"><h5>{{ __('支出管理') }}</h5>@if($showWriteForm)@if($writeDisabled)<p class="text-muted mb-3">{{ $writeAvailabilityMessage }}</p>@endif<form method="POST" action="{{ route('central-finance.expenses.store') }}">@csrf<fieldset @disabled($writeDisabled)>@include('central-finance.partials.operation-form',['categories'=>$expenseCategories,'actionLabel'=>'Record Expense','payer'=>false])</fieldset></form>@endif</div></div>
                @elseif($operation === 'income')
                    <div class="card central-finance-form-card"><div class="card-body"><h5>{{ __('其他收入') }}</h5>@if($showWriteForm)@if($writeDisabled)<p class="text-muted mb-3">{{ $writeAvailabilityMessage }}</p>@endif<form method="POST" action="{{ route('central-finance.other-income.store') }}">@csrf<fieldset @disabled($writeDisabled)>@include('central-finance.partials.operation-form',['categories'=>$incomeCategories,'actionLabel'=>'Record Other Income','payer'=>true])</fieldset></form>@endif</div></div>
                @else
                    <div class="card central-finance-form-card"><div class="card-body"><h5>{{ __('报销申请') }}</h5>@if($showWriteForm)@if($writeDisabled)<p class="text-muted mb-3">{{ $writeAvailabilityMessage }}</p>@endif<form method="POST" action="{{ route('central-finance.reimbursements.store') }}">@csrf<fieldset @disabled($writeDisabled)>
                        <div class="form-group"><select name="category_id" class="form-control" required><option value="">{{ __('Expense Category') }}</option>@foreach($expenseCategories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div><div class="form-group"><input name="amount" type="number" step="0.01" class="form-control" placeholder="{{ __('Amount') }}" required></div><div class="form-group"><input name="currency" class="form-control" value="MMK" required></div><div class="form-group"><input name="reference_no" class="form-control" placeholder="{{ __('Reference') }}"></div><div class="form-group"><textarea name="description" class="form-control" placeholder="{{ __('Description') }}"></textarea></div><button class="btn btn-theme">{{ __('Submit Reimbursement') }}</button>
                    </fieldset></form>@endif</div></div>
                    <div class="card mt-3"><div class="card-body"><h5>{{ __('Pending reimbursements') }}</h5>@foreach($reimbursements->where('status','pending') as $r)<div class="border rounded p-2 mb-2">{{ $r->reference_no }} · {{ number_format($r->amount,2) }} @if($canOperate)<form class="mt-2" method="POST" action="{{ route('central-finance.reimbursements.approve',$r->id) }}">@csrf <div class="form-row"><div class="col-md-4"><select name="fund_account_id" class="form-control" required>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->account_name }}</option>@endforeach</select></div><div class="col-md-3"><input name="payment_method" class="form-control" value="Cash" required></div><div class="col-md-3"><input name="reason" class="form-control" placeholder="{{ __('Approval reason') }}" required></div><div class="col-md-2"><button class="btn btn-outline-primary">{{ __('Approve') }}</button></div></div></form>@endif</div>@endforeach</div></div>
                @endif
            @endif

            @if($page === 'accounts')
                @if($canConfigureAccounts)<div class="card central-finance-form-card mb-3" id="central-finance-setup"><div class="card-body"><h5>{{ __('Central Finance 设置') }}</h5><p class="text-muted">{{ __('Only Head Finance may create an opening balance. It is audited and does not create Money In, Operating Income, or a Ledger entry.') }}</p><form method="POST" action="{{ route('central-finance.accounts.store') }}">@csrf
                    <div class="form-row"><div class="form-group col-md-4"><input name="account_code" class="form-control" placeholder="{{ __('Account code') }}" required></div><div class="form-group col-md-4"><input name="account_name" class="form-control" placeholder="{{ __('Account name') }}" required></div><div class="form-group col-md-4"><select name="owner_type" class="form-control" required><option value="school">{{ __('Current School') }}</option><option value="hq">{{ __('HQ') }}</option></select></div><div class="form-group col-md-4"><input name="currency" class="form-control" value="MMK" maxlength="3" required></div><div class="form-group col-md-4"><input name="opening_balance" type="number" step="0.0001" min="0" class="form-control" placeholder="{{ __('Opening balance') }}" required></div><div class="form-group col-md-4"><input name="opening_balance_date" type="date" class="form-control" required></div></div><div class="form-group"><input name="opening_reason" class="form-control" placeholder="{{ __('Signed opening-balance reference / reason') }}" required></div><div class="form-group"><label>{{ __('Authorized Central Finance users') }}</label><div class="d-flex flex-wrap">@foreach($schoolUsers as $user)<label class="mr-3"><input type="checkbox" name="authorized_user_ids[]" value="{{ $user->id }}" @checked($user->id === $actor->id)> {{ $user->first_name }} {{ $user->last_name }}</label>@endforeach</div></div><button class="btn btn-theme">{{ __('Create audited Fund Account') }}</button></form></div></div>
                <details class="card mb-3"><summary class="card-body font-weight-bold">{{ __('Cutover state') }} · {{ $cutoverStatus }}</summary><div class="card-body border-top"><form method="POST" action="{{ route('central-finance.cutover-state') }}" class="form-inline">@csrf <select name="status" class="form-control mr-2"><option value="ready">ready</option><option value="central">central</option><option value="legacy">legacy</option></select><button class="btn btn-outline-primary">{{ __('Update cutover state') }}</button></form></div></details>@endif
                <div class="card"><div class="card-body"><h5>{{ __('银行账户') }}</h5><div class="table-responsive"><table class="table mb-0"><thead><tr><th>{{ __('Account') }}</th><th>{{ __('Owner') }}</th><th>{{ __('Currency') }}</th><th>{{ __('Money In') }}</th><th>{{ __('Money Out') }}</th><th>{{ __('Balance') }}</th>@if($canConfigureAccounts)<th>{{ __('Configuration') }}</th>@endif</tr></thead><tbody>@foreach($accounts as $a)<tr><td>{{ $a->account_name }}</td><td>{{ $a->owner_type === 'hq' ? 'HQ' : $school?->name }}</td><td>{{ $a->currency }}</td><td>{{ number_format($ledger->where('fund_account_id',$a->id)->sum('money_in'),2) }}</td><td>{{ number_format($ledger->where('fund_account_id',$a->id)->sum('money_out'),2) }}</td><td>{{ number_format($a->opening_balance + $ledger->where('fund_account_id',$a->id)->sum('money_in') - $ledger->where('fund_account_id',$a->id)->sum('money_out'),2) }}</td>@if($canConfigureAccounts)<td>@if(($a->owner_type === 'school' && $school && $a->school_id === $school->id) || ($a->owner_type === 'hq' && $a->school_id === null))<details><summary>{{ __('Manage') }}</summary><form method="POST" action="{{ route('central-finance.accounts.assignments',$a->id) }}" class="mt-2">@csrf @foreach($schoolUsers as $user)<label class="mr-2"><input type="checkbox" name="authorized_user_ids[]" value="{{ $user->id }}" @checked($a->authorizedUsers->contains('id',$user->id))> {{ $user->first_name }}</label>@endforeach <button class="btn btn-sm btn-outline-primary">{{ __('Save') }}</button></form><form method="POST" action="{{ route('central-finance.accounts.opening-adjustments',$a->id) }}" class="mt-2">@csrf <input name="amount" type="number" step="0.0001" class="form-control mb-1" placeholder="{{ __('Signed adjustment') }}" required><input name="effective_date" type="date" class="form-control mb-1" required><input name="reason" class="form-control mb-1" placeholder="{{ __('Adjustment reason') }}" required><button class="btn btn-sm btn-outline-primary">{{ __('Record audited adjustment') }}</button></form></details>@endif</td>@endif</tr>@endforeach</tbody></table></div></div></div>
            @endif

            @if(in_array($page,['transfers','handovers','funding'],true))
                @php($heading = $page === 'transfers' ? __('银行转账') : ($page === 'handovers' ? __('资金交接') : __('总部拨款')))
                <div class="card central-finance-form-card mb-3"><div class="card-body"><h5>{{ $heading }}</h5>@if($showWriteForm)@if($writeDisabled)<p class="text-muted mb-3">{{ $writeAvailabilityMessage }}</p>@endif<form method="POST" action="{{ route('central-finance.'.$page.'.store') }}">@csrf<fieldset @disabled($writeDisabled)>
                    @if($page === 'handovers')<div class="form-group"><label>{{ __('Receiver') }}</label><select name="receiver_user_id" class="form-control" required><option value="">{{ __('Receiver') }}</option>@foreach($schoolUsers as $u)<option value="{{ $u->id }}">{{ $u->first_name }} {{ $u->last_name }}</option>@endforeach</select></div>@endif
                    <div class="form-group"><label>{{ __('Source Account') }}</label><select name="source_account_id" class="form-control" required><option value="">{{ __('Source Account') }}</option>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->account_name }}</option>@endforeach</select></div><div class="form-group"><label>{{ __('Destination Account') }}</label><select name="destination_account_id" class="form-control" required><option value="">{{ __('Destination Account') }}</option>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->account_name }}</option>@endforeach</select></div><div class="form-row"><div class="form-group col-md-6"><input name="amount" type="number" step="0.01" min="0.01" class="form-control" placeholder="{{ __('Amount') }}" required></div><div class="form-group col-md-6"><input name="reference_no" class="form-control" placeholder="{{ __('Reference') }}"></div></div><button class="btn btn-theme">{{ __('Submit') }}</button>
                </fieldset></form>@endif</div></div>
                @if($page !== 'transfers')
                    @php($documents = $page === 'handovers' ? $handovers : $fundingRequests)
                    <div class="card"><div class="card-body"><h5>{{ __('Recent requests') }}</h5><div class="table-responsive"><table class="table mb-0"><thead><tr><th>{{ __('Reference') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Status') }}</th><th>{{ __('Action') }}</th></tr></thead><tbody>
                        @foreach($documents as $document)
                            <tr><td>{{ $document->reference_no }}</td><td>{{ number_format($document->amount,2) }}</td><td>{{ $document->status }}</td><td>
                                @if($canOperate && $document->status === 'pending')
                                    @php($routeParameter = $page === 'handovers' ? ['handover' => $document->id] : ['funding' => $document->id])
                                    @foreach(['confirm'=>'Confirm','reject'=>'Reject','cancel'=>'Cancel'] as $action=>$label)
                                        <form method="POST" action="{{ route('central-finance.'.$page.'.resolve', array_merge($routeParameter, ['action' => $action])) }}" class="d-inline">@csrf
                                            @if($action !== 'confirm')<input name="reason" value="{{ __('Workflow decision') }}" type="hidden">@endif
                                            <button class="btn btn-sm btn-outline-secondary">{{ __($label) }}</button>
                                        </form>
                                    @endforeach
                                @endif
                            </td></tr>
                        @endforeach
                    </tbody></table></div></div></div>
                @endif
            @endif

            @if($page === 'ledger')<div class="card"><div class="card-body"><h5>{{ __('Standard Ledger') }}</h5><div class="table-responsive"><table class="table mb-0"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('School') }}</th><th>{{ __('Source') }}</th><th>{{ __('Fund Account') }}</th><th>{{ __('Money In') }}</th><th>{{ __('Money Out') }}</th><th>{{ __('Operating Income') }}</th><th>{{ __('Operating Expense') }}</th></tr></thead><tbody>@foreach($ledger as $entry)<tr><td>{{ $entry->entry_date }}</td><td>{{ $entry->school_id }}</td><td>{{ $entry->source_type }} · {{ $entry->reference_no }}</td><td>{{ $entry->fund_account_id }}</td><td>{{ number_format($entry->money_in,2) }}</td><td>{{ number_format($entry->money_out,2) }}</td><td>{{ number_format($entry->operating_income,2) }}</td><td>{{ number_format($entry->operating_expense,2) }}</td></tr>@endforeach</tbody></table></div></div></div>@endif
        </main>
    </div>
</div>
@endsection
