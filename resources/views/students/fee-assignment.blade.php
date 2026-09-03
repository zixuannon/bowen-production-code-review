@extends('layouts.master')

@section('title', __('Student Fee Setup'))

@section('content')
    <div class="content-wrapper">
        <div class="page-header d-flex justify-content-between align-items-center">
            <h3 class="page-title">{{ __('Student Fee Setup') }}</h3>
            <a class="btn btn-outline-secondary" href="{{ route('students.finance.show', $student->id) }}">{{ __('Back to Student') }}</a>
        </div>
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        <div class="card mb-3"><div class="card-body">
            <h4>{{ $student->user?->full_name }}</h4>
            <div class="text-muted">{{ __('Student Code') }}: {{ $student->studentImportIdentity?->student_code ?: '—' }} · {{ __('Gr Number') }}: {{ $student->admission_no }} · {{ __('Academic Year') }}: {{ $student->session_year_id }} · {{ __('Class') }}: {{ $student->class?->name ?? $student->class_id }}</div>
            @if($finance['available'] ?? false)<div class="mt-3 border-top pt-3"><strong>{{ __('Central Finance') }}</strong>@foreach($finance['currency_totals'] as $currency => $total)<span class="d-block small">{{ $currency }} · {{ __('Assigned / Due') }}: {{ number_format($total['due'],2) }} · {{ __('Paid') }}: {{ number_format($total['paid'],2) }} · {{ __('Outstanding') }}: {{ number_format($total['outstanding'],2) }}</span>@endforeach</div>@else<div class="alert alert-light py-2 mt-3 mb-0">{{ __('Finance synchronization pending. Payment status is not available until Central Finance has the Student profile and Receivables.') }}</div>@endif
        </div></div>

        @if($availableItems->isNotEmpty())
            <form method="POST" action="{{ route('students.fee-assignment.draft', $student->id) }}" class="card">
                @csrf
                <div class="card-body">
                    <h4>{{ __('Fee Items') }}</h4>
                    @foreach($availableItems->groupBy(fn($item) => $item->fees_type?->name ?? __('Other')) as $type => $items)
                        <h5 class="mt-4">{{ $type }}</h5>
                        @foreach($items as $item)
                            @php($checked = !$item->optional || collect(optional($draft)->items)->contains('fees_class_type_id', $item->id))
                            <div class="form-check mb-2">
                                <input class="form-check-input fee-item" type="checkbox" name="optional_fee_ids[]" value="{{ $item->id }}" data-amount="{{ $item->amount }}" {{ $checked ? 'checked' : '' }} {{ !$item->optional ? 'disabled' : '' }}>
                                @if(!$item->optional)<input type="hidden" name="compulsory_fee_ids[]" value="{{ $item->id }}">@endif
                                <label class="form-check-label">{{ $item->fee?->name }} · {{ number_format((float)$item->amount, 2) }} {{ strtoupper($item->fee_currency ?: $item->fee?->currency ?: 'MMK') }} @if(!$item->optional)<span class="badge badge-secondary">{{ __('Compulsory') }}</span>@else<span class="badge badge-info">{{ __('Optional') }}</span>@endif</label>
                            </div>
                        @endforeach
                    @endforeach
                    <hr><strong>{{ __('Preview Total Due') }}: <span id="fee-total">0.00</span></strong>
                    <button class="btn btn-primary ml-3" type="submit">{{ __('Preview Fee Assignment') }}</button>
                </div>
            </form>
        @else
            <div class="alert alert-info">{{ __('No unassigned current Fee items are available for this Student.') }}</div>
        @endif

        @if($draft)
            <div class="card mt-3"><div class="card-body"><h4>{{ __('Student Fee Assignment Review') }}</h4><p class="text-muted">{{ $student->user?->full_name }} · {{ __('Academic Year') }} {{ $student->session_year_id }}</p><ul>@foreach($draft->items as $item)<li>{{ $item->description_snapshot }} — {{ number_format($item->amount_snapshot, 2) }} {{ $item->currency_snapshot }}</li>@endforeach</ul><strong>{{ __('Total Assigned') }}: {{ number_format($draft->items->where('status','active')->sum('amount_snapshot'), 2) }}</strong><form method="POST" action="{{ route('students.fee-assignment.confirm', $student->id) }}" class="mt-3">@csrf <input type="hidden" name="assignment_uuid" value="{{ $draft->uuid }}"><button class="btn btn-success" type="submit">{{ __('Confirm Fee Assignment') }}</button></form></div></div>
        @endif

        @if($availableAdditionalItems->isNotEmpty() && $confirmedAssignments->isNotEmpty())
            <form method="POST" action="{{ route('students.fee-assignment.add-fee', $student->id) }}" class="card mt-3">@csrf<div class="card-body"><h4>{{ __('Add Student Fee') }}</h4><p class="text-muted">{{ __('Only unassigned eligible optional items for the current academic year are available.') }}</p>@foreach($availableAdditionalItems as $item)<div class="form-check mb-2"><input class="form-check-input" id="additional-fee-{{ $item->id }}" type="checkbox" name="optional_fee_ids[]" value="{{ $item->id }}"><label class="form-check-label" for="additional-fee-{{ $item->id }}">{{ $item->fee?->name }} · {{ number_format((float) $item->amount, 2) }} {{ strtoupper($item->fee_currency ?: $item->fee?->currency ?: 'MMK') }}</label></div>@endforeach<button class="btn btn-outline-primary mt-2">{{ __('Preview Additional Fee') }}</button></div></form>
        @endif

        @foreach($confirmedAssignments as $assignment)
            <div class="card mt-3"><div class="card-body"><h5>{{ __('Assigned Total') }}: {{ number_format($assignment->items->where('status','active')->sum('amount_snapshot'), 2) }}</h5>
                <div class="text-muted">{{ __('Academic Year') }} {{ $assignment->academic_year_id }} · {{ __('Confirmed') }} {{ $assignment->confirmed_at }} · {{ __('Central Finance Status') }}: {{ ($assignmentSync[$assignment->id] ?? 'pending') === 'synced' ? __('Synced') : __('Sync Pending / Requires Retry') }}</div>
                <ul class="mb-0">@foreach($assignment->items as $item)<li>{{ $item->description_snapshot }} — {{ number_format($item->amount_snapshot, 2) }} {{ $item->currency_snapshot }}</li>@endforeach</ul>
                @if(($assignmentSync[$assignment->id] ?? 'pending') === 'synced' && ($finance['profile']->id ?? null))<a class="btn btn-sm btn-outline-primary mt-2" href="{{ route('students.finance.show', $student->id) }}">{{ __('View Student Finance') }}</a>@endif
                @if(($assignmentSync[$assignment->id] ?? 'pending') === 'synced' && $canCollect && ($finance['profile']->id ?? null))<a class="btn btn-sm btn-theme mt-2" href="{{ route('central-finance.student-collection.show', $finance['profile']->id) }}">{{ __('Collect Payment Now') }}</a>@elseif(($assignmentSync[$assignment->id] ?? 'pending') === 'synced')<p class="small text-muted mt-2 mb-0">{{ __('Fees assigned successfully. Finance collection must be completed by a School Accountant or Head Finance.') }}</p>@endif
            </div></div>
        @endforeach
    </div>
@endsection

@section('script')
<script>
(() => { const refresh = () => { let total = 0; document.querySelectorAll('.fee-item:checked').forEach((el) => total += Number(el.dataset.amount || 0)); document.getElementById('fee-total').textContent = total.toFixed(2); }; document.querySelectorAll('.fee-item').forEach((el) => el.addEventListener('change', refresh)); refresh(); })();
</script>
@endsection
