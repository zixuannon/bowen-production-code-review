@extends('layouts.master')

@section('title', __('Student Finance'))

@section('content')
    <div class="content-wrapper">
        <div class="page-header d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <small class="text-primary font-weight-bold">{{ __('Student / Finance') }}</small>
                <h3 class="page-title mb-0">{{ $student->user?->full_name }}</h3>
                <small class="text-muted">{{ __('Student Code') }}: {{ $student->admission_no }} · {{ __('Academic Year') }}: {{ $student->session_year_id }}</small>
            </div>
            <a class="btn btn-outline-secondary" href="{{ route('students.index') }}">{{ __('Back to Students') }}</a>
        </div>

        <div class="card mb-3"><div class="card-body">
            <h4>{{ __('Finance') }}</h4>
            @if($assignments->isEmpty())
                <p class="text-muted mb-2">{{ __('No Fee Assignment') }}</p>
                @if($canSetup)<a class="btn btn-theme" href="{{ route('students.fee-assignment.show', $student->id) }}">{{ __('Set Up Student Fees') }}</a>@endif
            @elseif(!($finance['available'] ?? false))
                <p class="mb-1"><span class="badge badge-warning">{{ __('Fee Assignment Confirmed') }}</span></p>
                <p class="text-muted mb-0">{{ __('Finance synchronization pending. Payment status is not available until Central Finance has the Student profile and Receivables.') }}</p>
            @else
                <div class="row">@foreach($finance['currency_totals'] as $currency => $total)<div class="col-md-4 mb-2"><strong>{{ $currency }}</strong><span class="d-block">{{ __('Assigned / Due') }}: {{ number_format($total['due'], 2) }}</span><span class="d-block">{{ __('Paid') }}: {{ number_format($total['paid'], 2) }}</span><span class="d-block">{{ __('Outstanding') }}: {{ number_format($total['outstanding'], 2) }}</span><span class="badge badge-{{ $total['outstanding'] > 0 && $total['paid'] > 0 ? 'warning' : ($total['outstanding'] > 0 ? 'secondary' : 'success') }}">{{ $total['outstanding'] > 0 && $total['paid'] > 0 ? __('Partial') : ($total['outstanding'] > 0 ? __('Unpaid') : __('Fully Paid')) }}</span></div>@endforeach</div>
                @if($finance['profile'] ?? null)<a class="btn btn-outline-primary mr-2" href="{{ route('central-finance.student-collection.show', $finance['profile']->id) }}">{{ __('View Student Finance') }}</a>@if($canCollect)<a class="btn btn-theme" href="{{ route('central-finance.student-collection.show', $finance['profile']->id) }}">{{ __('Collect Payment') }}</a>@endif@endif
            @endif
        </div></div>

        <div class="card"><div class="card-body"><h4>{{ __('Fee Assignment History') }}</h4><div class="table-responsive"><table class="table mb-0"><thead><tr><th>{{ __('Academic Year') }}</th><th>{{ __('Type') }}</th><th>{{ __('Confirmed Date') }}</th><th>{{ __('Assigned Total') }}</th><th>{{ __('Confirmed By') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead><tbody>@forelse($assignments as $assignment)<tr><td>{{ $assignment->academic_year_id }}</td><td>{{ ucfirst($assignment->assignment_type) }}</td><td>{{ optional($assignment->confirmed_at)->format('Y-m-d H:i') ?: '—' }}</td><td>{{ number_format($assignment->items->where('status', 'active')->sum('amount_snapshot'), 2) }} {{ $assignment->items->first()?->currency_snapshot }}</td><td>{{ $assignment->confirmed_by ?: '—' }}</td><td><span class="badge badge-success">{{ __('Confirmed') }}</span></td><td><a class="btn btn-sm btn-outline-secondary" href="{{ route('students.fee-assignment.show', $student->id) }}#assignment-{{ $assignment->id }}">{{ __('View Assignment') }}</a></td></tr>@empty<tr><td colspan="7" class="text-center text-muted">{{ __('No confirmed fee assignments.') }}</td></tr>@endforelse</tbody></table></div></div></div>
    </div>
@endsection
