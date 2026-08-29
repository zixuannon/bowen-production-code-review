<?php

namespace App\Services;

use App\Models\CentralFinancePayment;
use App\Models\School;
use App\ViewModels\CentralFinanceReceiptViewModel;
use Illuminate\Support\Facades\Storage;

/** Creates the one canonical, read-only data contract used by Central receipts. */
final class CentralFinanceReceiptViewModelFactory
{
    public function make(CentralFinancePayment $payment, School $school): CentralFinanceReceiptViewModel
    {
        $payment->loadMissing([
            'receipt', 'refunds.fundAccount', 'receivable.studentProfile',
            'receivable.payments.refunds', 'fundAccount', 'receivedBy',
        ]);

        $receivable = $payment->receivable;
        $payments = $receivable?->payments
            ?->sortBy(fn (CentralFinancePayment $item) => sprintf('%s-%010d', $item->paid_at?->format('Y-m-d H:i:s.u') ?? '', $item->id));
        $paidAtReceipt = 0.0;
        foreach ($payments ?? [] as $item) {
            $paidAtReceipt += (float) $item->amount;
            if ((int) $item->id === (int) $payment->id) break;
        }
        if ($paidAtReceipt <= 0) $paidAtReceipt = (float) $payment->amount;
        $due = (float) ($receivable?->amount_due ?? $payment->amount);
        $refundTotal = (float) $payment->refunds->sum('amount');
        $refundStatus = $refundTotal <= 0 ? 'none' : ($refundTotal + 0.00001 >= (float) $payment->amount ? 'refunded' : 'partial_refund');
        $rawLogo = trim((string) $school->getRawOriginal('logo'));

        return new CentralFinanceReceiptViewModel(
            paymentId: $payment->id,
            school: [
                'name' => $school->name,
                'address' => $school->getAttribute('address') ?: null,
                'phone' => $school->getAttribute('support_phone') ?: null,
                'logo_url' => $this->logoUrl($rawLogo),
            ],
            receipt: [
                'number' => $payment->receipt?->receipt_no ?? '—',
                'issued_at' => $payment->receipt?->issued_at ?? $payment->paid_at,
                'payment_status' => $paidAtReceipt + 0.00001 >= $due ? 'paid' : 'partial',
                'refund_status' => $refundStatus,
            ],
            student: [
                'name' => $receivable?->studentProfile?->student_name ?: '—',
                'admission_no' => $receivable?->studentProfile?->admission_no ?: '—',
                'class_section' => trim(($receivable?->studentProfile?->class_name ?? '').(($receivable?->studentProfile?->section_name ?? '') ? ' · '.$receivable->studentProfile->section_name : '')) ?: '—',
            ],
            payment: [
                'description' => $receivable?->description ?: '—',
                'currency' => $payment->currency,
                'due' => $due,
                'this_payment' => (float) $payment->amount,
                'paid_at_receipt' => $paidAtReceipt,
                'outstanding_at_receipt' => (float) max(0, $due - $paidAtReceipt),
                'payment_method' => $payment->payment_method,
                'reference' => $payment->payment_reference ?: '—',
                'collected_by' => $payment->received_by ? optional($payment->receivedBy)->full_name : '—',
            ],
            fundAccount: [
                'name' => $payment->fundAccount?->account_name ?: '—',
                'code' => $payment->fundAccount?->account_code ?: '—',
                'type' => $payment->fundAccount?->account_type ?: '—',
                'bank_name' => $payment->fundAccount?->bank_name ?: null,
                'masked_identifier' => $payment->fundAccount?->masked_account_identifier ?: null,
                'currency' => $payment->fundAccount?->currency ?: $payment->currency,
            ],
            refunds: $payment->refunds->sortBy('refunded_at')->map(fn ($refund) => [
                'id' => $refund->id,
                'date' => $refund->refunded_at,
                'reference' => $refund->refund_reference ?: '—',
                'amount' => (float) $refund->amount,
                'currency' => $refund->currency,
                'reason' => $refund->reason,
            ])->values()->all(),
        );
    }

    private function logoUrl(string $path): string
    {
        if ($path === '') return asset('assets/vertical-logo.svg');
        // Keep this contract aligned with the authenticated school header:
        // relative public-disk values become /storage URLs, while already
        // public storage paths and external URLs are never rewritten.
        if (preg_match('#^https?://#i', $path) || str_starts_with($path, '/')) return $path;
        if (str_starts_with($path, 'storage/')) return url('/'.$path);

        // Receipts can be opened or printed outside the normal dashboard
        // navigation. Anchor a storage-relative School logo to the configured
        // application origin so the image does not inherit an alternate static
        // host from an older release context.
        return url(Storage::url($path));
    }
}
