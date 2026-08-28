@extends('layouts.master')

@section('title', __('Student Fee Setup'))

@section('content')
    <div class="content-wrapper">
        <div class="page-header d-flex justify-content-between align-items-center">
            <h3 class="page-title">{{ __('Student Fee Setup') }}</h3>
            <a class="btn btn-outline-secondary" href="{{ route('students.index') }}">{{ __('Back to Student') }}</a>
        </div>
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        <div class="card mb-3"><div class="card-body">
            <h4>{{ $student->user?->full_name }}</h4>
            <div class="text-muted">{{ __('Academic Year') }}: {{ $student->session_year_id }} · {{ __('Class') }}: {{ $student->class?->name ?? $student->class_id }}</div>
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
                    <button class="btn btn-primary ml-3" type="submit">{{ __('Save Draft') }}</button>
                </div>
            </form>
        @else
            <div class="alert alert-info">{{ __('No unassigned current Fee items are available for this Student.') }}</div>
        @endif

        @if($draft)
            <form method="POST" action="{{ route('students.fee-assignment.confirm', $student->id) }}" class="mt-3">
                @csrf <input type="hidden" name="assignment_uuid" value="{{ $draft->uuid }}">
                <button class="btn btn-success" type="submit">{{ __('Confirm Fee Assignment') }}</button>
            </form>
        @endif

        @foreach($confirmedAssignments as $assignment)
            <div class="card mt-3"><div class="card-body"><h5>{{ __('Assigned Total') }}: {{ number_format($assignment->items->where('status','active')->sum('amount_snapshot'), 2) }}</h5>
                <div class="text-muted">{{ __('Confirmed') }} {{ $assignment->confirmed_at }} · {{ __('Central sync status') }}: {{ __('Requested') }}</div>
                <ul class="mb-0">@foreach($assignment->items as $item)<li>{{ $item->description_snapshot }} — {{ number_format($item->amount_snapshot, 2) }} {{ $item->currency_snapshot }}</li>@endforeach</ul>
            </div></div>
        @endforeach
    </div>
@endsection

@push('scripts')
<script>
(() => { const refresh = () => { let total = 0; document.querySelectorAll('.fee-item:checked').forEach((el) => total += Number(el.dataset.amount || 0)); document.getElementById('fee-total').textContent = total.toFixed(2); }; document.querySelectorAll('.fee-item').forEach((el) => el.addEventListener('change', refresh)); refresh(); })();
</script>
@endpush
