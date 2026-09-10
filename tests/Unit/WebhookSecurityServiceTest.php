<?php

namespace Tests\Unit;

use App\Models\PaymentTransaction;
use App\Services\WebhookSecurityService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WebhookSecurityServiceTest extends TestCase
{
    private WebhookSecurityService $security;

    protected function setUp(): void
    {
        parent::setUp();
        $this->security = new WebhookSecurityService();
    }

    public function test_real_provider_signatures_are_verified_against_the_raw_body(): void
    {
        $raw = '{"event":"charge.success","amount":123}';
        $secret = 'webhook-secret';

        $this->security->verifyRazorpay($raw, hash_hmac('sha256', $raw, $secret), $secret);
        $this->security->verifyPaystack($raw, hash_hmac('sha512', $raw, $secret), $secret);
        $this->security->verifyFlutterwave($raw, base64_encode(hash_hmac('sha256', $raw, $secret, true)), $secret);
        $this->addToAssertionCount(3);
    }

    public function test_forged_or_missing_provider_signatures_are_rejected(): void
    {
        foreach (['verifyRazorpay', 'verifyPaystack', 'verifyFlutterwave'] as $method) {
            foreach ([null, 'attacker-signature'] as $signature) {
                try {
                    $this->security->{$method}('{"event":"charge.success"}', $signature, 'secret');
                    $this->fail("{$method} accepted an invalid signature.");
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('signature', $exception->getMessage());
                }
            }
        }
    }

    public function test_verified_payment_must_match_existing_pending_transaction_and_tenant(): void
    {
        $transaction = new PaymentTransaction([
            'amount' => '125.50',
            'payment_gateway' => 'Flutterwave',
            'order_id' => 'FLW-safe-ref',
            'payment_status' => 'Pending',
            'school_id' => 15,
        ]);

        $this->security->assertPendingTransaction($transaction, 'Flutterwave', 'FLW-safe-ref', 15, 'MMK', [
            'reference' => 'FLW-safe-ref',
            'amount' => '125.50',
            'currency' => 'MMK',
            'status' => 'successful',
        ]);
        $this->addToAssertionCount(1);
    }

    /** @dataProvider mismatchedProviderData */
    public function test_amount_currency_reference_status_gateway_and_tenant_mismatches_are_rejected(array $changes): void
    {
        $transaction = new PaymentTransaction([
            'amount' => '125.50', 'payment_gateway' => 'Paystack', 'order_id' => 'PSK-ref',
            'payment_status' => 'pending', 'school_id' => 15,
        ]);
        foreach ($changes['transaction'] ?? [] as $key => $value) {
            $transaction->{$key} = $value;
        }
        $provider = array_merge([
            'reference' => 'PSK-ref', 'amount' => 12550, 'currency' => 'MMK', 'status' => 'success',
        ], $changes['provider'] ?? []);

        $this->expectException(RuntimeException::class);
        $this->security->assertPendingTransaction(
            $transaction,
            $changes['gateway'] ?? 'Paystack',
            $changes['reference'] ?? 'PSK-ref',
            $changes['school_id'] ?? 15,
            'MMK',
            $provider,
            true
        );
    }

    public static function mismatchedProviderData(): array
    {
        return [
            'not pending' => [['transaction' => ['payment_status' => 'succeed']]],
            'gateway' => [['transaction' => ['payment_gateway' => 'Flutterwave']]],
            'reference' => [['provider' => ['reference' => 'forged']]],
            'tenant' => [['school_id' => 16]],
            'status' => [['provider' => ['status' => 'pending']]],
            'currency' => [['provider' => ['currency' => 'USD']]],
            'amount' => [['provider' => ['amount' => 12551]]],
        ];
    }
}
