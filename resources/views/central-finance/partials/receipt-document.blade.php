<div class="central-receipt__brand">
    <img src="{{ $receipt->school['logo_url'] }}" alt="{{ $receipt->school['name'] }} logo" onerror="this.onerror=null;this.src='{{ $receipt->school['logo_fallback_url'] }}';">
    <div class="central-receipt__school">{{ $receipt->school['name'] }}</div>
    @if($receipt->school['address'])<div>{{ $receipt->school['address'] }}</div>@endif
    @if($receipt->school['phone'])<div>{{ $receipt->school['phone'] }}</div>@endif
</div>
<h1>{{ __('Central Finance Receipt') }}</h1>
<p class="central-receipt__status {{ $receipt->receipt['payment_status'] === 'paid' ? '' : 'is-partial' }}">{{ __('Finance Confirmed') }} · {{ $receipt->receipt['payment_status'] === 'paid' ? __('已结清') : __('部分缴费') }}</p>
<dl>
    <dt>{{ __('Official Receipt No.') }}</dt><dd class="central-receipt__number">{{ $receipt->receipt['number'] }}</dd>
    <dt>{{ __('Payment Effective Date') }}</dt><dd>{{ $receipt->payment['effective_date']?->format('Y-m-d') ?? '—' }}</dd>
    <dt>{{ __('Receipt Issued At') }}</dt><dd>{{ $receipt->receipt['issued_at']?->format('Y-m-d H:i') ?? '—' }}</dd>
    <dt>{{ __('Currency') }}</dt><dd>{{ $receipt->payment['currency'] }}</dd>
</dl>

@if($receipt->payment['unidentified_deposit'] ?? null)
<div class="central-receipt__section">
    <p>{{ __('Allocation of previously received deposit — no new bank receipt') }}</p>
    <dl>
        <dt>{{ __('Original Bank Reference') }}</dt><dd>{{ $receipt->payment['unidentified_deposit']['reference'] ?: '—' }}</dd>
        <dt>{{ __('Original Bank Transaction Date') }}</dt><dd>{{ $receipt->payment['unidentified_deposit']['received_date']?->format('Y-m-d') }}</dd>
        <dt>{{ __('Allocated At') }}</dt><dd>{{ $receipt->payment['unidentified_deposit']['allocated_at']?->format('Y-m-d H:i:s') }}</dd>
        <dt>{{ __('Unidentified Deposit') }}</dt><dd>{{ $receipt->payment['unidentified_deposit']['deposit_uuid'] }}</dd>
    </dl>
</div>
@endif

<div class="central-receipt__section"><p class="central-receipt__section-title">{{ __('Student') }}</p><dl>
    <dt>{{ __('Student') }}</dt><dd>{{ $receipt->student['name'] }}</dd>
    <dt>{{ __('Student Code') }}</dt><dd>{{ $receipt->student['student_code'] ?? $receipt->student['admission_no'] }}</dd>
    <dt>{{ __('GR Number') }}</dt><dd>{{ $receipt->student['admission_no'] }}</dd>
    <dt>{{ __('Class / Section') }}</dt><dd>{{ $receipt->student['class_section'] }}</dd>
</dl></div>

<div class="central-receipt__section"><p class="central-receipt__section-title">{{ __('Payment') }}</p><dl>
    @foreach($receipt->payment['lines'] as $line)
    @if(count($receipt->payment['lines']) > 1)<dt>{{ __('Receivable allocations') }}</dt>@else<dt>{{ __('Receivable') }}</dt>@endif
    <dd>{{ $line['description'] }}</dd>
    <dt>{{ __('Quantity') }}</dt><dd>{{ number_format($line['quantity']) }}</dd>
    @if($line['unit_price'] !== null)<dt>{{ __('Unit price') }}</dt><dd>{{ number_format($line['unit_price'],2) }} {{ $receipt->payment['currency'] }}</dd>@endif
    <dt>{{ __('Line total') }}</dt><dd>{{ number_format($line['gross'],2) }} {{ $receipt->payment['currency'] }}</dd>
    @if((float) $line['promotion'] > 0)<dt>{{ __('Promotion') }}</dt><dd>{{ number_format($line['promotion'],2) }} {{ $receipt->payment['currency'] }}</dd>@endif
    @endforeach
    <dt>{{ __('Receivable') }}</dt><dd>{{ $receipt->payment['description'] }}</dd>
    <dt>{{ __('应缴') }}</dt><dd>{{ number_format($receipt->payment['due'],2) }} {{ $receipt->payment['currency'] }}</dd>
    <dt>{{ __('本次付款') }}</dt><dd class="central-receipt__amount">{{ number_format($receipt->payment['this_payment'],2) }} {{ $receipt->payment['currency'] }}</dd>
    <dt>{{ __('累计已缴') }}</dt><dd>{{ number_format($receipt->payment['paid_at_receipt'],2) }} {{ $receipt->payment['currency'] }}</dd>
    <dt>{{ __('剩余未缴') }}</dt><dd>{{ number_format($receipt->payment['outstanding_at_receipt'],2) }} {{ $receipt->payment['currency'] }}</dd>
    <dt>{{ __('Payment method') }}</dt><dd>{{ $receipt->payment['payment_method'] }}</dd>
    <dt>{{ __('Payment reference') }}</dt><dd>{{ $receipt->payment['reference'] }}</dd>
    <dt>{{ __('Collected By') }}</dt><dd>{{ $receipt->payment['collected_by'] }}</dd>
</dl></div>

<div class="central-receipt__section"><p class="central-receipt__section-title">{{ __('Fund Account') }}</p><dl>
    <dt>{{ __('Account Name / Code') }}</dt><dd>{{ $receipt->fundAccount['name'] }} · {{ $receipt->fundAccount['code'] }}</dd>
    <dt>{{ __('Account type') }}</dt><dd>{{ __($receipt->fundAccount['type']) }}</dd>
    <dt>{{ __('Bank / identifier') }}</dt><dd>{{ collect([$receipt->fundAccount['bank_name'],$receipt->fundAccount['masked_identifier']])->filter()->join(' · ') ?: '—' }}</dd>
    <dt>{{ __('Currency') }}</dt><dd>{{ $receipt->fundAccount['currency'] }}</dd>
</dl></div>

@if($receipt->refunds)
    <div class="central-receipt__section"><p class="central-receipt__section-title">{{ __('Refund / reversal history') }}</p><dl>
        @foreach($receipt->refunds as $refund)
            <dt>{{ $refund['date']?->timezone('Asia/Yangon')->format('Y-m-d H:i') }} · {{ $refund['reference'] }}</dt>
            <dd>{{ number_format($refund['amount'],2) }} {{ $refund['currency'] }} · {{ $refund['method'] ?: '—' }} · {{ $refund['actor'] ?: '—' }} · {{ $refund['reason'] }}</dd>
        @endforeach
    </dl></div>
@endif
@if($receipt->reversal)
    <div class="central-receipt__section"><p class="central-receipt__section-title">{{ __('Payment reversal history') }}</p><dl><dt>{{ $receipt->reversal['date']?->timezone('Asia/Yangon')->format('Y-m-d') }} · {{ $receipt->reversal['reference'] }}</dt><dd>{{ number_format($receipt->reversal['amount'],2) }} {{ $receipt->reversal['currency'] }} · {{ $receipt->reversal['actor'] ?: '—' }} · {{ $receipt->reversal['reason'] }}</dd></dl></div>
@endif

@if($audits->isNotEmpty())
    <div class="central-receipt__section central-receipt__audit"><p class="central-receipt__section-title">{{ __('Audit timeline') }}</p>
        @foreach($audits as $audit)<div class="central-receipt__audit-row">{{ $audit->created_at?->timezone('Asia/Yangon')->format('Y-m-d H:i') ?? '—' }} · {{ __($audit->action) }}@if($audit->reason) · {{ $audit->reason }}@endif</div>@endforeach
    </div>
@endif

<p class="central-receipt__notice">{{ __('This is an official Central Finance Receipt. It records the confirmed canonical payment and does not create another payment when reprinted.') }}</p>
