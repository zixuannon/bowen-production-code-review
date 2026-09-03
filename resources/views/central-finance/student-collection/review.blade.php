@extends('layouts.master')

@section('title', __('Review Payment'))

@section('css')
@include('central-finance.partials.foundation-styles')
@endsection

@section('content')
<div class="content-wrapper central-finance-page">
    <x-central-finance.page-header :title="__('Review collection')" :school="$school" :status="$cutoverStatus" :eyebrow="__('Student Finance')">
        <a class="btn btn-outline-secondary" href="{{ route('central-finance.student-collection.show', $profile->id) }}">{{ __('Back to student') }}</a>
    </x-central-finance.page-header>
    <div class="card"><div class="card-body"><div class="alert alert-info mb-4"><strong>{{ $profile->student_name }}</strong><span class="d-block small">{{ __('Student Code') }}: {{ $profile->student_code ?: '—' }} · {{ __('Gr Number') }}: {{ $profile->admission_no ?: '—' }}</span><span class="d-block">{{ $receivable->description }}</span><span class="d-block mt-1">{{ __('Outstanding') }}: <strong>{{ number_format($receivable->amount_due-$receivable->amount_paid,2) }} {{ $receivable->currency }}</strong></span></div>
        <form method="POST" action="{{ route('central-finance.student-collection.collect', [$profile->id, $receivable->id]) }}">@csrf<input type="hidden" name="payment_attempt_uuid" value="{{ $attemptUuid }}">
            <div class="form-group"><label>{{ __('Fund Account') }}</label><select name="fund_account_id" class="form-control" required><option value="">{{ __('Select Fund Account') }}</option>@foreach($accounts as $account)<option value="{{ $account->id }}">[{{ __($account->account_type) }}] {{ $account->account_name }} · {{ $account->account_code }} · {{ $account->currency }}@if($account->bank_name) · {{ $account->bank_name }}@endif @if($account->masked_account_identifier) · {{ $account->masked_account_identifier }}@endif</option>@endforeach</select></div>
            <div class="form-row"><div class="form-group col-md-4"><label>{{ __('Amount') }}</label><input name="amount" type="number" min="0.01" max="{{ $receivable->amount_due-$receivable->amount_paid }}" step="0.01" class="form-control" required></div><div class="form-group col-md-4"><label>{{ __('Payment method') }}</label><input name="payment_method" class="form-control" value="Cash" maxlength="40" required></div><div class="form-group col-md-4"><label>{{ __('Reference') }}</label><input name="payment_reference" class="form-control" maxlength="100"></div></div>
            <div class="form-group"><label>{{ __('Note') }}</label><textarea name="note" class="form-control" rows="3" maxlength="2000"></textarea></div><button class="btn cf-primary-action">{{ __('Confirm payment') }}</button>
        </form>
    </div></div>
</div>

@if(false)
<div class="content-wrapper"><div class="d-flex justify-content-between align-items-center mb-3"><div><small class="text-primary font-weight-bold">{{ __('Central Finance / Student Collection') }}</small><h4 class="mb-0">{{ __('Review collection') }}</h4></div><a class="btn btn-outline-secondary" href="{{ route('central-finance.student-collection.show', $profile->id) }}">{{ __('Back to student') }}</a></div>
    <div class="card"><div class="card-body"><div class="alert alert-info">{{ $school->name }} · {{ $profile->student_name }} · {{ $receivable->description }}<br>{{ __('Outstanding') }}: <strong>{{ number_format($receivable->amount_due-$receivable->amount_paid,2) }} {{ $receivable->currency }}</strong></div>
        <form method="POST" action="{{ route('central-finance.student-collection.collect', [$profile->id, $receivable->id]) }}">@csrf<input type="hidden" name="payment_attempt_uuid" value="{{ $attemptUuid }}">
            <div class="form-group"><label>{{ __('Fund Account') }}</label><select name="fund_account_id" class="form-control" required><option value="">{{ __('Select Fund Account') }}</option>@foreach($accounts as $account)<option value="{{ $account->id }}">[{{ __($account->account_type) }}] {{ $account->account_name }} · {{ $account->account_code }} · {{ $account->currency }}@if($account->bank_name) · {{ $account->bank_name }}@endif @if($account->masked_account_identifier) · {{ $account->masked_account_identifier }}@endif</option>@endforeach</select></div>
            <div class="form-row"><div class="form-group col-md-4"><label>{{ __('Amount') }}</label><input name="amount" type="number" min="0.01" max="{{ $receivable->amount_due-$receivable->amount_paid }}" step="0.01" class="form-control" required></div><div class="form-group col-md-4"><label>{{ __('Payment method') }}</label><input name="payment_method" class="form-control" value="Cash" maxlength="40" required></div><div class="form-group col-md-4"><label>{{ __('Reference') }}</label><input name="payment_reference" class="form-control" maxlength="100"></div></div>
            <div class="form-group"><label>{{ __('Note') }}</label><textarea name="note" class="form-control" rows="3" maxlength="2000"></textarea></div><button class="btn btn-theme">{{ __('Confirm payment') }}</button>
        </form>
    </div></div>
</div>
@endif
@endsection
