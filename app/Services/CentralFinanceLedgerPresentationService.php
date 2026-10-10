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
use App\Models\CentralFinanceUnidentifiedDeposit;
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
            if ($model->reversal_of_transfer_id !== null) {
                $label = __('Internal Transfer Reversal');
            } else {
                [$originLabel, $origin] = $this->transferOrigin($model);
                if ($origin !== null) {
                    $label = $originLabel;
                    $model = $origin;
                }
            }
        }
        // A source UUID is not an authorization boundary.  It is globally
        // unique by design, but retain the Ledger row's trusted school scope
        // if a malformed/colliding source somehow resolves elsewhere.
        if ($model !== null && (int) $model->getAttribute('school_id') !== (int) $entry->school_id) {
            $model = null;
        }
        if ($model instanceof CentralFinanceUnidentifiedDeposit
            && (int) $model->fund_account_id !== (int) $entry->fund_account_id) $model = null;
        if ($model instanceof CentralFinancePayment && $model->unidentified_deposit_id) {
            $label = __('Allocation of previously received deposit — no new bank receipt');
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
            if ($source['model'] instanceof CentralFinanceOtherIncome) {
                // Payer is part of the canonical Other Income source record;
                // keep the statement query scoped to the Ledger account and
                // expose only the resolved, same-School source document.
                $entry->setAttribute('readable_payer', $source['model']->payer);
                if (filled($source['model']->payer)) {
                    $description = trim((string) $entry->memo);
                    $prefix = __('Sender').': '.$source['model']->payer;
                    if (! str_starts_with($description, $prefix)) {
                        $entry->setAttribute('memo', trim($prefix.($description !== '' ? ' · '.$description : '')));
                    }
                }
            }
            $depositFlow = $entry->source_type === 'central_unidentified_deposit'
                || $entry->transaction_type === 'unidentified_deposit_allocation';
            $entry->setAttribute('is_deposit_flow', $depositFlow);
            // Deposit occurred_at preserves the bank business date. Its
            // system recording time is the append-only row's created_at.
            $entry->setAttribute('display_recorded_at', $depositFlow ? $entry->created_at : $entry->occurred_at);
            $this->decorateTransferLeg($entry, $source['model']);
        });
    }

    /**
     * Funding remains one neutral canonical transfer. These are read-only
     * source/destination labels for account statements, never balance inputs.
     */
    private function decorateTransferLeg(CentralFinanceLedgerEntry $entry, ?Model $model): void
    {
        if ($model instanceof CentralFinanceHqFundingRequest) {
            $entry->setAttribute('funding_leg', $entry->source_line === 'destination' ? 'incoming' : 'outgoing');
            $entry->setAttribute('funding_source_account_label', $this->accountLabel($model->sourceAccount));
            $entry->setAttribute('funding_destination_account_label', $this->accountLabel($model->destinationAccount));
            return;
        }

        if (!$model instanceof CentralFinanceInternalTransfer) {
            return;
        }

        $entry->setAttribute('transfer_leg', $entry->source_line === 'destination' ? 'incoming' : 'outgoing');
        $entry->setAttribute('transfer_is_reversal', $model->reversal_of_transfer_id !== null);
        $entry->setAttribute('transfer_source_account_label', $this->accountLabel($model->sourceAccount));
        $entry->setAttribute('transfer_destination_account_label', $this->accountLabel($model->destinationAccount));
    }

    private function accountLabel(?\App\Models\CentralFinanceFundAccount $account): string
    {
        return $account === null ? '—' : trim($account->account_name.' · '.$account->account_code);
    }

    public function direction(CentralFinanceLedgerEntry $entry): string
    {
        if ($entry->transaction_type === CentralFinanceLedgerEntry::TYPE_INTERNAL_TRANSFER) return __('Internal Transfer');
        if ((float) $entry->money_in === 0.0 && (float) $entry->money_out === 0.0) return __('No cash movement');
        return (float) $entry->money_in > 0 ? __('Money In') : __('Money Out');
    }

    public function sourceLabel(string $sourceType): string
    {
        return $this->definition($sourceType)['label'] ?? $this->label($sourceType);
    }

    public function auditDocumentLabel(string $documentType): string
    {
        return match ($documentType) {
            'central_finance_payment', 'central_payment' => __('Student Payment'),
            'unidentified_deposit' => __('Unidentified Deposit'),
            'unidentified_deposit_allocation' => __('Deposit allocation'),
            'central_finance_payment_refund', 'central_payment_refund' => __('Payment Refund'),
            'central_finance_payment_reversal', 'central_payment_reversal' => __('Payment Reversal'),
            'central_finance_expense', 'expense' => __('Expense'),
            'central_finance_other_income', 'other_income' => __('Income'),
            'central_finance_reimbursement', 'reimbursement' => __('Reimbursement'),
            'central_finance_receivable', 'central_receivable' => __('Receivable'),
            'central_finance_receivable_adjustment' => __('Adjustment / Waiver / Void'),
            'central_finance_fund_account', 'fund_account' => __('Fund Account'),
            'central_finance_internal_transfer', 'internal_transfer' => __('Internal Transfer'),
            'central_finance_fund_handover', 'fund_handover' => __('Fund Handover'),
            'central_finance_hq_funding_request', 'hq_funding' => __('HQ / School Funding'),
            'central_finance_import_batch', 'import_batch' => __('Import Batch'),
            'central_finance_school_cutover' => __('School Centralization Cutover'),
            default => __('Unknown source (:source)', ['source' => str($documentType)->replace(['_', '-'], ' ')->title()->toString()]),
        };
    }

    /**
     * Audit reasons can be operator-entered narrative. Preserve those facts,
     * while localizing known system and UAT reason keys at presentation time.
     */
    public function auditReason(?string $reason): string
    {
        return blank($reason) ? '—' : __($reason);
    }

    /** @return list<array{field:string,before:string,after:string}> */
    public function auditDiff(?array $before, ?array $after): array
    {
        $before ??= [];
        $after ??= [];
        $keys = array_values(array_unique([...array_keys($before), ...array_keys($after)]));

        return array_map(fn (string $key): array => [
            'field' => str($key)->replace(['_', '-'], ' ')->title()->toString(),
            'before' => $this->auditValue($before[$key] ?? null),
            'after' => $this->auditValue($after[$key] ?? null),
        ], $keys);
    }

    private function auditValue(mixed $value): string
    {
        if ($value === null || $value === '') return '—';
        if (is_bool($value)) return $value ? __('Yes') : __('No');
        if (is_array($value) || is_object($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
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
            'central_unidentified_deposit' => ['label' => __('Unidentified Deposit'), 'query' => fn (string $id) => \Illuminate\Support\Facades\Schema::connection('mysql')->hasTable('central_finance_unidentified_deposits') ? CentralFinanceUnidentifiedDeposit::on('mysql')->where('deposit_uuid', $id)->first() : null],
            'central_payment' => ['label' => __('Student Payment'), 'query' => fn (string $id) => CentralFinancePayment::on('mysql')->where('payment_uuid', $id)->first()],
            'central_payment_refund' => ['label' => __('Payment Refund'), 'query' => fn (string $id) => CentralFinancePaymentRefund::on('mysql')->where('refund_uuid', $id)->first()],
            'central_payment_reversal' => ['label' => __('Payment Reversal'), 'query' => fn (string $id) => \App\Models\CentralFinancePaymentReversal::on('mysql')->where('reversal_uuid', $id)->first()],
            'central_expense', 'central_expense_void' => ['label' => $sourceType === 'central_expense_void' ? __('Expense Reversal') : __('Expense'), 'query' => fn (string $id) => CentralFinanceExpense::on('mysql')->withTrashed()->where('expense_uuid', $id)->first()],
            'central_other_income', 'central_other_income_void' => ['label' => $sourceType === 'central_other_income_void' ? __('Income Reversal') : __('Income'), 'query' => fn (string $id) => CentralFinanceOtherIncome::on('mysql')->withTrashed()->where('income_uuid', $id)->first()],
            'central_internal_transfer', 'central_internal_transfer_reversal' => ['label' => $sourceType === 'central_internal_transfer_reversal' ? __('Internal Transfer Reversal') : __('Internal Transfer'), 'query' => fn (string $id) => CentralFinanceInternalTransfer::on('mysql')->with(['sourceAccount', 'destinationAccount'])->where('transfer_uuid', $id)->first()],
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
        if ($model instanceof CentralFinanceUnidentifiedDeposit) return $model->bank_reference ?: $model->manual_identity ?: $model->deposit_uuid;
        if ($model instanceof CentralFinancePaymentRefund) return $model->refund_reference ?: $entry->reference_no ?: $model->refund_uuid;
        if ($model instanceof CentralFinanceExpense || $model instanceof CentralFinanceOtherIncome) return $model->reference_no ?: $entry->reference_no ?: (string) $model->getKey();
        if ($model instanceof CentralFinanceInternalTransfer || $model instanceof CentralFinanceFundHandover || $model instanceof CentralFinanceHqFundingRequest) return $model->reference_no ?: $entry->reference_no ?: (string) $model->getKey();
        return $entry->reference_no ?: $entry->source_id;
    }

    private function status(?Model $model, string $sourceType): string
    {
        if ($model === null) return 'unresolved';
        if (method_exists($model, 'trashed') && $model->trashed()) return 'reversed';
        if (str_ends_with($sourceType, '_void') || in_array($sourceType, ['central_payment_refund', 'central_payment_reversal'], true)) return 'reversal';
        return (string) ($model->status ?? 'posted');
    }

    /** @return array{0:string,1:?Model} */
    private function transferOrigin(CentralFinanceInternalTransfer $transfer): array
    {
        return match ($transfer->source_type) {
            'fund_handover' => [__('Fund Handover'), CentralFinanceFundHandover::on('mysql')->where('handover_uuid', $transfer->source_id)->first()],
            'hq_funding' => [__('HQ / School Funding'), CentralFinanceHqFundingRequest::on('mysql')->with(['sourceAccount', 'destinationAccount'])->where('funding_uuid', $transfer->source_id)->first()],
            default => [__('Internal Transfer'), $transfer],
        };
    }
}
