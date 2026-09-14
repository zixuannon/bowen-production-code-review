@extends('layouts.master')

@section('title', __('School Centralization Cutover'))

@section('css')
@include('central-finance.partials.foundation-styles')
<style>
    .cf-cutover-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 1rem; }
    .cf-cutover-status { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; }
    .cf-cutover-status__item { padding: .8rem; border: 1px solid var(--cf-border); border-radius: .55rem; background: #f8fafc; }
    .cf-cutover-status__item strong { display: block; margin-top: .2rem; overflow-wrap: anywhere; }
    .cf-cutover-confirm { padding: .85rem; border: 1px solid #eed6a4; border-radius: .5rem; background: #fffaf0; }
    @media (max-width: 767.98px) {
        .cf-cutover-grid, .cf-cutover-status { grid-template-columns: 1fr; }
    }
</style>
@endsection

@section('content')
<div class="content-wrapper central-finance-page">
    <x-central-finance.page-header
        :title="__('School Centralization Cutover')"
        :description="__('Set the audited receivable boundary and control each School transition. This page never creates payments, receipts, ledger entries, staff, accounts, categories, or fees.')"
        :school="$school"
        :status="$cutoverStatus"
        :eyebrow="__('Control plane')"
        :school-finance-facade="false"
    />

    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('central-finance.cutover') }}" class="form-row align-items-end cf-filter-bar mb-0">
                <div class="form-group col-md-9">
                    <label for="cutover-school">{{ __('School') }}</label>
                    <select id="cutover-school" name="school_id" class="form-control" required>
                        <option value="">{{ __('Select an authorized School') }}</option>
                        @foreach($schools as $availableSchool)
                            <option value="{{ $availableSchool->id }}" @selected($school && $school->id === $availableSchool->id)>{{ $availableSchool->name }} · {{ $availableSchool->code }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-md-3"><button class="btn btn-outline-primary btn-block">{{ __('View cutover controls') }}</button></div>
            </form>
        </div>
    </div>

    @if(!$school)
        <div class="card"><div class="card-body"><div class="cf-empty-state">{{ __('Select a School within your authorized Finance Group scope. No setting is changed by selecting a School.') }}</div></div></div>
    @else
        <div class="cf-cutover-status mb-3" aria-label="{{ __('Current cutover status') }}">
            <div class="cf-cutover-status__item"><small class="text-muted">{{ __('Current status') }}</small><strong>{{ __(ucfirst($cutoverStatus)) }}</strong></div>
            <div class="cf-cutover-status__item"><small class="text-muted">{{ __('Receivable cutoff / legacy freeze') }}</small><strong>{{ $effectiveAt?->format('Y-m-d H:i') ?: '—' }}</strong></div>
            <div class="cf-cutover-status__item"><small class="text-muted">{{ __('Effective timezone') }}</small><strong>{{ $timezone }}</strong></div>
        </div>

        @if($hasCentralWrites)
            <div class="alert alert-warning" role="alert">{{ __('Central financial history exists for this School. Returning to Legacy is blocked server-side and requires a reviewed forward-control process.') }}</div>
        @endif

        <div class="cf-cutover-grid">
            <section class="card">
                <div class="card-body">
                    <h4 class="card-title">{{ __('Receivable cutoff / legacy freeze datetime') }}</h4>
                    <p class="text-muted small">{{ __('The datetime is interpreted only in Asia/Yangon. Saving it does not execute cutover or write any financial document.') }}</p>
                    <form method="POST" action="{{ route('central-finance.cutover-receivable-effective-at') }}" data-cutover-confirm="{{ __('Confirm the audited receivable cutoff for this School?') }}">
                        @csrf
                        <input type="hidden" name="school_id" value="{{ $school->id }}">
                        <div class="form-group">
                            <label for="receivable-cutoff">{{ __('Effective date and time') }}</label>
                            <input id="receivable-cutoff" name="receivable_sync_effective_at" type="datetime-local" class="form-control" value="{{ old('receivable_sync_effective_at', $effectiveAt?->format('Y-m-d\\TH:i')) }}" required>
                            <small class="form-text text-muted">{{ $timezone }}</small>
                        </div>
                        <div class="form-group">
                            <label for="cutoff-reason">{{ __('Audit reason') }}</label>
                            <textarea id="cutoff-reason" name="receivable_sync_effective_reason" class="form-control" rows="4" maxlength="2000" required>{{ old('receivable_sync_effective_reason') }}</textarea>
                        </div>
                        @include('central-finance.partials.cutover-confirmation', ['prefix' => 'cutoff'])
                        <button class="btn btn-theme">{{ __('Save audited cutoff') }}</button>
                    </form>
                </div>
            </section>

            <section class="card">
                <div class="card-body">
                    <h4 class="card-title">{{ __('Controlled status transition') }}</h4>
                    <p class="text-muted small">{{ __('Ready validates the complete School readiness checklist. Central enables Central Finance writes. Every transition is separately confirmed and audited.') }}</p>
                    <form method="POST" action="{{ route('central-finance.cutover-state') }}" data-cutover-confirm="{{ __('Confirm this controlled Central Finance status transition?') }}">
                        @csrf
                        <input type="hidden" name="school_id" value="{{ $school->id }}">
                        <div class="form-group">
                            <label for="cutover-target-status">{{ __('Target status') }}</label>
                            <select id="cutover-target-status" name="status" class="form-control" required>
                                <option value="">{{ __('Select target status') }}</option>
                                @if($cutoverStatus === 'legacy')<option value="ready">{{ __('Ready') }}</option>@endif
                                @if($cutoverStatus === 'ready')<option value="legacy">{{ __('Legacy') }}</option><option value="central">{{ __('Central') }}</option>@endif
                                @if($cutoverStatus === 'central')<option value="legacy" @disabled($hasCentralWrites)>{{ __('Legacy') }}@if($hasCentralWrites) · {{ __('blocked') }}@endif</option>@endif
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="transition-reason">{{ __('Audit reason') }}</label>
                            <textarea id="transition-reason" name="reason" class="form-control" rows="4" maxlength="2000" required>{{ old('reason') }}</textarea>
                        </div>
                        @include('central-finance.partials.cutover-confirmation', ['prefix' => 'status'])
                        <button class="btn btn-outline-primary">{{ __('Submit controlled transition') }}</button>
                    </form>
                </div>
            </section>
        </div>

        <section class="card mt-3">
            <div class="card-body">
                <h4 class="card-title">{{ __('Cutover audit history') }}</h4>
                <div class="table-responsive">
                    <table class="table cf-data-table cf-mobile-card-table mb-0">
                        <thead><tr><th>{{ __('Time') }}</th><th>{{ __('Action') }}</th><th>{{ __('Actor') }}</th><th>{{ __('Reason') }}</th></tr></thead>
                        <tbody>
                        @forelse($audits as $audit)
                            <tr>
                                <td data-label="{{ __('Time') }}">{{ $audit->created_at?->timezone($timezone)->format('Y-m-d H:i') }}</td>
                                <td data-label="{{ __('Action') }}">{{ __($audit->action) }}</td>
                                <td data-label="{{ __('Actor') }}">{{ $auditActors->get($audit->actor_id)?->full_name ?: ('#'.$audit->actor_id) }}</td>
                                <td data-label="{{ __('Reason') }}" class="cf-break-anywhere">{{ $audit->reason ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><div class="cf-empty-state">{{ __('No cutover changes have been recorded for this School.') }}</div></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endif
</div>
@endsection

@section('js')
<script>
document.addEventListener('submit', function (event) {
    const form = event.target.closest('form[data-cutover-confirm]');
    if (!form || window.confirm(form.dataset.cutoverConfirm)) return;
    event.preventDefault();
});
</script>
@endsection
