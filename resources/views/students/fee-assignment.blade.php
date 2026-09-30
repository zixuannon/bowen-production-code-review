@extends('layouts.master')

@section('title', __('Student Fee Setup'))

@section('content')
    <div class="content-wrapper">
        <div class="page-header d-flex justify-content-between align-items-center">
            <h3 class="page-title">{{ __('Student Fee Setup') }}</h3>
            <a class="btn btn-outline-secondary" href="{{ route('students.finance.show', $student->id) }}">{{ __('Back to Student') }}</a>
        </div>
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="alert alert-warning">{{ session('error') }}</div>@endif
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
                            @php($draftItem = collect(optional($draft)->items)->firstWhere('fees_class_type_id', $item->id))
                            @php($checked = !$item->optional || $draftItem !== null)
                            @php($choiceItems = $promotionChoices->get((int) $item->id, collect()))
                            <div class="border rounded p-3 mb-2 fee-item-row">
                                <div class="form-check">
                                    <input class="form-check-input fee-item" id="fee-item-{{ $item->id }}" type="checkbox" name="optional_fee_ids[]" value="{{ $item->id }}" data-unit-price="{{ $item->fee_original_amount ?? $item->amount }}" data-currency="{{ strtoupper($item->fee_currency ?: $item->fee?->currency ?: 'MMK') }}" {{ $checked ? 'checked' : '' }} {{ !$item->optional ? 'disabled' : '' }}>
                                    <label class="form-check-label" for="fee-item-{{ $item->id }}">{{ $item->fee?->name }} · {{ number_format((float) ($item->fee_original_amount ?? $item->amount), 2) }} {{ strtoupper($item->fee_currency ?: $item->fee?->currency ?: 'MMK') }} @if(!$item->optional)<span class="badge badge-secondary">{{ __('Compulsory') }}</span>@else<span class="badge badge-info">{{ __('Optional') }}</span>@endif</label>
                                </div>
                                <div class="form-row mt-2 ml-1">
                                    <div class="col-md-3 mb-2">
                                        <label class="small mb-1" for="fee-quantity-{{ $item->id }}">{{ __('Quantity') }}</label>
                                        @if($item->quantity_enabled)
                                            <input class="form-control form-control-sm fee-quantity" id="fee-quantity-{{ $item->id }}" type="number" name="optional_fee_quantities[{{ $item->id }}]" value="{{ $draftItem?->quantity_snapshot ?? 1 }}" min="1" max="{{ $quantityMax }}" step="1" data-fee-id="{{ $item->id }}" {{ $checked ? '' : 'disabled' }}>
                                            <small class="text-muted">{{ __('Maximum') }} {{ $quantityMax }}</small>
                                        @else
                                            <input class="form-control form-control-sm" type="number" value="1" readonly aria-label="{{ __('Fixed quantity') }}">
                                            <small class="text-muted">{{ __('Fixed at 1') }}</small>
                                        @endif
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label class="small mb-1">{{ __('Line total') }}</label>
                                        <div class="font-weight-bold fee-line-total" data-fee-id="{{ $item->id }}">0.00 {{ strtoupper($item->fee_currency ?: $item->fee?->currency ?: 'MMK') }}</div>
                                        <small class="text-muted">{{ __('Unit price') }} × {{ __('Quantity') }}</small>
                                    </div>
                                    <div class="col-md-5 mb-2">
                                        <label class="small mb-1" for="fee-promotion-{{ $item->id }}">{{ __('Approved Promotion') }}</label>
                                        <select class="form-control form-control-sm fee-promotion" id="fee-promotion-{{ $item->id }}" name="promotions[{{ $item->id }}]" data-fee-id="{{ $item->id }}" {{ $checked ? '' : 'disabled' }}>
                                            <option value="">{{ __('No Promotion') }}</option>
                                            @foreach($choiceItems as $promotion)
                                                <option value="{{ $promotion->id }}" @selected((int) ($draftItem?->selected_promotion_id ?? 0) === (int) $promotion->id)>{{ $promotion->code }} · {{ $promotion->name }}</option>
                                            @endforeach
                                        </select>
                                        @if($choiceItems->isEmpty())<small class="text-muted">{{ __('No approved applicable Promotion is currently available.') }}</small>@endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    @endforeach
                    <hr><strong>{{ __('Preview Gross Total') }}: <span id="fee-total">0.00</span></strong><span class="d-block small text-muted">{{ __('Promotion discounts and net totals are calculated by the approved Promotion engine in the saved preview.') }}</span>
                    <button class="btn btn-primary ml-3" type="submit">{{ __('Preview Fee Assignment') }}</button>
                </div>
            </form>
        @else
            <div class="alert alert-info">{{ __('No unassigned current Fee items are available for this Student.') }}</div>
        @endif

        @if($draft)
            <div class="card mt-3"><div class="card-body"><h4>{{ __('Student Fee Assignment Review') }}</h4><p class="text-muted">{{ $student->user?->full_name }} · {{ __('Academic Year') }} {{ $student->session_year_id }}</p><div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Fee Item') }}</th><th>{{ __('Unit price') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Gross Line Total') }}</th><th>{{ __('Promotion') }}</th><th>{{ __('Discount') }}</th><th>{{ __('Net Line Total') }}</th></tr></thead><tbody>@foreach($draft->items->where('status','active') as $item)@php($quote = $draftPromotionPreview->get($item->id))<tr><td>{{ $item->description_snapshot }}</td><td>{{ number_format($item->unit_price_snapshot ?? $item->amount_snapshot, 2) }} {{ $item->currency_snapshot }}</td><td>{{ $item->quantity_snapshot ?? 1 }}</td><td>{{ number_format($item->amount_snapshot, 2) }} {{ $item->currency_snapshot }}</td><td>{{ $quote['promotion'] ?? __('None') }}</td><td>{{ number_format((float) ($quote['discount'] ?? 0), 2) }} {{ $item->currency_snapshot }}</td><td><strong>{{ number_format((float) ($quote['net'] ?? $item->amount_snapshot), 2) }} {{ $item->currency_snapshot }}</strong></td></tr>@endforeach</tbody></table></div><strong>{{ __('Total Assigned') }}: {{ number_format($draft->items->where('status','active')->sum('amount_snapshot'), 2) }}</strong><form method="POST" action="{{ route('students.fee-assignment.confirm', $student->id) }}" class="mt-3">@csrf <input type="hidden" name="assignment_uuid" value="{{ $draft->uuid }}"><button class="btn btn-success" type="submit">{{ __('Confirm Fee Assignment') }}</button></form></div></div>
        @endif

        @if($availableAdditionalItems->isNotEmpty() && $confirmedAssignments->isNotEmpty())
            <form method="POST" action="{{ route('students.fee-assignment.add-fee', $student->id) }}" class="card mt-3">@csrf<div class="card-body"><h4>{{ __('Add Student Fee') }}</h4><p class="text-muted">{{ __('Only unassigned eligible optional items for the current academic year are available.') }}</p>@foreach($availableAdditionalItems as $item)@php($choiceItems = $promotionChoices->get((int) $item->id, collect()))<div class="border rounded p-3 mb-2"><div class="form-check"><input class="form-check-input additional-fee-item" id="additional-fee-{{ $item->id }}" type="checkbox" name="optional_fee_ids[]" value="{{ $item->id }}" data-fee-id="{{ $item->id }}"><label class="form-check-label" for="additional-fee-{{ $item->id }}">{{ $item->fee?->name }} · {{ number_format((float) ($item->fee_original_amount ?? $item->amount), 2) }} {{ strtoupper($item->fee_currency ?: $item->fee?->currency ?: 'MMK') }}</label></div><div class="form-row mt-2 ml-1"><div class="col-md-3 mb-2"><label class="small mb-1" for="additional-quantity-{{ $item->id }}">{{ __('Quantity') }}</label>@if($item->quantity_enabled)<input class="form-control form-control-sm" id="additional-quantity-{{ $item->id }}" type="number" name="optional_fee_quantities[{{ $item->id }}]" value="1" min="1" max="{{ $quantityMax }}" step="1" disabled><small class="text-muted">{{ __('Maximum') }} {{ $quantityMax }}</small>@else<input class="form-control form-control-sm" type="number" value="1" readonly aria-label="{{ __('Fixed quantity') }}"><small class="text-muted">{{ __('Fixed at 1') }}</small>@endif</div><div class="col-md-5 mb-2"><label class="small mb-1" for="additional-promotion-{{ $item->id }}">{{ __('Approved Promotion') }}</label><select class="form-control form-control-sm" id="additional-promotion-{{ $item->id }}" name="promotions[{{ $item->id }}]" disabled><option value="">{{ __('No Promotion') }}</option>@foreach($choiceItems as $promotion)<option value="{{ $promotion->id }}">{{ $promotion->code }} · {{ $promotion->name }}</option>@endforeach</select>@if($choiceItems->isEmpty())<small class="text-muted">{{ __('No approved applicable Promotion is currently available.') }}</small>@endif</div></div></div>@endforeach<button class="btn btn-outline-primary mt-2">{{ __('Preview Additional Fee') }}</button></div></form>
        @endif

        @foreach($confirmedAssignments as $assignment)
            <div class="card mt-3"><div class="card-body"><h5>{{ __('Assigned Total') }}: {{ number_format($assignment->items->where('status','active')->sum('amount_snapshot'), 2) }}</h5>
                <div class="text-muted">{{ __('Academic Year') }} {{ $assignment->academic_year_id }} · {{ __('Confirmed') }} {{ $assignment->confirmed_at }} · {{ __('Central Finance Status') }}: {{ ($assignmentSync[$assignment->id] ?? 'pending') === 'synced' ? __('Synced') : __('Sync Pending / Requires Retry') }}@if(($promotionSync[$assignment->id] ?? 'none') === 'applied') · {{ __('Approved Promotion applied') }}@elseif(($promotionSync[$assignment->id] ?? 'none') === 'pending') · {{ __('Approved Promotion sync pending') }}@endif</div>
                <ul class="mb-0">@foreach($assignment->items as $item)<li>{{ $item->description_snapshot }} — {{ number_format($item->amount_snapshot, 2) }} {{ $item->currency_snapshot }}</li>@endforeach</ul>
                @if(($assignmentSync[$assignment->id] ?? 'pending') === 'synced' && ($finance['profile']->id ?? null))<a class="btn btn-sm btn-outline-primary mt-2" href="{{ route('students.finance.show', $student->id) }}">{{ __('View Student Finance') }}</a>@endif
                @if(($assignmentSync[$assignment->id] ?? 'pending') === 'synced' && $canCollect && ($finance['profile']->id ?? null))<a class="btn btn-sm btn-theme mt-2" href="{{ route('central-finance.student-collection.show', $finance['profile']->id) }}">{{ __('Collect Payment Now') }}</a>@elseif(($assignmentSync[$assignment->id] ?? 'pending') === 'synced')<p class="small text-muted mt-2 mb-0">{{ __('Fees assigned successfully. Finance collection must be completed by a School Accountant or Head Finance.') }}</p>@endif
                @if(($promotionSync[$assignment->id] ?? 'none') === 'pending')<form method="POST" action="{{ route('students.fee-assignment.confirm', $student->id) }}" class="d-inline">@csrf <input type="hidden" name="assignment_uuid" value="{{ $assignment->uuid }}"><button class="btn btn-sm btn-outline-warning mt-2" type="submit">{{ __('Retry Approved Promotion Sync') }}</button></form>@endif
            </div></div>
        @endforeach
    </div>
@endsection

@section('script')
<script>
(() => {
    const input = (selector, id) => document.querySelector(`${selector}[data-fee-id="${id}"]`);
    const refresh = () => {
        let total = 0;
        document.querySelectorAll('.fee-item:checked').forEach((el) => {
            const quantity = input('.fee-quantity', el.value);
            const unitPrice = Number(el.dataset.unitPrice || 0);
            const lineTotal = unitPrice * Math.max(1, Number(quantity?.value || 1));
            total += lineTotal;
            const line = document.querySelector(`.fee-line-total[data-fee-id="${el.value}"]`);
            if (line) line.textContent = lineTotal.toFixed(2) + ' ' + (el.dataset.currency || 'MMK');
        });
        document.getElementById('fee-total').textContent = total.toFixed(2);
    };
    const toggle = (checkbox, prefix = '') => {
        const quantity = document.getElementById(`${prefix}quantity-${checkbox.value}`);
        const promotion = document.getElementById(`${prefix}promotion-${checkbox.value}`);
        if (quantity && !quantity.readOnly) quantity.disabled = !checkbox.checked;
        if (promotion) promotion.disabled = !checkbox.checked;
        refresh();
    };
    document.querySelectorAll('.fee-item').forEach((checkbox) => {
        checkbox.addEventListener('change', () => toggle(checkbox, 'fee-'));
        toggle(checkbox, 'fee-');
    });
    document.querySelectorAll('.additional-fee-item').forEach((checkbox) => {
        checkbox.addEventListener('change', () => toggle(checkbox, 'additional-'));
        toggle(checkbox, 'additional-');
    });
    document.querySelectorAll('.fee-quantity').forEach((quantity) => quantity.addEventListener('input', refresh));
    refresh();
})();
</script>
@endsection
