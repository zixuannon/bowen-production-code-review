<?php

namespace Tests\Feature;

use App\Services\PaymentGatewayVerificationClient;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class PaymentGatewayVerificationClientTest extends TestCase
{
    public function test_paystack_verification_uses_only_the_fixed_gateway_host(): void
    {
        Http::fake(['https://api.paystack.co/*' => Http::response(['status' => true])]);

        app(PaymentGatewayVerificationClient::class)->verifyPaystack('secret', 'QA-ref_1.2=ok');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.paystack.co/transaction/verify/QA-ref_1.2%3Dok');
    }

    public function test_flutterwave_verification_uses_only_the_fixed_gateway_host(): void
    {
        Http::fake(['https://api.flutterwave.com/*' => Http::response(['status' => 'success'])]);

        app(PaymentGatewayVerificationClient::class)->verifyFlutterwave('secret', '123456789');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.flutterwave.com/v3/transactions/123456789/verify');
    }

    /** @dataProvider maliciousPaystackReferences */
    public function test_paystack_rejects_path_or_host_injection(string $reference): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        try {
            app(PaymentGatewayVerificationClient::class)->verifyPaystack('secret', $reference);
        } finally {
            Http::assertNothingSent();
        }
    }

    public static function maliciousPaystackReferences(): array
    {
        return [
            ['https://attacker.example/'],
            ['//attacker.example/path'],
            ['../metadata'],
            ["valid\r\nHost: attacker.example"],
            ['valid?redirect=https://attacker.example'],
            ['valid%2f..%2fmetadata'],
        ];
    }

    /** @dataProvider maliciousFlutterwaveIds */
    public function test_flutterwave_rejects_non_numeric_transaction_ids(string $transactionId): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        try {
            app(PaymentGatewayVerificationClient::class)->verifyFlutterwave('secret', $transactionId);
        } finally {
            Http::assertNothingSent();
        }
    }

    public static function maliciousFlutterwaveIds(): array
    {
        return [[''], ['../1'], ['https://attacker.example'], ['1?next=evil'], ['1%2fverify']];
    }
}
