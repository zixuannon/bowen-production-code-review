<?php

namespace App\Services;

use App\Models\CentralFinanceImportBatch;

/** Read-only UI state mapping; import persistence and confirmation stay unchanged. */
final class CentralFinanceImportBatchPresentationService
{
    /** @return array{key:string,label:string,badge:string} */
    public function state(CentralFinanceImportBatch $batch): array
    {
        if ($batch->status === CentralFinanceImportBatch::STATUS_COMPLETED) {
            return ['key' => 'completed', 'label' => __('Completed'), 'badge' => 'success'];
        }
        if ($batch->status === CentralFinanceImportBatch::STATUS_DISCARDED) {
            return ['key' => 'cancelled', 'label' => __('Cancelled'), 'badge' => 'secondary'];
        }
        if ($batch->status === CentralFinanceImportBatch::STATUS_PENDING && (int) $batch->error_rows === 0) {
            return ['key' => 'pending_confirmation', 'label' => __('Pending confirmation'), 'badge' => 'warning'];
        }

        return ['key' => 'validation_failed', 'label' => __('Validation failed / correction required'), 'badge' => 'danger'];
    }

    public function correctedUploadUrl(CentralFinanceImportBatch $batch): string
    {
        return $batch->import_type === 'payment'
            ? route('central-finance.payments.index').'#central-payment-import'
            : route('central-finance.operations', ['operation' => 'expense']).'#central-expense-import';
    }
}
