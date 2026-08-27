<div class="row">
    @foreach($currencyTotals as $currency => $totals)
        <div class="col-12 col-xl-4 mb-3" data-currency-group="{{ $currency }}">
            <div class="card h-100"><div class="card-body">
                <h6 class="mb-3">{{ $currency }}</h6>
                <div class="row small">
                    @foreach(['money_in'=>'Money In','money_out'=>'Money Out','operating_income'=>'Operating Income','operating_expense'=>'Operating Expense','operating_net'=>'Operating Net'] as $key=>$label)
                        <div class="col-6 mb-2"><span class="text-muted d-block">{{ __($label) }}</span><strong>{{ number_format($totals[$key], 2) }} {{ $currency }}</strong></div>
                    @endforeach
                </div>
            </div></div>
        </div>
    @endforeach
</div>
