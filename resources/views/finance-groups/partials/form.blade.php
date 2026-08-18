<form action="{{ $action }}" method="POST">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif
    <div class="row">
        <div class="form-group col-md-4">
            <label>{{ __('Name') }} <span class="text-danger">*</span></label>
            <input class="form-control" name="name" required value="{{ old('name', $group?->name) }}">
        </div>
        <div class="form-group col-md-2">
            <label>{{ __('Code') }}</label>
            <input class="form-control" name="code" maxlength="64" value="{{ old('code', $group?->code) }}" placeholder="{{ __('Optional until ready') }}">
        </div>
        <div class="form-group col-md-2">
            <label>{{ __('Reporting Currency') }}</label>
            <input class="form-control text-uppercase" name="reporting_currency" maxlength="3" required value="{{ old('reporting_currency', $group?->reporting_currency ?? 'MMK') }}">
        </div>
        <div class="form-group col-md-2">
            <label>{{ __('Fiscal Year Starts') }}</label>
            <select class="form-control" name="fiscal_year_start_month">
                @for ($month = 1; $month <= 12; $month++)
                    <option value="{{ $month }}" @selected((int) old('fiscal_year_start_month', $group?->fiscal_year_start_month ?? 1) === $month)>{{ $month }}</option>
                @endfor
            </select>
        </div>
        <div class="form-group col-md-2">
            <label>{{ __('Status') }}</label>
            <select class="form-control" name="status">
                @foreach (['draft', 'active', 'inactive'] as $status)
                    <option value="{{ $status }}" @selected(old('status', $group?->status ?? 'draft') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="form-group">
        <label>{{ __('Member Schools') }}</label>
        <div class="row">
            @php($selectedSchools = $group?->schools->where('status', 'active')->pluck('school_id')->all() ?? [])
            @foreach ($schools as $school)
                <div class="col-md-3">
                    <label class="form-check-label">
                        <input type="checkbox" name="school_ids[]" value="{{ $school->id }}" @checked(in_array($school->id, old('school_ids', $selectedSchools), true))>
                        {{ $school->name }} @if($school->code) ({{ $school->code }}) @endif
                    </label>
                </div>
            @endforeach
        </div>
        <small class="form-text text-muted">{{ __('Only existing Schools from the central registry can be selected.') }}</small>
    </div>
    <button class="btn btn-theme" type="submit">{{ $group ? __('Save changes') : __('Create') }}</button>
</form>
