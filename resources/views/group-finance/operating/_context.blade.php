<div class="card mb-3 border-primary" data-operating-school-banner>
    <div class="card-body d-flex flex-wrap align-items-center justify-content-between">
        <div>
            <strong>{{ __('Group Finance') }} · {{ __('Current Operating School') }}: {{ $workspace['school']->name }}</strong>
            <span class="badge badge-success ml-2">{{ __('Operating mode') }}</span>
            <p class="mb-0 small text-muted">{{ __('Central identity is retained. Fund Account visibility and writes follow the mapped tenant identity and existing scope.') }}</p>
        </div>
        <div class="mt-2 mt-md-0">
            <a class="btn btn-outline-primary btn-sm" href="{{ route('group-finance.show', $workspace['group']) }}">{{ __('Switch School') }}</a>
            @if($workspace['isCentralHeadFinance'])
                <a class="btn btn-outline-primary btn-sm" href="{{ route('finance-groups.transfers.index', $workspace['group']) }}">{{ __('HQ / School Funding') }}</a>
            @endif
            <form method="POST" action="{{ route('group-finance.operating.exit') }}" class="d-inline">
                @csrf
                <button class="btn btn-outline-secondary btn-sm" type="submit">{{ __('Return to All Schools') }}</button>
            </form>
        </div>
    </div>
</div>

<ul class="nav nav-tabs mb-3" data-operating-finance-nav>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('group-finance.operating.bank-accounts') ? 'active' : '' }}" href="{{ route('group-finance.operating.bank-accounts') }}">{{ __('Bank Accounts') }}</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('group-finance.operating.transactions') ? 'active' : '' }}" href="{{ route('group-finance.operating.transactions') }}">{{ __('Transactions') }}</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('group-finance.operating.reports') ? 'active' : '' }}" href="{{ route('group-finance.operating.reports') }}">{{ __('Finance Reports') }}</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('group-finance.operating.operations*') ? 'active' : '' }}" href="{{ route('group-finance.operating.operations') }}">{{ __('Finance Operations') }}</a></li>
</ul>
