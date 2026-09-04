<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ZixuanStudentFinanceCutoverUxContractTest extends TestCase
{
    public function test_school_student_finance_entry_stays_a_read_bridge_to_central_finance(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string) file_get_contents($root.'/resources/views/students/finance-summary.blade.php');

        $this->assertStringContainsString("route('central-finance.student-collection.show'", $view);
        $this->assertStringContainsString(__('View Student Finance'), $view);
        $this->assertStringContainsString(__('View Receivables'), $view);
        $this->assertStringContainsString(__('Payment History'), $view);
        $this->assertStringContainsString(__('Receipts'), $view);
        $this->assertStringContainsString(__('Collect Payment'), $view);
        $this->assertStringNotContainsString('fees-paid', $view);
        $this->assertStringNotContainsString('FeesPaymentService', $view);
    }

    public function test_collection_workspace_exposes_canonical_student_identity_and_payment_history(): void
    {
        $root = dirname(__DIR__, 2);
        $index = (string) file_get_contents($root.'/resources/views/central-finance/student-collection/index.blade.php');
        $show = (string) file_get_contents($root.'/resources/views/central-finance/student-collection/show.blade.php');

        $this->assertStringContainsString(__('Student Code'), $index);
        $this->assertStringContainsString('No receivables', $index);
        $this->assertStringContainsString('id="receivable-items"', $show);
        $this->assertStringContainsString('id="payment-history"', $show);
        $this->assertStringContainsString(__('Payment method'), $show);
        $this->assertStringContainsString(__('Received by'), $show);
        $this->assertStringContainsString("route('central-finance.payments.receipt'", $show);
        $this->assertStringNotContainsString('FeesPaid', $show);
    }

    public function test_workspace_student_search_accepts_canonical_student_code_without_changing_payment_service(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string) file_get_contents($root.'/app/Http/Controllers/CentralFinanceWorkspaceController.php');
        $collection = (string) file_get_contents($root.'/app/Http/Controllers/CentralFinanceStudentCollectionController.php');

        $this->assertGreaterThanOrEqual(3, substr_count($controller, "->orWhere('student_code'"));
        $this->assertStringContainsString('->where(\'school_id\', $school->id)', $collection);
        $this->assertStringContainsString('CentralFinancePaymentService $payments', $collection);
        $this->assertStringContainsString('student-collection-', $collection);
    }
}
