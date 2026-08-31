@extends('layouts.master')

@section('title', __('Student Collection'))

@section('css')
@include('central-finance.partials.foundation-styles')
@endsection

@section('content')
<div class="content-wrapper central-finance-page">
    <x-central-finance.page-header :title="__('Student Collection')" :school="$school" :status="$cutoverStatus ?? null" :eyebrow="__('Student Finance')" />

    @if(!$school)
        <div class="alert alert-info">{{ __('All Schools is read-only. Select an authorized School before searching students or collecting payment.') }}</div>
    @else
        <form method="GET" class="form-row cf-filter-bar align-items-end">
            <div class="form-group col-md-3"><label>{{ __('Class') }}</label><select name="class" class="form-control"><option value="">{{ __('All Classes') }}</option>@foreach($classes as $item)<option value="{{ $item }}" @selected($class === $item)>{{ $item }}</option>@endforeach</select></div>
            <div class="form-group col-md-6"><label>{{ __('Student search') }}</label><input name="search" class="form-control" value="{{ $search }}" placeholder="{{ __('Name, admission number, guardian or phone') }}"></div>
            <div class="form-group col-md-3"><button class="btn cf-primary-action btn-block">{{ __('Search') }}</button></div>
        </form>

        @if(!$canCollect)<div class="alert alert-warning">{{ __('This School is read-only until Central cutover and operating scope are active. Student financial information remains available.') }}</div>@endif

        <div class="card"><div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3"><div><h5 class="mb-1">{{ __('Students') }}</h5></div><span class="cf-context-chip">{{ $profiles->total() }} {{ __('Students') }}</span></div>
            <div class="table-responsive"><table class="table cf-data-table cf-mobile-card-table mb-0"><thead><tr><th>{{ __('Student') }}</th><th>{{ __('Class') }}</th><th>{{ __('Guardian') }}</th><th>{{ __('Due') }}</th><th>{{ __('Paid') }}</th><th>{{ __('Outstanding') }}</th><th>{{ __('Status') }}</th><th>{{ __('Action') }}</th></tr></thead><tbody>
            @forelse($profiles as $profile)
                @php($totals = $profile->currency_totals ?? [])
                @php($hasOutstanding = collect($totals)->contains(fn ($total) => (float) ($total['outstanding'] ?? 0) > 0))
                <tr>
                    <td data-label="{{ __('Student') }}"><span class="cf-primary-line">{{ $profile->student_name }}</span><span class="cf-secondary-line">{{ $profile->admission_no ?: '—' }}</span></td>
                    <td data-label="{{ __('Class') }}">{{ trim($profile->class_name.' '.$profile->section_name) ?: '—' }}</td>
                    <td data-label="{{ __('Guardian') }}"><span class="cf-primary-line">{{ $profile->guardian_name ?: '—' }}</span><span class="cf-secondary-line">{{ $profile->guardian_mobile ?: '—' }}</span></td>
                    <td data-label="{{ __('Due') }}">@forelse($totals as $currency => $total)<span class="d-block">{{ number_format($total['due'],2) }} {{ $currency }}</span>@empty — @endforelse</td>
                    <td data-label="{{ __('Paid') }}">@forelse($totals as $currency => $total)<span class="d-block">{{ number_format($total['paid'],2) }} {{ $currency }}</span>@empty — @endforelse</td>
                    <td data-label="{{ __('Outstanding') }}">@forelse($totals as $currency => $total)<strong class="d-block">{{ number_format($total['outstanding'],2) }} {{ $currency }}</strong>@empty — @endforelse</td>
                    <td data-label="{{ __('Status') }}"><span class="badge cf-status-badge badge-{{ $hasOutstanding ? 'warning' : 'success' }}">{{ $hasOutstanding ? __('Outstanding') : __('Paid') }}</span></td>
                    <td data-label=""><a class="btn btn-sm {{ $canCollect && $hasOutstanding ? 'cf-primary-action' : 'btn-outline-primary' }}" href="{{ route('central-finance.student-collection.show', $profile->id) }}">{{ $canCollect && $hasOutstanding ? __('Collect') : __('View') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="8"><div class="cf-empty-state">{{ __('No Central Student Financial Profiles match the selected filter.') }}</div></td></tr>
            @endforelse
            </tbody></table></div>
            <div class="mt-3">{{ $profiles->links() }}</div>
        </div></div>
    @endif
</div>
@endsection
