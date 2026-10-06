@extends('layouts.master')

@section('content')
<div class="container-fluid">
    <div class="alert alert-warning"><strong>QA ONLY</strong> — Zixuan / MMBOWEN01 is permanently isolated from Official Finance totals.</div>
    <h3>Zixuan QA Runs</h3>
    <p>Each Run owns fresh Students and Finance history. Archived history is retained and never reused.</p>
    @if($activeRun)<div class="alert alert-info">Current Active Run: <a href="{{ route('central-finance.qa-runs.show', $activeRun->id) }}">#{{ $activeRun->run_number }} {{ $activeRun->label }}</a></div>@endif
    <form method="POST" action="{{ route('central-finance.qa-runs.store') }}" class="form-inline mb-3">@csrf
        <label for="qa-run-label" class="mr-2">Create QA Run</label><input id="qa-run-label" class="form-control mr-2" name="label" maxlength="191" required placeholder="Finance E2E YYYY-MM-DD">
        <button class="btn btn-primary">Create QA Run</button>
    </form>
    <div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Run</th><th>Status</th><th>Students</th><th>Created</th><th>Action</th></tr></thead><tbody>
        @forelse($runs as $run)
            <tr><td><a href="{{ route('central-finance.qa-runs.show', $run->id) }}">#{{ $run->run_number }} {{ $run->label }}</a></td><td>{{ ucfirst($run->status) }}</td><td>{{ $run->records->where('subject_type', 'student')->count() }}</td><td>{{ $run->created_at }}</td><td><a class="btn btn-sm btn-outline-primary" href="{{ route('central-finance.qa-runs.show', $run->id) }}">Run Detail</a></td></tr>
        @empty<tr><td colspan="5">No QA Runs yet.</td></tr>@endforelse
    </tbody></table></div></div>
</div>
@endsection
