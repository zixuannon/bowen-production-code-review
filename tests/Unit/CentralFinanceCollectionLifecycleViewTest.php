<?php

namespace Tests\Unit;

use App\Services\CentralFinanceCurrencySummaryService;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Process\Process;
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

    public function test_student_collection_form_blade_compiles_to_valid_php(): void
    {
        $compiled = Blade::compileString(file_get_contents(resource_path('views/central-finance/student-collection/show.blade.php')));
        $path = tempnam(sys_get_temp_dir(), 'eschool-student-collection-');
        file_put_contents($path, $compiled);

        try {
            $lint = new Process([PHP_BINARY, '-l', $path]);
            $lint->run();
            $this->assertTrue($lint->isSuccessful(), $lint->getErrorOutput().$lint->getOutput());
        } finally {
            @unlink($path);
        }
    }

    public function test_confirmed_finance_receipt_uses_the_same_80mm_thermal_contract_as_the_collection_receipt(): void
    {
        $receiptView = file_get_contents(resource_path('views/central-finance/receipt.blade.php'));
        $document = file_get_contents(resource_path('views/central-finance/partials/receipt-document.blade.php'));

        $this->assertStringContainsString('@page { size: 80mm auto; margin: 0; }', $receiptView);
        $this->assertStringContainsString('width: 80mm;', $receiptView);
        $this->assertStringContainsString('window.print()', $receiptView);
        $this->assertStringContainsString('Finance Confirmed', $document);
        $this->assertStringContainsString('Payment Effective Date', $document);
        $this->assertStringContainsString('Receipt Issued At', $document);
        $this->assertStringContainsString('does not create another payment when reprinted', $document);
    }

    public function test_group_import_preview_exposes_the_transaction_date_that_will_drive_posting(): void
    {
        $view = file_get_contents(resource_path('views/central-finance/group-import/index.blade.php'));

        $this->assertStringContainsString("<th>{{ __('Transaction Date') }}</th>", $view);
        $this->assertStringContainsString("data-label=\"{{ __('Transaction Date') }}\">{{ \$row->normalized_data['transaction_date'] ?? '—' }}", $view);
    }
}
