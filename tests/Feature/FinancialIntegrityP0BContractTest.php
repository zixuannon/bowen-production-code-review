<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class FinancialIntegrityP0BContractTest extends TestCase
{
    public function test_webhooks_use_row_locks_correct_status_and_never_create_transactions(): void
    {
        $fee = file_get_contents(__DIR__.'/../../app/Http/Controllers/WebhookController.php');
        $subscription = file_get_contents(__DIR__.'/../../app/Http/Controllers/SubscriptionWebhookController.php');
        $service = file_get_contents(__DIR__.'/../../app/Services/PaymentTransactionExactlyOnceService.php');

        $this->assertStringNotContainsString('PaymentTransaction::create', $fee.$subscription);
        $this->assertStringNotContainsString('paymentTransactionData->status', $fee.$subscription);
        $this->assertStringContainsString('payment_status', $fee.$subscription);
        $this->assertStringContainsString('lockForUpdate()', $fee);
        $this->assertStringContainsString('lockForUpdate()', $subscription);
        $this->assertStringContainsString('lockForUpdate()', $service);
        $this->assertStringContainsString("status === 'succeed'", $service);
        $this->assertStringContainsString('hash_equals($calculatedSignature, $expectedSignature)', $fee);
        $this->assertStringContainsString("response()->json(['error' => 'Invalid signature'], 400)", $fee);
        $this->assertStringContainsString("response()->json(['error' => 'Invalid webhook configuration'], 400)", $fee);
    }

    public function test_offline_authority_rebuilds_amounts_from_locked_fee_setup(): void
    {
        $source = file_get_contents(__DIR__.'/../../app/Services/OfflineFeePaymentAuthorityService.php');
        $controller = file_get_contents(__DIR__.'/../../app/Http/Controllers/FeesController.php');

        $this->assertStringContainsString("->where('optional', false)", $source);
        $this->assertStringContainsString("->where('optional', true)", $source);
        $this->assertStringContainsString('installment_amount', $source);
        $this->assertStringContainsString('lockForUpdate()', $source);
        $this->assertStringContainsString('Payment currency does not match Fee Setup.', $source);
        $this->assertStringContainsString('OfflineFeePaymentAuthorityService', $controller);
    }
}
