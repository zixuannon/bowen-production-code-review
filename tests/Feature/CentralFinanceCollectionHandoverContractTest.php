<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Contract coverage for the collection handover boundary.
 *
 * These checks intentionally avoid a financial write.  They pin the additive
 * schema and the service boundary before integration tests exercise the
 * canonical PendingCollectionConfirmationService.
 */
final class CentralFinanceCollectionHandoverContractTest extends TestCase
{
    private function source(string $relativePath): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$relativePath);
    }

    public function test_additive_schema_has_batch_and_item_tables_with_short_constraints(): void
    {
        $migration = $this->source('database/migrations/2026_09_08_000001_create_central_finance_collection_handover_batches.php');

        $this->assertStringContainsString("central_finance_collection_handover_batches", $migration);
        $this->assertStringContainsString("central_finance_collection_handover_items", $migration);
        $this->assertStringContainsString("cfchb_uuid_unique", $migration);
        $this->assertStringContainsString("cfchb_idempotency_unique", $migration);
        $this->assertStringContainsString("cfchi_batch_pending_unique", $migration);
        $this->assertStringContainsString("cfchi_pending_fk", $migration);
        $this->assertStringContainsString("cfchi_payment_unique", $migration);
        $this->assertStringNotContainsString('dropColumn', $migration);
        $this->assertStringNotContainsString('central_finance_payments', $migration);
        $this->assertStringNotContainsString('central_finance_receipts', $migration);
        $this->assertStringNotContainsString('central_finance_ledger_entries', $migration);
    }

    public function test_batch_and_item_models_are_append_only_and_use_canonical_column_names(): void
    {
        $batch = $this->source('app/Models/CentralFinanceCollectionHandoverBatch.php');
        $item = $this->source('app/Models/CentralFinanceCollectionHandoverItem.php');

        $this->assertStringContainsString("'declared_handed_over_amount'", $batch);
        $this->assertStringContainsString("'actual_handed_over_amount'", $batch);
        $this->assertStringContainsString("'handover_batch_id'", $item);
        $this->assertStringContainsString("'expected_amount_snapshot'", $item);
        $this->assertStringContainsString('Confirmed handover batches are immutable.', $batch);
        $this->assertStringContainsString('Handover batches cannot be deleted.', $batch);
        $this->assertStringContainsString('Handover items cannot be deleted.', $item);
    }

    public function test_front_desk_service_never_owns_payment_receipt_ledger_or_balance_writes(): void
    {
        $service = $this->source('app/Services/CentralFinanceCollectionHandoverService.php');

        $this->assertStringContainsString('MAX_ITEMS = 50', $service);
        $this->assertStringContainsString('assertCanSubmitCollectionsSchool', $service);
        $this->assertStringContainsString('assertCentralWritesAllowed', $service);
        $this->assertStringContainsString('Pending collection already belongs to a handover.', $service);
        $this->assertStringContainsString("'handover_batch_id'", $service);
        $this->assertStringContainsString("'expected_amount_snapshot'", $service);
        $this->assertStringNotContainsString("'batch_id'", $service);
        $this->assertStringNotContainsString("'amount_snapshot'", $service);
        $this->assertStringNotContainsString('CentralFinancePaymentService', $service);
        $this->assertStringNotContainsString('CentralFinanceReceipt', $service);
        $this->assertStringNotContainsString('CentralFinanceLedger', $service);
        $this->assertStringNotContainsString('fund_account_balance', strtolower($service));
    }

    public function test_head_confirm_is_delegated_to_pending_confirmation_service(): void
    {
        $service = $this->source('app/Services/CentralFinanceHeadFinanceHandoverConfirmService.php');

        $this->assertStringContainsString('CentralFinancePendingCollectionConfirmationService', $service);
        $this->assertStringContainsString('pendingConfirmation->confirm', $service);
        $this->assertStringContainsString('assertHeadFinance', $service);
        $this->assertStringContainsString('assertCanOperateSchool', $service);
        $this->assertStringContainsString('assertCentralWritesAllowed', $service);
        $this->assertStringContainsString('DB::connection(\'mysql\')->transaction', $service);
        $this->assertStringContainsString("'actual_handed_over_amount'", $service);
        $this->assertStringNotContainsString("'actual_received_amount'", $service);
        $this->assertStringNotContainsString("'review_reason'", $service);
    }
}
