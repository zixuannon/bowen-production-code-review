<?php

namespace App\Services;

use App\Models\CentralFinanceExpense;
use App\Models\CentralFinanceFundHandover;
use App\Models\CentralFinanceHqFundingRequest;
use App\Models\CentralFinanceInternalTransfer;
use App\Models\CentralFinanceLedgerEntry;
use App\Models\CentralFinanceOtherIncome;
use App\Models\CentralFinancePayment;
use App\Models\CentralFinancePaymentRefund;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Read-only, explicit source mapping for Central Ledger.  It never infers a
 * route from source_id, and it never changes a Ledger or source document.
 */
final class CentralFinanceLedgerPresentationService
{
    /** @return array{label:string,document_number:string,status:string,model:?Model} */
    public function source(CentralFinanceLedgerEntry $entry): array
    {
        $definition = $this->definition($entry->source_type);
        if ($definition === null) {
            return ['label' => $this->label($entry->source_type), 'document_number' => $entry->reference_no ?: $entry->source_id, 'status' => 'unresolved', 'model' => null];
        }

        $model = $definition['query']($entry->source_id);
        $label = $definition['label'];
        // A canonical transfer may be the accounting representation of a
        // Handover or HQ Funding request. Resolve that approved origin
        // explicitly instead of making a URL from a raw source_id.
        if ($model instanceof CentralFinanceInternalTransfer) {
            [$originLabel, $origin] = $this->transferOrigin($model);
            if ($origin !== null) {
                $label = $originLabel;
                $model = $origin;
            }
        }
        // A source UUID is not an authorization boundary.  It is globally
        // unique by design, but retain the Ledger row's trusted school scope
        // if a malformed/colliding source somehow resolves elsewhere.
        if ($model !== null && (int) $model->getAttribute('school_id') !== (int) $entry->school_id) {
            $model = null;
        }
        return [
            'label' => $label,
            'document_number' => $this->documentNumber($entry, $model),
            'status' => $this->status($model, $entry->source_type),
            'model' => $model,
        ];
    }

    /** @param Collection<int,CentralFinanceLedgerEntry> $entries @return Collection<int,CentralFinanceLedgerEntry> */
    public function decorate(Collection $entries): Collection
    {
        return $entries->each(function (CentralFinanceLedgerEntry $entry): void {
            $source = $this->source($entry);
            $entry->setAttribute('readable_source', $source['label']);
            $entry->setAttribute('readable_document_number', $source['document_number']);
            $entry->setAttribute('source_status', $source['status']);
            $entry->setAttribute('direction_label', $this->direction($entry));
            $entry->setAttribute('operating_label', $this->operating($entry));
        });
    }

    public function direction(CentralFinanceLedgerEntry $entry): string
    {
        if ($entry->transaction_type === CentralFinanceLedgerEntry::TYPE_INTERNAL_TRANSFER) return __('Internal Transfer');
        return (float) $entry->money_in > 0 ? __('Money In') : __('Money Out');
    }

    public function sourceLabel(string $sourceType): string
    {
        return $this->definition($sourceType)['label'] ?? $this->label($sourceType);
    }

    public function auditDocumentLabel(string $documentType): string
    {
        return match ($documentType) {
            'central_finance_payment' => __('Student Payment'),
            'central_finance_payment_refund' => __('Payment Refund / Reversal'),
            'central_finance_expense' => __('Expense'),
            'central_finance_other_income' => __('Other Income'),
            'central_finance_reimbursement' => __('Reimbursement'),
            'central_finance_receivable' => __('Receivable'),
            'central_finance_receivable_adjustment' => __('Adjustment / Waiver / Void'),
            'central_finance_fund_account' => __('Fund Account'),
            'central_finance_internal_transfer' => __('Internal Transfer'),
            'central_finance_fund_handover' => __('Fund Handover'),
            'central_finance_hq_funding_request' => __('HQ / School Funding'),
            'central_finance_import_batch' => __('Import Batch'),
            default => __('Unknown source (:source)', ['source' => str($documentType)->replace(['_', '-'], ' ')->title()->toString()]),
        };
    }

    public function operating(CentralFinanceLedgerEntry $entry): string
    {
        if ((float) $entry->operating_income !== 0.0) return __('Income');
        if ((float) $entry->operating_expense !== 0.0) return __('Expense');
        return __('Neutral');
    }

    private function definition(string $sourceType): ?array
    {
        return match ($sourceType) {
            'central_payment' => ['label' => __('Student Payment'), 'query' => fn (string $id) => CentralFinancePayment::on('mysql')->where('payment_uuid', $id)->first()],
            'central_payment_refund' => ['label' => __('Payment Refund / Reversal'), 'query' => fn (string $id) => CentralFinancePaymentRefund::on('mysql')->where('refund_uuid', $id)->first()],
            'central_expense', 'central_expense_void' => ['label' => $sourceType === 'central_expense_void' ? __('Expense Reversal') : __('Expense'), 'query' => fn (string $id) => CentralFinanceExpense::on('mysql')->withTrashed()->where('expense_uuid', $id)->first()],
            'central_other_income', 'central_other_income_void' => ['label' => $sourceType === 'central_other_income_void' ? __('Other Income Reversal') : __('Other Income'), 'query' => fn (string $id) => CentralFinanceOtherIncome::on('mysql')->withTrashed()->where('income_uuid', $id)->first()],
            'central_internal_transfer' => ['label' => __('Internal Transfer'), 'query' => fn (string $id) => CentralFinanceInternalTransfer::on('mysql')->where('transfer_uuid', $id)->first()],
            default => null,
        };
    }

    private function label(string $sourceType): string
    {
        return __('Unknown source (:source)', ['source' => str($sourceType)->replace(['_', '-'], ' ')->title()->toString()]);
    }

    private function documentNumber(CentralFinanceLedgerEntry $entry, ?Model $model): string
    {
        if ($model instanceof CentralFinancePayment) return $model->receipt?->receipt_no ?: ($model->payment_reference ?: $entry->reference_no ?: $model->payment_uuid);
        if ($model instanceof CentralFinancePaymentRefund) return $model->refund_reference ?: $entry->reference_no ?: $model->refund_uuid;
        if ($model instanceof CentralFinanceExpense || $model instanceof CentralFinanceOtherIncome) return $model->reference_no ?: $entry->reference_no ?: (string) $model->getKey();
        if ($model instanceof CentralFinanceInternalTransfer || $model instanceof CentralFinanceFundHandover || $model instanceof CentralFinanceHqFundingRequest) return $model->reference_no ?: $entry->reference_no ?: (string) $model->getKey();
        return $entry->reference_no ?: $entry->source_id;
    }

    private function status(?Model $model, string $sourceType): string
    {
        if ($model === null) return 'unresolved';
        if (method_exists($model, 'trashed') && $model->trashed()) return 'reversed';
        if (str_ends_with($sourceType, '_void') || $sourceType === 'central_payment_refund') return 'reversal';
        return (string) ($model->status ?? 'posted');
    }

    /** @return array{0:string,1:?Model} */
    private function transferOrigin(CentralFinanceInternalTransfer $transfer): array
    {
        return match ($transfer->source_type) {
            'fund_handover' => [__('Fund Handover'), CentralFinanceFundHandover::on('mysql')->where('handover_uuid', $transfer->source_id)->first()],
            'hq_funding' => [__('HQ / School Funding'), CentralFinanceHqFundingRequest::on('mysql')->where('funding_uuid', $transfer->source_id)->first()],
            default => [__('Internal Transfer'), $transfer],
        };
    }
}
