@extends('layouts.master')

@section('title', __('Promotion definitions'))

@section('css')
@include('central-finance.partials.foundation-styles')
@endsection

@section('content')
<div class="content-wrapper central-finance-page">
    <x-central-finance.page-header :title="__('Promotion definitions')" :description="__('Group definitions are separate from receivable lifecycle history. Promotion applications snapshot their terms.')" eyebrow="{{ __('学生收费') }}" />
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="card mb-3"><div class="card-body"><h5>{{ __('Create promotion') }}</h5>
        <form method="POST" action="{{ route('central-finance.promotions.store') }}">@csrf
            <div class="form-row"><div class="col-md-4 form-group"><label>{{ __('Finance Group') }}</label><select name="group_id" class="form-control" required>@foreach($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach</select></div><div class="col-md-4 form-group"><label>{{ __('Name') }}</label><input name="name" class="form-control" required></div><div class="col-md-4 form-group"><label>{{ __('Code') }}</label><input name="code" class="form-control" required></div></div>
            <p class="text-muted">{{ __('General Promotions apply only to the allocated Schools. Student-specific Discounts must be requested from Student Fee Setup and approved or rejected in Student Discount Requests.') }}</p>
            <div class="form-row"><div class="col-md-3 form-group"><label>{{ __('Discount type') }}</label><select name="discount_type" class="form-control"><option value="percentage">{{ __('Percentage') }}</option><option value="fixed">{{ __('Fixed amount') }}</option></select></div><div class="col-md-3 form-group"><label>{{ __('Discount value') }}</label><input name="discount_value" inputmode="decimal" class="form-control" required></div><div class="col-md-3 form-group"><label>{{ __('Valid from') }}</label><input name="valid_from" type="date" class="form-control" required></div><div class="col-md-3 form-group"><label>{{ __('Valid until') }}</label><input name="valid_until" type="date" class="form-control"></div></div>
            <div class="form-group"><label>{{ __('Allocated Schools') }}</label><div class="border rounded p-2">@foreach($schools as $groupId => $members)<div data-promotion-group="{{ $groupId }}">@foreach($members as $member)<label class="d-block mb-1"><input type="checkbox" name="school_ids[]" value="{{ $member->school_id }}"> {{ $member->school?->name }}</label>@endforeach</div>@endforeach</div><small class="form-text text-muted">{{ __('QA/Test and Official Schools cannot be mixed in one Promotion definition.') }}</small></div>
            <div class="form-group"><label>{{ __('Description') }}</label><textarea name="description" class="form-control"></textarea></div><input type="hidden" name="status" value="active"><button class="btn btn-primary">{{ __('Create promotion') }}</button>
        </form>
    </div></div>
    <div class="card"><div class="card-body"><h5>{{ __('Existing definitions') }}</h5>
        @foreach($promotions as $promotion)
            <div class="border rounded p-3 mb-3" data-promotion-id="{{ $promotion->id }}">
                <div class="d-flex justify-content-between align-items-start flex-wrap">
                    <div><strong>{{ $promotion->code }} · {{ $promotion->name }}</strong>
                        <div>{{ $promotion->discount_type }} {{ number_format($promotion->discount_value, 2) }} · {{ $promotion->valid_from?->format('Y-m-d') }} — {{ $promotion->valid_until?->format('Y-m-d') ?: '—' }}</div>
                        <div>{{ $promotion->allocations->where('status','active')->map(fn ($allocation) => $allocation->school?->name ?: $allocation->school_id)->join(', ') }} · {{ __($promotionClassifications[$promotion->id] ?? 'production') }} · {{ __($promotion->status) }}</div>
                        @if($promotion->has_management_dependencies)<small class="text-muted">{{ __('Used Promotion: historical applications are immutable; status changes affect future use only.') }}</small>@endif
                    </div>
                    <form method="POST" action="{{ route('central-finance.promotions.status', $promotion->id) }}" class="form-inline mb-2">
                        @csrf
                        <input type="hidden" name="status" value="{{ $promotion->status === 'active' ? 'inactive' : 'active' }}">
                        <input class="form-control form-control-sm mr-2" name="reason" maxlength="2000" placeholder="{{ __('Reason') }}" required>
                        <button class="btn btn-sm {{ $promotion->status === 'active' ? 'btn-outline-secondary' : 'btn-outline-primary' }}">{{ $promotion->status === 'active' ? __('Disable') : __('Enable') }}</button>
                    </form>
                </div>
                @if(!$promotion->has_management_dependencies && ($promotion->scope ?? 'general') === 'general')
                    <details class="mt-2"><summary>{{ __('Edit unused Promotion') }}</summary>
                        <form method="POST" action="{{ route('central-finance.promotions.update', $promotion->id) }}" class="mt-3">
                            @csrf @method('PUT')
                            <div class="form-row">
                                <div class="col-md-4 form-group"><label>{{ __('Name') }}</label><input name="name" class="form-control" maxlength="191" value="{{ $promotion->name }}" required></div>
                                <div class="col-md-4 form-group"><label>{{ __('Discount type') }}</label><select name="discount_type" class="form-control" required><option value="percentage" @selected($promotion->discount_type === 'percentage')>{{ __('Percentage') }}</option><option value="fixed" @selected($promotion->discount_type === 'fixed')>{{ __('Fixed amount') }}</option></select></div>
                                <div class="col-md-4 form-group"><label>{{ __('Discount value') }}</label><input name="discount_value" inputmode="decimal" class="form-control" value="{{ $promotion->discount_value }}" required></div>
                                <div class="col-md-6 form-group"><label>{{ __('Valid from') }}</label><input name="valid_from" type="date" class="form-control" value="{{ $promotion->valid_from?->format('Y-m-d') }}" required></div>
                                <div class="col-md-6 form-group"><label>{{ __('Valid until') }}</label><input name="valid_until" type="date" class="form-control" value="{{ $promotion->valid_until?->format('Y-m-d') }}"></div>
                            </div>
                            <div class="form-group"><label>{{ __('Description') }}</label><textarea name="description" class="form-control">{{ $promotion->description }}</textarea></div>
                            <div class="form-group"><label>{{ __('Allocated Schools') }}</label>@foreach($schools->get($promotion->group_id, collect()) as $member)<label class="d-block mb-1"><input type="checkbox" name="school_ids[]" value="{{ $member->school_id }}" @checked($promotion->allocations->contains(fn ($allocation) => (int) $allocation->school_id === (int) $member->school_id && $allocation->status === 'active'))>{{ $member->school?->name }}</label>@endforeach</div>
                            <div class="form-group"><label>{{ __('Reason') }}</label><input name="reason" class="form-control" maxlength="2000" required></div>
                            <button class="btn btn-primary">{{ __('Save Promotion changes') }}</button>
                        </form>
                        <form method="POST" action="{{ route('central-finance.promotions.destroy', $promotion->id) }}" class="mt-3" onsubmit="return confirm('{{ __('Delete this unused Promotion? Its audit record will be retained.') }}')">
                            @csrf @method('DELETE')
                            <div class="form-group"><label>{{ __('Deletion reason') }}</label><input name="reason" class="form-control" maxlength="2000" required></div>
                            <button class="btn btn-outline-danger">{{ __('Delete unused Promotion') }}</button>
                        </form>
                    </details>
                @endif
            </div>
        @endforeach
        @if($promotions->isEmpty())<div class="text-center text-muted">{{ __('No Promotion definitions have been created.') }}</div>@endif
    </div></div>
</div>
@endsection
