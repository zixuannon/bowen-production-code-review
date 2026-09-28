<?php

namespace Tests\Unit;

use App\Services\CentralFinanceCurrencySummaryService;
use Tests\TestCase;

final class CentralFinanceCollectionLifecycleViewTest extends TestCase
{
    public function test_receivable_summary_keeps_finance_pending_amount_outside_canonical_paid_and_outstanding(): void
    {
        $summary = app(CentralFinanceCurrencySummaryService::class)->receivables([
            (object) [
                'status' => 'open',
                'currency' => 'MMK',
                'amount_due' => 100000,
                'amount_paid' => 0,
                'pending_confirmation_amount' => 50000,
            ],
        ]);

        $this->assertSame(100000.0, $summary['MMK']['due']);
        $this->assertSame(0.0, $summary['MMK']['paid']);
        $this->assertSame(100000.0, $summary['MMK']['outstanding']);
        $this->assertSame(50000.0, $summary['MMK']['pending_confirmation']);
        $this->assertSame(50000.0, $summary['MMK']['available_to_collect']);
    }

    public function test_head_finance_confirmation_locks_the_front_desk_declared_bank_account_instead_of_offering_a_substitute(): void
    {
        $view = file_get_contents(resource_path('views/central-finance/pending-collections/head-finance-index.blade.php'));

        $this->assertStringContainsString('Declared Bank Fund Account', $view);
        $this->assertStringContainsString('name="fund_account_id" value="{{ $confirmedAccount->id }}"', $view);
        $this->assertStringNotContainsString('<select class="form-control" name="fund_account_id"', $view);
        $this->assertStringContainsString('Cash collections must be confirmed through a submitted Cash Handover.', $view);
    }

    public function test_collection_views_explain_the_lifecycle_and_show_reportable_collection_timestamps(): void
    {
        $studentView = file_get_contents(resource_path('views/central-finance/student-collection/show.blade.php'));
        $frontDeskView = file_get_contents(resource_path('views/central-finance/pending-collections/front-desk-index.blade.php'));
        $receiptView = file_get_contents(resource_path('views/central-finance/pending-collections/collection-receipt.blade.php'));

        $this->assertStringContainsString('Pending finance confirmation', $studentView);
        $this->assertStringContainsString('Available to collect', $studentView);
        $this->assertStringContainsString("__('Collected')", $frontDeskView);
        $this->assertStringContainsString("__('Submitted')", $frontDeskView);
        $this->assertStringContainsString('Finance Confirmed At', $receiptView);
    }
}
