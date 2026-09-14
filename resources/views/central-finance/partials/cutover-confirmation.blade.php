<div class="cf-cutover-confirm mb-3">
    <div class="form-group">
        <label for="{{ $prefix }}-confirm-code">{{ __('Type the canonical School Code to confirm') }}: <strong>{{ $school->code }}</strong></label>
        <input id="{{ $prefix }}-confirm-code" name="confirm_school_code" class="form-control text-uppercase" autocomplete="off" required>
    </div>
    <div class="custom-control custom-checkbox">
        <input id="{{ $prefix }}-confirmed" name="confirmed" value="1" type="checkbox" class="custom-control-input" required>
        <label class="custom-control-label" for="{{ $prefix }}-confirmed">{{ __('I reviewed the School, effective date, status, and audit reason.') }}</label>
    </div>
</div>
