@if($canIncludeQaTest ?? false)
    <form method="GET" action="{{ url()->current() }}" class="cf-data-scope mb-3 d-flex flex-wrap align-items-center" aria-label="{{ __('Finance data visibility') }}">
        <div class="mr-3 small"><strong>{{ __('Data scope: Official data') }}</strong></div>
            @foreach(request()->except('include_qa_test') as $queryKey => $queryValue)
                @if(is_scalar($queryValue))<input type="hidden" name="{{ $queryKey }}" value="{{ $queryValue }}">@endif
            @endforeach
            <label class="mb-0 small d-flex align-items-center">
                <input type="checkbox" name="include_qa_test" value="1" class="mr-2" @checked($includeQaTest ?? false) onchange="this.form.submit()">
                {{ __('Include QA/Test history') }}
            </label>
        @if($includeQaTest ?? false)<span class="small text-warning ml-2">{{ __('QA/Test history is visible in this read view.') }}</span>@endif
    </form>
@endif
