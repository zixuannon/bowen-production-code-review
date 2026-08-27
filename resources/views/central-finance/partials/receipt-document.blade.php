<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="central-receipt__brand"><img src="{{ $receipt->school['logo_url'] }}" alt="{{ $receipt->school['name'] }} logo" onerror="this.onerror=null;this.src='{{ asset('assets/vertical-logo.svg') }}';"><div><div class="font-weight-bold">{{ $receipt->school['name'] }}</div>@if($receipt->school['address'])<div class="small text-muted">{{ $receipt->school['address'] }}</div>@endif @if($receipt->school['phone'])<div class="small text-muted">{{ $receipt->school['phone'] }}</div>@endif</div></div>
    <div class="text-right"><div class="text-muted small">{{ __('Central Finance Receipt') }}</div><div class="central-receipt__number">{{ $receipt->receipt['number'] }}</div><div class="small text-muted">{{ $receipt->receipt['issued_at']?->format('Y-m-d H:i') }}</div></div>
</div>
<div class="d-flex flex-wrap justify-content-between mb-3"><span class="badge badge-{{ $receipt->receipt['payment_status'] === 'paid' ? 'success' : 'warning' }}">{{ $receipt->receipt['payment_status'] === 'paid' ? __('已结清') : __('部分缴费') }}</span>@if($receipt->receipt['refund_status'] !== 'none')<span class="badge badge-danger">{{ $receipt->receipt['refund_status'] === 'refunded' ? __('已退款') : __('部分退款') }}</span>@endif<span class="text-muted small">{{ __('Currency') }}: {{ $receipt->payment['currency'] }}</span></div>

<h6>{{ __('Student') }}</h6><table class="table table-sm table-bordered"><tbody>
    <tr><th style="width:35%">{{ __('Student') }}</th><td>{{ $receipt->student['name'] }}</td></tr>
    <tr><th>{{ __('Student Code') }}</th><td>{{ $receipt->student['admission_no'] }}</td></tr>
    <tr><th>{{ __('Class / Section') }}</th><td>{{ $receipt->student['class_section'] }}</td></tr>
</tbody></table>
<h6>{{ __('Payment') }}</h6><table class="table table-sm table-bordered"><tbody>
    <tr><th style="width:35%">{{ __('Receivable') }}</th><td>{{ $receipt->payment['description'] }}</td></tr>
    <tr><th>{{ __('应缴') }}</th><td>{{ number_format($receipt->payment['due'],2) }} {{ $receipt->payment['currency'] }}</td></tr>
    <tr><th>{{ __('本次付款') }}</th><td class="central-receipt__amount">{{ number_format($receipt->payment['this_payment'],2) }} {{ $receipt->payment['currency'] }}</td></tr>
    <tr><th>{{ __('累计已缴') }}</th><td>{{ number_format($receipt->payment['paid_at_receipt'],2) }} {{ $receipt->payment['currency'] }}</td></tr>
    <tr><th>{{ __('剩余未缴') }}</th><td>{{ number_format($receipt->payment['outstanding_at_receipt'],2) }} {{ $receipt->payment['currency'] }}</td></tr>
    <tr><th>{{ __('Payment method') }}</th><td>{{ $receipt->payment['payment_method'] }}</td></tr><tr><th>{{ __('Payment reference') }}</th><td>{{ $receipt->payment['reference'] }}</td></tr><tr><th>{{ __('Collected By') }}</th><td>{{ $receipt->payment['collected_by'] }}</td></tr>
</tbody></table>
<h6>{{ __('Fund Account') }}</h6><table class="table table-sm table-bordered"><tbody>
    <tr><th style="width:35%">{{ __('Account Name / Code') }}</th><td>{{ $receipt->fundAccount['name'] }} · {{ $receipt->fundAccount['code'] }}</td></tr>
    <tr><th>{{ __('Account type') }}</th><td>{{ $receipt->fundAccount['type'] }}</td></tr><tr><th>{{ __('Bank / identifier') }}</th><td>{{ collect([$receipt->fundAccount['bank_name'],$receipt->fundAccount['masked_identifier']])->filter()->join(' · ') ?: '—' }}</td></tr><tr><th>{{ __('Currency') }}</th><td>{{ $receipt->fundAccount['currency'] }}</td></tr>
</tbody></table>
@if($receipt->refunds)<h6 class="mt-4">{{ __('Refund / reversal history') }}</h6><div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Reference') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Reason') }}</th><th>{{ __('Detail') }}</th></tr></thead><tbody>@foreach($receipt->refunds as $refund)<tr><td>{{ $refund['date']?->format('Y-m-d H:i') }}</td><td>{{ $refund['reference'] }}</td><td>{{ number_format($refund['amount'],2) }} {{ $refund['currency'] }}</td><td>{{ $refund['reason'] }}</td><td><a href="{{ route('central-finance.payments.refunds.show', [$receipt->paymentId, $refund['id']]) }}">{{ __('View') }}</a></td></tr>@endforeach</tbody></table></div>@endif
