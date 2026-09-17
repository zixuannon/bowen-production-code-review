<div class="cf-account-manage">
    <div class="cf-workspace-toolbar">
        <div>
            <h4 class="mb-1">{{ $accountReport->account_name }}</h4>
            <p class="text-muted mb-0">{{ $accountReport->account_code }} · {{ $accountReport->currency }} · {{ __($accountReport->status) }} · {{ __('Owner: Bowen Group / Central Finance') }}</p>
        </div>
        <div class="cf-workspace-toolbar__actions">
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('central-finance.accounts.report', $accountReport->id) }}">{{ __('Detail') }}</a>
            <a class="btn btn-sm btn-outline-primary" href="{{ route('central-finance.accounts.statements', ['fund_account_id' => $accountReport->id]) }}">{{ __('Statement') }}</a>
            <a class="btn btn-sm btn-light" href="{{ route('central-finance.accounts') }}">{{ __('Back to Fund Accounts') }}</a>
        </div>
    </div>

    <div class="card mb-3"><div class="card-body">
        <h5>{{ __('Basic details') }}</h5>
        <form method="POST" action="{{ route('central-finance.accounts.update', $accountReport->id) }}">@csrf @method('PUT')
            <div class="form-row">
                <div class="form-group col-md-6"><label>{{ __('Account name') }}</label><input name="account_name" class="form-control" value="{{ $accountReport->account_name }}" required></div>
                <div class="form-group col-md-3"><label>{{ __('Type') }}</label><select name="account_type" class="form-control">@foreach(['cash' => 'Cash', 'bank' => 'Bank', 'other' => 'Other'] as $value => $label)<option value="{{ $value }}" @selected($accountReport->account_type === $value)>{{ __($label) }}</option>@endforeach</select></div>
                <div class="form-group col-md-3"><label>{{ __('Custodian') }}</label><select name="custodian_user_id" class="form-control"><option value="">{{ __('No custodian') }}</option>@foreach($accountAssignableUsers as $user)<option value="{{ $user->id }}" @selected($accountReport->custodian_user_id === $user->id)>{{ $user->full_name }}</option>@endforeach</select></div>
                <div class="form-group col-md-6"><label>{{ __('Bank name') }}</label><input name="bank_name" class="form-control" value="{{ $accountReport->bank_name }}"></div>
                <div class="form-group col-md-6"><label>{{ __('Masked identifier only') }}</label><input name="masked_account_identifier" class="form-control" value="{{ $accountReport->masked_account_identifier }}"></div>
                <div class="form-group col-12"><label>{{ __('Notes') }}</label><textarea name="notes" class="form-control">{{ $accountReport->notes }}</textarea></div>
                <div class="form-group col-12"><label>{{ __('Change reason') }}</label><input name="reason" class="form-control" maxlength="2000"></div>
            </div>
            <button class="btn btn-theme">{{ __('Save master data') }}</button>
        </form>
        <hr>
        <form method="POST" action="{{ route('central-finance.accounts.status', $accountReport->id) }}" data-lifecycle-confirm data-lifecycle-object="{{ $accountReport->account_name }} · {{ $accountReport->account_code }}" data-lifecycle-amount="{{ number_format($accountReport->current_balance, 2) }} {{ $accountReport->currency }}" data-lifecycle-current-status="{{ __($accountReport->status) }}" data-lifecycle-result="{{ __('Account status changes; statements remain readable') }}">@csrf
            <div class="form-row align-items-end"><div class="form-group col-md-4"><label>{{ __('Lifecycle status') }}</label><select name="status" class="form-control">@foreach(['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'] as $value => $label)<option value="{{ $value }}" @selected($accountReport->status === $value)>{{ __($label) }}</option>@endforeach</select></div><div class="form-group col-md-6"><label>{{ __('Reason') }}</label><input name="reason" class="form-control" required></div><div class="form-group col-md-2"><button class="btn btn-outline-danger btn-block">{{ __('Update lifecycle') }}</button></div></div>
        </form>
    </div></div>

    @if($accountReport->authorizedUsers->isNotEmpty())
        <div class="card mb-3"><div class="card-body">
            <details><summary>{{ __('Legacy explicit account-user records') }}</summary>
                <p class="text-muted mt-3">{{ __('Compatibility and audit only. Normal Fund Account access is authorized by active School allocation, staff identity, and School/Group Finance scope.') }}</p>
                <ul class="mb-0">@foreach($accountReport->authorizedUsers as $user)<li>{{ $user->full_name }}{{ $user->email ? ' · '.$user->email : '' }}</li>@endforeach</ul>
            </details>
        </div></div>
    @endif

    <div class="card mb-3"><div class="card-body">
        <h5>{{ __('Allocated Schools') }}</h5>
        @if($accountReport->relationLoaded('schoolAllocations'))
            <form method="POST" action="{{ route('central-finance.accounts.school-allocations', $accountReport->id) }}" data-lifecycle-confirm data-lifecycle-object="{{ $accountReport->account_name }}" data-lifecycle-current-status="{{ __('School allocations') }}" data-lifecycle-result="{{ __('School availability updates immediately; it never moves Ledger history') }}">@csrf @method('PUT')
                <p class="text-muted">{{ __('School allocation grants access only. Physical balance remains account-level and School activity comes from School-scoped Ledger entries.') }}</p>
                @foreach($allocationSchools as $allocationSchool)@php($allocation = $accountReport->schoolAllocations->firstWhere('school_id', $allocationSchool->id))<div class="mb-2"><input type="hidden" name="allocations[{{ $loop->index }}][school_id]" value="{{ $allocationSchool->id }}"><label class="border rounded d-block p-2 mb-0"><input type="checkbox" name="allocations[{{ $loop->index }}][is_active]" value="1" @checked($allocation?->is_active)> {{ $allocationSchool->name }}</label></div>@endforeach
                <div class="form-group"><label>{{ __('Allocation reason') }}</label><input name="reason" class="form-control" required></div><button class="btn btn-outline-primary">{{ __('Save School allocations') }}</button>
            </form>
        @else
            <p class="text-muted mb-0">{{ __('School allocation records are unavailable; this account cannot be used for School transactions.') }}</p>
        @endif
    </div></div>

    <div class="card mb-3"><div class="card-body">
        <h5>{{ __('Account Opening Balance / Adjustment') }}</h5>
        <p class="text-muted">{{ __('Adjustments append an audited opening-balance change; they do not create Ledger income or expense.') }}</p>
        <form method="POST" action="{{ route('central-finance.accounts.opening-adjustments', $accountReport->id) }}" data-lifecycle-confirm data-lifecycle-object="{{ $accountReport->account_name }} · {{ $accountReport->account_code }}" data-lifecycle-amount="{{ number_format($accountReport->opening_balance, 2) }} {{ $accountReport->currency }}" data-lifecycle-current-status="{{ __('Audited baseline') }}" data-lifecycle-result="{{ __('New append-only opening balance audit') }}">@csrf
            <div class="form-row"><div class="form-group col-md-3"><label>{{ __('Signed adjustment') }}</label><input name="amount" type="number" step="0.0001" class="form-control" required></div><div class="form-group col-md-3"><label>{{ __('Effective date') }}</label><input name="effective_date" type="date" class="form-control" required></div><div class="form-group col-md-6"><label>{{ __('Adjustment reason') }}</label><input name="reason" class="form-control" required></div></div>
            <button class="btn btn-outline-primary">{{ __('Record audited adjustment') }}</button>
        </form>
    </div></div>

    <div class="card"><div class="card-body">
        <h5>{{ __('Audit History') }}</h5>
        <div class="table-responsive"><table class="table table-sm cf-data-table cf-mobile-card-table mb-0"><thead><tr><th>{{ __('When') }}</th><th>{{ __('Action') }}</th><th>{{ __('Actor') }}</th><th>{{ __('Reason') }}</th><th>{{ __('Before / After') }}</th></tr></thead><tbody>@forelse($accountAudits as $audit)<tr><td data-label="{{ __('When') }}">{{ $audit->created_at }}</td><td data-label="{{ __('Action') }}">{{ __($audit->action) }}</td><td data-label="{{ __('Actor') }}">#{{ $audit->actor_id }}</td><td data-label="{{ __('Reason') }}">{{ app(\App\Services\CentralFinanceLedgerPresentationService::class)->auditReason($audit->reason) }}</td><td data-label="">@include('central-finance.partials.audit-snapshot', ['audit' => $audit])</td></tr>@empty<tr><td colspan="5"><div class="cf-empty-state">{{ __('No account master-data audit entries.') }}</div></td></tr>@endforelse</tbody></table></div>
    </div></div>
</div>
