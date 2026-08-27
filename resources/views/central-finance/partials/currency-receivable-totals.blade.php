<div class="row">
    @foreach($receivableCurrencyTotals as $currency => $totals)
        <div class="col-12 col-md-4 mb-2" data-currency-group="{{ $currency }}"><div class="border rounded p-2 h-100">
            <strong>{{ $currency }}</strong>
            <div class="small mt-1">{{ __('Due') }}: {{ number_format($totals['due'], 2) }} {{ $currency }}</div>
            <div class="small">{{ __('Paid') }}: {{ number_format($totals['paid'], 2) }} {{ $currency }}</div>
            <div class="small">{{ __('Outstanding') }}: {{ number_format($totals['outstanding'], 2) }} {{ $currency }}</div>
        </div></div>
    @endforeach
</div>
