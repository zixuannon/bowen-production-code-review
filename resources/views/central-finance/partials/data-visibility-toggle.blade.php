@if($canIncludeQaTest ?? false)
    <form method="GET" action="{{ url()->current() }}" class="card mb-3" aria-label="{{ __('Finance data visibility') }}">
        <div class="card-body py-2 d-flex flex-wrap align-items-center justify-content-between">
            <div class="mr-3">
                <strong>{{ __('Production data') }}</strong>
                <span class="text-muted small d-block">{{ __('QA/Test and archived records are excluded from totals and operating choices by default.') }}</span>
            </div>
            @foreach(request()->except('include_qa_test') as $queryKey => $queryValue)
                @if(is_scalar($queryValue))<input type="hidden" name="{{ $queryKey }}" value="{{ $queryValue }}">@endif
            @endforeach
            <label class="mb-0 d-flex align-items-center">
                <input type="checkbox" name="include_qa_test" value="1" class="mr-2" @checked($includeQaTest ?? false) onchange="this.form.submit()">
                {{ __('Include QA/Test history') }}
            </label>
        </div>
        @if($includeQaTest ?? false)<div class="alert alert-warning rounded-0 mb-0 py-2">{{ __('QA/Test history is visible in this read view. It remains excluded from normal operating selectors.') }}</div>@endif
    </form>
@endif
