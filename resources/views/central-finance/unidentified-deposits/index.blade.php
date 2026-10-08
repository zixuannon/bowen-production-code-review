@extends('layouts.master')
@section('title', __('Unidentified Deposits'))
@section('content')
<div class="container-fluid py-3">
    <div class="card mb-3"><div class="card-body">
        <h3>{{ __('Unidentified Deposits') }}</h3>
        <p class="text-muted">{{ __('Record real Group-held money without assigning a School, Student, or operating income. Matching later settles a receivable without a second physical balance movement.') }}</p>
        @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
        @if($errors->any())<div class="alert alert-danger" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
        @if($qaRun)
            <div class="alert alert-warning">QA Run #{{ $qaRun->run_number }} · {{ $qaRun->school?->name }} · {{ ucfirst($qaRun->status) }}. This view contains only deposits linked to this Run.</div>
            <a class="btn btn-sm btn-outline-secondary mb-3" href="{{ route('central-finance.qa-runs.show', $qaRun->id) }}">Back to QA Run</a>
        @else
        <form method="GET" class="mb-3">
            <label><input type="checkbox" name="include_qa_test" value="1" @checked($includeQaTest)> {{ __('Include QA/Test') }}</label>
            <button class="btn btn-sm btn-outline-secondary">{{ __('Apply') }}</button>
        </form>
        @endif
        @if(!$qaRun)
        <form method="POST" action="{{ route('central-finance.unidentified-deposits.store') }}">
            @csrf
            <input type="hidden" name="idempotency_reference" value="{{ old('idempotency_reference', (string) \Illuminate\Support\Str::uuid()) }}">
            <div class="form-row">
                <div class="col-md-5 form-group"><label for="deposit-account">{{ __('Group Fund Account') }}</label>
                    <select id="deposit-account" class="form-control" name="fund_account_id" required><option value="">{{ __('Select account') }}</option>
                        @foreach($accounts as $account)<option value="{{ $account->id }}" @selected((string) old('fund_account_id') === (string) $account->id)>{{ $account->account_code }} · {{ $account->account_name }} · {{ $account->currency }}{{ $account->is_qa_test ? ' · QA/Test' : '' }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-3 form-group"><label for="deposit-amount">{{ __('Amount') }}</label><input id="deposit-amount" class="form-control" name="amount" inputmode="decimal" value="{{ old('amount') }}" required></div>
                <div class="col-md-4 form-group"><label for="deposit-date">{{ __('Bank Transaction Date') }}</label><input id="deposit-date" class="form-control" name="received_date" type="date" value="{{ old('received_date', now('Asia/Yangon')->format('Y-m-d')) }}" required></div>
            </div>
            <div class="form-row">
                <div class="col-md-4 form-group"><label for="deposit-reference">{{ __('Bank reference') }}</label><input id="deposit-reference" class="form-control" name="bank_reference" maxlength="100" value="{{ old('bank_reference') }}"></div>
                <div class="col-md-4 form-group"><label for="deposit-payer">{{ __('Known payer') }}</label><input id="deposit-payer" class="form-control" name="known_payer" maxlength="191" value="{{ old('known_payer') }}"></div>
                <div class="col-md-4 form-group"><label for="deposit-description">{{ __('Description') }}</label><input id="deposit-description" class="form-control" name="description" maxlength="2000" value="{{ old('description') }}"></div>
            </div>
            <details class="mb-3" @if(old('manual_identity') || old('manual_reason')) open @endif>
                <summary>{{ __('Bank reference unavailable') }}</summary>
                <p class="text-muted mt-2">{{ __('Provide a stable alternative bank transaction identity and a verification reason. Reuse this identity wherever the same bank transaction is recorded.') }}</p>
                <div class="form-row">
                    <div class="col-md-6 form-group"><label for="deposit-manual-identity">{{ __('Alternative transaction identity') }}</label><input id="deposit-manual-identity" class="form-control" name="manual_identity" maxlength="100" value="{{ old('manual_identity') }}"></div>
                    <div class="col-md-6 form-group"><label for="deposit-manual-reason">{{ __('Manual verification reason') }}</label><input id="deposit-manual-reason" class="form-control" name="manual_reason" maxlength="2000" value="{{ old('manual_reason') }}"></div>
                </div>
            </details>
            <button class="btn cf-primary-action">{{ __('Record Unidentified Deposit') }}</button>
        </form>
        @endif
    </div></div>
    <div class="card"><div class="card-body">
        <h5>{{ __('Recorded deposits') }}</h5>
        <div class="table-responsive"><table class="table"><thead><tr>
            <th>{{ __('Date') }}</th><th>{{ __('Account') }}</th><th>{{ __('Reference') }}</th><th>{{ __('Known payer') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Currency') }}</th><th>{{ __('Allocated') }}</th><th>{{ __('Remaining') }}</th><th>{{ __('Status') }}</th><th>{{ __('Allocate') }}</th>
        </tr></thead><tbody>
        @forelse($deposits as $deposit)
            @php
                $allocated = $deposit->allocations->reduce(fn ($sum, $line) => \App\Support\CentralFinanceDecimal::add($sum, (string) $line->amount), '0.0000');
                $remaining = \App\Support\CentralFinanceDecimal::subtract((string) $deposit->amount, $allocated);
            @endphp
            <tr data-deposit-id="{{ $deposit->id }}">
                <td>{{ $deposit->received_date?->format('Y-m-d') }}<small class="d-block text-muted">{{ __('Recorded') }} {{ $deposit->created_at?->format('Y-m-d H:i') }}</small></td><td>{{ $deposit->fundAccount?->account_name }}</td><td>{{ $deposit->bank_reference ?: $deposit->manual_identity ?: '—' }}</td><td>{{ $deposit->known_payer ?: '—' }}</td>
                <td>{{ number_format($deposit->amount, 2) }}</td><td>{{ $deposit->currency }}</td><td>{{ number_format($allocated, 2) }}</td><td>{{ number_format($remaining, 2) }}</td><td>{{ __($deposit->status) }}@if($deposit->is_qa_test)<span class="badge badge-warning d-block">QA/Test</span>@endif</td>
                <td>
                    @foreach($deposit->allocations as $allocation)
                        @if($allocation->payment?->receipt)<a class="d-block" href="{{ route('central-finance.payments.receipt', $allocation->payment_id) }}">{{ $allocation->payment->receipt->receipt_no }}</a>@endif
                    @endforeach
                    @if(in_array($deposit->status, ['unidentified', 'partially_applied'], true) && (!$qaRun || $qaRun->status === 'active'))
                        <a href="#deposit-allocation-{{ $deposit->id }}" data-deposit-open="deposit-allocation-{{ $deposit->id }}">{{ __('Allocate to receivable') }}</a>
                    @elseif($qaRun && $qaRun->status !== 'active')
                        <span class="text-muted">Run is read-only</span>
                    @endif
                </td>
            </tr>
        @empty<tr><td colspan="10" class="text-muted">{{ __('No Unidentified Deposits.') }}</td></tr>@endforelse
        </tbody></table></div>
        @foreach($deposits as $deposit)
            @if(in_array($deposit->status, ['unidentified', 'partially_applied'], true) && (!$qaRun || $qaRun->status === 'active'))
                    <details id="deposit-allocation-{{ $deposit->id }}" class="border rounded p-3 mt-3">
                        <summary class="cf-break-anywhere">{{ __('Allocate to receivable') }} · {{ $deposit->bank_reference ?: $deposit->manual_identity ?: $deposit->deposit_uuid }} · {{ $deposit->fundAccount?->account_name }}</summary>
                        <form method="POST" action="{{ route('central-finance.unidentified-deposits.match', $deposit) }}" class="mt-2 deposit-allocation-form" data-search-url="{{ route('central-finance.unidentified-deposits.receivables', $deposit) }}">
                            @csrf
                            <input type="hidden" name="idempotency_reference" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <div class="form-row align-items-end">
                                <div class="col-md-4 form-group">
                                    <label for="deposit-school-{{ $deposit->id }}">{{ __('School') }}</label>
                                    <select id="deposit-school-{{ $deposit->id }}" class="form-control deposit-school" name="school_id" required><option value="">{{ __('Select School') }}</option>@foreach($schools as $school)<option value="{{ $school->id }}">{{ $school->name }}{{ $school->is_qa_test ? ' · QA/Test' : '' }}</option>@endforeach</select>
                                </div>
                                <div class="col-md-6 form-group">
                                    <label for="deposit-student-{{ $deposit->id }}">{{ __('Student Code / GR / Student Name') }}</label>
                                    <input id="deposit-student-{{ $deposit->id }}" class="form-control deposit-student" maxlength="191" autocomplete="off">
                                </div>
                                <div class="col-md-2 form-group"><button type="button" class="btn btn-outline-secondary deposit-search">{{ __('Search') }}</button></div>
                            </div>
                            <div class="deposit-search-status small mb-2" role="status" aria-live="polite"></div>
                            <div class="form-row">
                                <div class="col-md-8 form-group">
                                    <label for="deposit-receivable-{{ $deposit->id }}">{{ __('Open Receivable') }}</label>
                                    <select id="deposit-receivable-{{ $deposit->id }}" class="form-control deposit-receivable" name="receivable_id" required disabled><option value="">{{ __('Search for a Student first') }}</option></select>
                                </div>
                                <div class="col-md-4 form-group"><label for="deposit-match-amount-{{ $deposit->id }}">{{ __('Allocation amount') }}</label><input id="deposit-match-amount-{{ $deposit->id }}" class="form-control" name="amount" inputmode="decimal" required></div>
                            </div>
                            <div class="form-group"><label for="deposit-reason-{{ $deposit->id }}">{{ __('Matching reason') }}</label><input id="deposit-reason-{{ $deposit->id }}" class="form-control" name="reason" maxlength="2000" required></div>
                            <button class="btn cf-primary-action deposit-submit text-wrap" disabled>{{ __('Allocate without another bank receipt') }}</button>
                        </form>
                    </details>
            @endif
        @endforeach
        @if(method_exists($deposits, 'links')){{ $deposits->links() }}@endif
    </div></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-deposit-open]').forEach(link => {
        link.addEventListener('click', () => { document.getElementById(link.dataset.depositOpen).open = true; });
    });
    document.querySelectorAll('.deposit-allocation-form').forEach(form => {
        const school = form.querySelector('.deposit-school');
        const student = form.querySelector('.deposit-student');
        const receivable = form.querySelector('.deposit-receivable');
        const search = form.querySelector('.deposit-search');
        const submit = form.querySelector('.deposit-submit');
        const status = form.querySelector('.deposit-search-status');
        let revision = 0;
        const clear = () => { revision++; receivable.replaceChildren(new Option(@json(__('Search for a Student first')), '')); receivable.disabled = true; submit.disabled = true; status.textContent = ''; };
        school.addEventListener('change', clear);
        student.addEventListener('input', clear);
        receivable.addEventListener('change', () => { submit.disabled = !receivable.value; });
        search.addEventListener('click', async () => {
            clear();
            if (!school.value || !student.value.trim()) { status.textContent = @json(__('Select a School and enter a Student identity.')); return; }
            const searchRevision = revision;
            search.disabled = true;
            try {
                const url = new URL(form.dataset.searchUrl, window.location.origin);
                url.searchParams.set('school_id', school.value);
                url.searchParams.set('student', student.value.trim());
                const response = await fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
                if (!response.ok) throw new Error('lookup');
                const data = await response.json();
                if (revision !== searchRevision) return;
                receivable.replaceChildren(new Option(@json(__('Select Receivable')), ''));
                data.receivables.forEach(row => receivable.add(new Option(`${row.student} · ${row.student_code || row.gr || '—'} · ${row.description} · ${row.available} ${row.currency}`, row.id)));
                receivable.disabled = !data.receivables.length;
                status.textContent = data.receivables.length ? @json(__('Available amounts exclude pending reservations.')) : @json(__('No eligible open receivables found.'));
            } catch (error) {
                if (revision === searchRevision) status.textContent = {{ \Illuminate\Support\Js::from(__('Search unavailable. Check the School, account scope and cutover.')) }};
            } finally { search.disabled = false; }
        });
        form.addEventListener('submit', () => { submit.disabled = true; });
    });
});
</script>
@endsection
