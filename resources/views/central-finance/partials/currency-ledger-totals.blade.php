<section aria-label="{{ __('Current Physical Account Summary') }}">
<p class="small text-muted mb-2">{{ __('Each physical Fund Account is counted once. Period Money In and Money Out use the selected Transaction Date range. The final figure is the current physical balance, not a filtered-period closing balance.') }}</p>
<div class="row">
    @foreach($physicalAccountTotals as $currency => $totals)
        <div class="col-12 col-xl-4 mb-3" data-currency-group="{{ $currency }}">
            <div class="card h-100"><div class="card-body">
                <h6 class="mb-3">{{ $currency }}</h6>
                <div class="row small">
                    @php($period = $periodLedgerTotals[$currency] ?? ['money_in' => 0, 'money_out' => 0])
                    @foreach(['opening_balance'=>'Opening Balance','period_money_in'=>'Period Money In','period_money_out'=>'Period Money Out','closing_balance'=>'Current Physical Balance'] as $key=>$label)
                        @php($value = $key === 'period_money_in' ? $period['money_in'] : ($key === 'period_money_out' ? $period['money_out'] : $totals[$key]))
                        <div class="col-6 mb-2"><span class="text-muted d-block">{{ __($label) }}</span><strong>{{ number_format($value, 2) }} {{ $currency }}</strong></div>
                    @endforeach
                </div>
            </div></div>
        </div>
    @endforeach
</div>
</section>
