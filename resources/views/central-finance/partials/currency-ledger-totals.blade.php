<div class="row" aria-label="{{ __('Physical Account Summary') }}">
    @foreach($physicalAccountTotals as $currency => $totals)
        <div class="col-12 col-xl-4 mb-3" data-currency-group="{{ $currency }}">
            <div class="card h-100"><div class="card-body">
                <h6 class="mb-3">{{ $currency }}</h6>
                <div class="row small">
                    @foreach(['opening_balance'=>'Opening Balance','money_in'=>'Money In','money_out'=>'Money Out','closing_balance'=>'Closing Balance'] as $key=>$label)
                        <div class="col-6 mb-2"><span class="text-muted d-block">{{ __($label) }}</span><strong>{{ number_format($totals[$key], 2) }} {{ $currency }}</strong></div>
                    @endforeach
                </div>
            </div></div>
        </div>
    @endforeach
</div>
