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
            <div class="form-row"><div class="col-md-3 form-group"><label>{{ __('Discount type') }}</label><select name="discount_type" class="form-control"><option value="percentage">{{ __('Percentage') }}</option><option value="fixed">{{ __('Fixed amount') }}</option></select></div><div class="col-md-3 form-group"><label>{{ __('Discount value') }}</label><input name="discount_value" inputmode="decimal" class="form-control" required></div><div class="col-md-3 form-group"><label>{{ __('Valid from') }}</label><input name="valid_from" type="date" class="form-control" required></div><div class="col-md-3 form-group"><label>{{ __('Valid until') }}</label><input name="valid_until" type="date" class="form-control"></div></div>
            <div class="form-group"><label>{{ __('Allocated Schools') }}</label><div class="border rounded p-2">@foreach($schools as $groupId => $members)<div data-promotion-group="{{ $groupId }}">@foreach($members as $member)<label class="d-block mb-1"><input type="checkbox" name="school_ids[]" value="{{ $member->school_id }}"> {{ $member->school?->name }}</label>@endforeach</div>@endforeach</div><small class="form-text text-muted">{{ __('QA/Test and Official Schools cannot be mixed in one Promotion definition.') }}</small></div>
            <div class="form-group"><label>{{ __('Description') }}</label><textarea name="description" class="form-control"></textarea></div><input type="hidden" name="status" value="active"><button class="btn btn-primary">{{ __('Create promotion') }}</button>
        </form>
    </div></div>
    <div class="card"><div class="card-body"><h5>{{ __('Existing definitions') }}</h5><div class="table-responsive"><table class="table"><thead><tr><th>{{ __('Code') }}</th><th>{{ __('Name') }}</th><th>{{ __('Terms') }}</th><th>{{ __('Dates') }}</th><th>{{ __('Allocated Schools') }}</th><th>{{ __('Fee scope') }}</th><th>{{ __('Classification') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>@forelse($promotions as $promotion)<tr><td>{{ $promotion->code }}</td><td>{{ $promotion->name }}</td><td>{{ $promotion->discount_type }} {{ number_format($promotion->discount_value, 2) }}</td><td>{{ $promotion->valid_from?->format('Y-m-d') }} — {{ $promotion->valid_until?->format('Y-m-d') ?: '—' }}</td><td>{{ $promotion->allocations->where('status','active')->map(fn ($allocation) => $allocation->school?->name ?: $allocation->school_id)->join(', ') }}</td><td>{{ __($promotion->fee_scope) }}</td><td>{{ __($promotionClassifications[$promotion->id] ?? 'production') }}</td><td>{{ __($promotion->status) }}</td></tr>@empty<tr><td colspan="8" class="text-center text-muted">{{ __('No Promotion definitions have been created.') }}</td></tr>@endforelse</tbody></table></div></div></div>
</div>
@endsection
