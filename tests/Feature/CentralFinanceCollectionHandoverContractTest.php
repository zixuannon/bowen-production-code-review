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
        $this->assertStringContainsString("'expected_amount' => 0", $service);
        $this->assertStringContainsString('The handover idempotency key belongs to a different request.', $service);
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

    public function test_batch_contract_covers_limits_and_fail_closed_lifecycle(): void
    {
        $service = $this->source('app/Services/CentralFinanceCollectionHandoverService.php');
        $confirm = $this->source('app/Services/CentralFinanceHeadFinanceHandoverConfirmService.php');
        $this->assertStringContainsString('MAX_ITEMS = 50', $service);
        $this->assertStringContainsString("where('status', CentralFinanceCollectionHandoverItem::ATTACHED)->count() >= self::MAX_ITEMS", $service);
        $this->assertStringContainsString('Declared amount must equal the server-calculated expected amount.', $service);
        $this->assertStringContainsString('Pending collection is not eligible for this handover.', $service);
        $this->assertStringContainsString('Only submitted handovers can be confirmed.', $confirm);
        $this->assertStringContainsString('Every handover item must still be a submitted Pending Collection.', $confirm);
        $this->assertStringNotContainsString('CentralFinancePendingCollection::CONFIRMED) continue', $confirm);
        $this->assertStringContainsString('actual_handed_over_amount', $confirm);
    }

    public function test_handover_ui_exposes_complete_role_scoped_workflow(): void
    {
        $controller = $this->source('app/Http/Controllers/CentralFinanceCollectionHandoverController.php');
        $view = $this->source('resources/views/central-finance/collection-handovers/index.blade.php');
        $service = $this->source('app/Services/CentralFinanceCollectionHandoverService.php');

        $this->assertStringContainsString("where('collector_id', \$actor->id)", $controller);
        $this->assertStringContainsString('assertCanSubmitCollectionsSchool', $controller);
        $this->assertStringContainsString('assertCanOperateSchool', $controller);
        $this->assertStringContainsString('canReviewPendingCollections', $controller);
        $this->assertStringContainsString('ValidationException::withMessages', $controller);
        $this->assertStringContainsString("'item_removed'", $service);
        $this->assertStringContainsString('CentralFinanceCollectionHandoverItem::REMOVED', $service);
        foreach (['Add item', 'Remove', 'Submit', 'Cancel', 'Hold', 'Reject', 'Confirm', 'Actual received amount'] as $label) {
            $this->assertStringContainsString($label, $view);
        }
    }

    public function test_routes_expose_review_actions_without_front_desk_financial_writes(): void
    {
        $routes = $this->source('routes/web.php');
        $controller = $this->source('app/Http/Controllers/CentralFinanceCollectionHandoverController.php');
        foreach (['collection-handovers', 'submit', 'confirm', 'hold', 'reject', 'cancel'] as $route) {
            $this->assertStringContainsString($route, $routes);
        }
        $this->assertStringContainsString('CentralFinanceCollectionHandoverService', $controller);
        $this->assertStringNotContainsString('CentralFinancePaymentService', $controller);
    }
}
