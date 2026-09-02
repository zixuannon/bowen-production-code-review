<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CentralFinanceTransferReversalModalTest extends TestCase
{
    public function test_transfer_reversal_uses_the_shared_modal_reason_contract(): void
    {
        $workspace = $this->contents('resources/views/central-finance/workspace.blade.php');
        $component = $this->contents('resources/views/components/central-finance/lifecycle-confirmation.blade.php');

        $transferWorkspace = substr($workspace, (int) strpos($workspace, "@if(\$page === 'transfers')"));

        self::assertStringContainsString("route('central-finance.transfers.reverse'", $transferWorkspace);
        self::assertStringContainsString('data-lifecycle-modal-reason="true"', $transferWorkspace);
        self::assertStringContainsString('data-lifecycle-confirm-label="{{ __(\'Confirm Reversal\') }}"', $transferWorkspace);
        self::assertStringContainsString('type="button" class="btn btn-sm btn-warning" data-lifecycle-open', $transferWorkspace);
        self::assertStringNotContainsString('<input name="reason" class="form-control form-control-sm d-inline-block" style="width:12rem" placeholder="{{ __(\'Reversal reason\') }}" required>', $transferWorkspace);

        self::assertStringContainsString('data-lifecycle-confirm="reason-input"', $component);
        self::assertStringContainsString('pendingModalReason', $component);
        self::assertStringContainsString('hiddenReason.name = \'reason\';', $component);
        self::assertStringContainsString("form.dispatchEvent(new Event('submit', { cancelable: true }))", $component);
        self::assertStringContainsString("var datasetKey = 'lifecycle' + name.replace(/(^|-)([a-z])/g", $component);
        self::assertStringNotContainsString('window.confirm(', $component);
    }

    private function contents(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }
}
