<?php

namespace App\Services;

use App\Models\CentralFinancePayment;
use App\Models\School;
use App\Support\CentralFinanceDecimal;
use App\Support\SchoolBranding;
use App\ViewModels\CentralFinanceReceiptViewModel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;

/** Creates the one canonical, read-only data contract used by Central receipts. */
final class CentralFinanceReceiptViewModelFactory
{
    public function make(CentralFinancePayment $payment, School $school): CentralFinanceReceiptViewModel
    {
        $relations = [
            'receipt', 'refunds.fundAccount', 'refunds.refundedBy', 'reversal.fundAccount', 'reversal.reversedBy', 'receivable.studentProfile',
            'receivable.payments.refunds', 'fundAccount', 'receivedBy',
        ];
        if (Schema::connection('mysql')->hasTable('central_finance_payment_allocations')) {
            $relations[] = 'allocations.receivable.studentProfile';
        }
        $payment->loadMissing($relations);
        $depositAllocation = Schema::connection('mysql')->hasColumn('central_finance_unidentified_deposit_allocations', 'payment_id')
            ? \App\Models\CentralFinanceUnidentifiedDepositAllocation::on('mysql')->with('deposit')->where('payment_id', $payment->id)->first()
            : null;

        $receivable = $payment->receivable;
        $allocations = $payment->relationLoaded('allocations') ? $payment->allocations->sortBy('id')->values() : collect();
        $lines = $allocations->map(function ($line): array {
            $outstanding = CentralFinanceDecimal::max(CentralFinanceDecimal::subtract((string) $line->outstanding_before_snapshot, (string) $line->amount), '0');
            return [
                'description' => $line->description_snapshot ?: ($line->receivable?->description ?: '—'),
                // Allocation lines are immutable payment evidence.  Preserve
                // the item quantity and unit price here rather than looking
                // back to a mutable Fee Setup record when a receipt is
                // reprinted later.
                'unit_price' => $line->unit_price_snapshot === null ? null : CentralFinanceDecimal::normalize((string) $line->unit_price_snapshot),
                'quantity' => max(1, (int) ($line->quantity_snapshot ?? 1)),
                'gross' => CentralFinanceDecimal::normalize((string) $line->gross_amount_snapshot),
                'promotion' => CentralFinanceDecimal::normalize((string) $line->promotion_amount_snapshot),
                'due' => CentralFinanceDecimal::normalize((string) $line->net_due_snapshot),
                'this_payment' => CentralFinanceDecimal::normalize((string) $line->amount),
                'paid_at_receipt' => CentralFinanceDecimal::add((string) $line->paid_before_snapshot, (string) $line->amount),
                'outstanding_at_receipt' => $outstanding,
            ];
        });
        if ($lines->isEmpty()) {
            // Pre-P0 one-receivable Payments retain their established receipt
            // presentation even before the additive allocation backfill runs.
            $payments = $receivable?->payments
                ?->sortBy(fn (CentralFinancePayment $item) => sprintf('%s-%010d', $item->paid_at?->format('Y-m-d H:i:s.u') ?? '', $item->id));
            $paidAtReceipt = CentralFinanceDecimal::normalize('0');
            foreach ($payments ?? [] as $item) {
                $paidAtReceipt = CentralFinanceDecimal::add($paidAtReceipt, (string) $item->amount);
                if ((int) $item->id === (int) $payment->id) break;
            }
            if (CentralFinanceDecimal::compare($paidAtReceipt, '0') <= 0) $paidAtReceipt = CentralFinanceDecimal::normalize((string) $payment->amount);
            $due = CentralFinanceDecimal::normalize((string) ($receivable?->amount_due ?? $payment->amount));
            $lines = collect([[
                'description' => $receivable?->description ?: '—',
                'unit_price' => $receivable?->unit_price_snapshot === null ? null : CentralFinanceDecimal::normalize((string) $receivable->unit_price_snapshot),
                'quantity' => max(1, (int) ($receivable?->quantity_snapshot ?? 1)),
                'gross' => CentralFinanceDecimal::normalize((string) ($receivable?->source_amount_due ?? $due)),
                'promotion' => CentralFinanceDecimal::max(CentralFinanceDecimal::subtract((string) ($receivable?->source_amount_due ?? $due), $due), '0'),
                'due' => $due,
                'this_payment' => CentralFinanceDecimal::normalize((string) $payment->amount),
                'paid_at_receipt' => $paidAtReceipt,
                'outstanding_at_receipt' => CentralFinanceDecimal::max(CentralFinanceDecimal::subtract($due, $paidAtReceipt), '0'),
            ]]);
        }
        $studentReceivable = $allocations->first()?->receivable ?? $receivable;
        $sum = fn (string $field): string => $lines->reduce(fn (string $total, array $line): string => CentralFinanceDecimal::add($total, (string) $line[$field]), CentralFinanceDecimal::normalize('0'));
        $due = $sum('due');
        $paidAtReceipt = $sum('paid_at_receipt');
        $outstandingAtReceipt = $sum('outstanding_at_receipt');
        $refundTotal = (float) $payment->refunds->sum('amount');
        $refundStatus = $refundTotal <= 0 ? 'none' : ($refundTotal + 0.00001 >= (float) $payment->amount ? 'refunded' : 'partial_refund');
        $rawLogo = trim((string) $school->getRawOriginal('logo'));
        [, $logoFallback] = SchoolBranding::logoFallbacks(
            $school->getRawOriginal('code'),
            true
        );
        $logoFallbackUrl = asset($logoFallback);

        return new CentralFinanceReceiptViewModel(
            paymentId: $payment->id,
            school: [
                'name' => $school->name,
                'address' => $school->getAttribute('address') ?: null,
                'phone' => $school->getAttribute('support_phone') ?: null,
                // A Central receipt is an official School document.  Bowen
                // Schools without a custom upload must use the Bowen brand,
                // never the generic SaaS placeholder.  Keep the fallback in
                // the view model too, so a stale persistent-storage logo
                // fails safely in the browser/thermal-print renderer.
                'logo_url' => $this->logoUrl($rawLogo, $logoFallbackUrl),
                'logo_fallback_url' => $logoFallbackUrl,
            ],
            receipt: [
                'number' => $payment->receipt?->receipt_no ?? '—',
                'issued_at' => $payment->receipt?->issued_at ?? $payment->paid_at,
                'payment_status' => CentralFinanceDecimal::compare($outstandingAtReceipt, '0') <= 0 ? 'paid' : 'partial',
                'refund_status' => $refundStatus,
            ],
            student: [
                'name' => $studentReceivable?->studentProfile?->student_name ?: '—',
                'admission_no' => $studentReceivable?->studentProfile?->admission_no ?: '—',
                'student_code' => $studentReceivable?->studentProfile?->student_code ?: ($studentReceivable?->studentProfile?->admission_no ?: '—'),
                'class_section' => trim(($studentReceivable?->studentProfile?->class_name ?? '').(($studentReceivable?->studentProfile?->section_name ?? '') ? ' · '.$studentReceivable->studentProfile->section_name : '')) ?: '—',
            ],
            payment: [
                'description' => $lines->count() === 1 ? $lines->first()['description'] : __('Multiple receivables'),
                'currency' => $payment->currency,
                'due' => $due,
                'this_payment' => CentralFinanceDecimal::normalize((string) $payment->amount),
                'effective_date' => $payment->paid_at,
                'paid_at_receipt' => $paidAtReceipt,
                'outstanding_at_receipt' => $outstandingAtReceipt,
                'lines' => $lines->all(),
                'payment_method' => $payment->payment_method,
                'reference' => $payment->payment_reference ?: '—',
                'collected_by' => $payment->received_by ? optional($payment->receivedBy)->full_name : '—',
                'unidentified_deposit' => $depositAllocation ? [
                    'reference' => $depositAllocation->deposit?->bank_reference ?: $depositAllocation->deposit?->manual_identity,
                    'received_date' => $depositAllocation->deposit?->received_date,
                    'allocated_at' => $depositAllocation->matched_at,
                    'deposit_uuid' => $depositAllocation->deposit?->deposit_uuid,
                ] : null,
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
                'method' => $refund->refund_method,
                'effective_date' => $refund->effective_date,
                'actor' => $refund->refundedBy?->full_name,
            ])->values()->all(),
            reversal: $payment->reversal ? [
                'id' => $payment->reversal->id,
                'date' => $payment->reversal->effective_date,
                'reference' => $payment->reversal->reversal_reference ?: '—',
                'amount' => (float) $payment->reversal->amount,
                'currency' => $payment->reversal->currency,
                'reason' => $payment->reversal->reason,
                'actor' => $payment->reversal->reversedBy?->full_name,
            ] : null,
        );
    }

    private function logoUrl(string $path, ?string $fallback = null): string
    {
        $fallback ??= asset('assets/vertical-logo.svg');
        if ($path === '') return $fallback;
        // Keep this contract aligned with the authenticated school header:
        // relative public-disk values become /storage URLs, while already
        // public storage paths and external URLs are never rewritten.
        if (preg_match('#^https?://#i', $path)) return $path;
        if (str_starts_with($path, '/') && ! str_starts_with($path, '/storage/')) return $path;

        $relativePath = str_starts_with($path, '/storage/')
            ? substr($path, strlen('/storage/'))
            : (str_starts_with($path, 'storage/') ? substr($path, strlen('storage/')) : $path);

        // A legacy School row can retain a storage path after the shared
        // branding object has been removed. Resolve that stale reference on
        // the server rather than relying on inline JavaScript (which a CSP
        // may block) to replace a successfully rendered placeholder image.
        if (! Storage::disk('public')->exists($relativePath)) return $fallback;

        // Receipts can be opened or printed outside the normal dashboard
        // navigation. Anchor a storage-relative School logo to the configured
        // application origin and use the file's stable modification time to
        // invalidate a stale static-image response after a release switch.
        $url = url(Storage::url($relativePath));
        try {
            $version = Storage::disk('public')->lastModified($relativePath);
            return $version > 0 ? $url.'?v='.$version : $url;
        } catch (\Throwable) {
            return $url;
        }
    }
}
