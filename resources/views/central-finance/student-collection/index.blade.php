@extends('layouts.master')

@section('title', __('Student Collection'))

@section('content')
<div class="content-wrapper">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div><small class="text-primary font-weight-bold">{{ __('Central Finance / Student Collection') }}</small><h4 class="mb-0">{{ __('学生收费') }}</h4></div>
        @if($school)<span class="badge badge-success">{{ $school->name }}</span>@endif
    </div>
    @if(!$school)
        <div class="alert alert-info">{{ __('All Schools is read-only. Select an authorized School before searching students or collecting payment.') }}</div>
    @else
        <div class="card mb-3"><div class="card-body">
            <form method="GET" class="form-row align-items-end">
                <div class="form-group col-md-3"><label>{{ __('Class') }}</label><select name="class" class="form-control"><option value="">{{ __('All Classes') }}</option>@foreach($classes as $item)<option value="{{ $item }}" @selected($class === $item)>{{ $item }}</option>@endforeach</select></div>
                <div class="form-group col-md-6"><label>{{ __('Student search') }}</label><input name="search" class="form-control" value="{{ $search }}" placeholder="{{ __('Name, admission number, guardian or phone') }}"></div>
                <div class="form-group col-md-3"><button class="btn btn-theme btn-block">{{ __('Search') }}</button></div>
            </form>
        </div></div>
        @if(!$canCollect)<div class="alert alert-warning">{{ __('This School is read-only until Central cutover and operating scope are active. Student financial information remains available.') }}</div>@endif
        <div class="card"><div class="card-body"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>{{ __('Student') }}</th><th>{{ __('Class') }}</th><th>{{ __('Guardian') }}</th><th>{{ __('Due / Paid / Outstanding') }}</th><th></th></tr></thead><tbody>
        @forelse($profiles as $profile)<tr><td><strong>{{ $profile->student_name }}</strong><small class="d-block text-muted">{{ $profile->admission_no ?: '—' }}</small></td><td>{{ trim($profile->class_name.' '.$profile->section_name) ?: '—' }}</td><td>{{ $profile->guardian_name ?: '—' }}<small class="d-block text-muted">{{ $profile->guardian_mobile ?: '' }}</small></td><td>@foreach($profile->currency_totals as $currency => $total)<small class="d-block">{{ number_format($total['due'],2) }} / {{ number_format($total['paid'],2) }} / <strong>{{ number_format($total['outstanding'],2) }}</strong> {{ $currency }}</small>@endforeach</td><td><a class="btn btn-sm btn-outline-primary" href="{{ route('central-finance.student-collection.show', $profile->id) }}">{{ __('View / Collect') }}</a></td></tr>
        @empty<tr><td colspan="5" class="text-center text-muted">{{ __('No Central Student Financial Profiles match the selected filter.') }}</td></tr>@endforelse
        </tbody></table></div><div class="mt-3">{{ $profiles->links() }}</div></div></div>
    @endif
</div>
@endsection
