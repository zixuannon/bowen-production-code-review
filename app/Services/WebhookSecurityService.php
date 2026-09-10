<?php

namespace App\Services;

use App\Models\PaymentTransaction;
use RuntimeException;

final class WebhookSecurityService
{
    public function verifyRazorpay(string $rawBody, ?string $signature, string $secret): void
    {
        $this->verifyHexHmac('sha256', $rawBody, $signature, $secret, 'Razorpay');
    }

    public function verifyPaystack(string $rawBody, ?string $signature, string $secret): void
    {
        $this->verifyHexHmac('sha512', $rawBody, $signature, $secret, 'Paystack');
    }

    public function verifyFlutterwave(string $rawBody, ?string $signature, string $secret): void
    {
        if ($secret === '' || !$signature) {
            throw new RuntimeException('Flutterwave webhook signature is missing.');
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        if (!hash_equals($expected, trim($signature))) {
            throw new RuntimeException('Flutterwave webhook signature is invalid.');
        }
    }

    /**
     * Confirm that a signed/verified provider transaction can settle one
     * existing server-created pending transaction only.
     *
     * @param array{reference:mixed,amount:mixed,currency:mixed,status:mixed} $provider
     */
    public function assertPendingTransaction(
        PaymentTransaction $transaction,
        string $gateway,
        string $reference,
        int $schoolId,
        string $expectedCurrency,
        array $provider,
        bool $providerAmountIsMinorUnits = false
    ): void {
        if (strtolower((string) $transaction->payment_status) !== 'pending') {
            throw new RuntimeException('Payment transaction is not pending.');
        }
        $this->assertTransactionMatches(
            $transaction,
            $gateway,
            $reference,
            $schoolId,
            $expectedCurrency,
            $provider,
            $providerAmountIsMinorUnits
        );
    }

    /**
     * Validate immutable provider identity/amount fields. Status locking belongs
     * to PaymentTransactionExactlyOnceService so a valid replay can return 200.
     *
     * @param array{reference:mixed,amount:mixed,currency:mixed,status:mixed} $provider
     */
    public function assertTransactionMatches(
        PaymentTransaction $transaction,
        string $gateway,
        string $reference,
        int $schoolId,
        string $expectedCurrency,
        array $provider,
        bool $providerAmountIsMinorUnits = false
    ): void {
        if (strcasecmp((string) $transaction->payment_gateway, $gateway) !== 0) {
            throw new RuntimeException('Payment gateway does not match the pending transaction.');
        }
        if (!hash_equals((string) $transaction->order_id, $reference)
            || !hash_equals($reference, (string) ($provider['reference'] ?? ''))) {
            throw new RuntimeException('Payment reference does not match the pending transaction.');
        }
        if ((int) $transaction->school_id !== $schoolId) {
            throw new RuntimeException('Payment tenant does not match the pending transaction.');
        }
        if (strtolower((string) ($provider['status'] ?? '')) !== 'successful'
            && strtolower((string) ($provider['status'] ?? '')) !== 'success'
            && strtolower((string) ($provider['status'] ?? '')) !== 'succeeded'
            && strtolower((string) ($provider['status'] ?? '')) !== 'captured') {
            throw new RuntimeException('Provider transaction is not successful.');
        }
        if (strtoupper((string) ($provider['currency'] ?? '')) !== strtoupper($expectedCurrency)) {
            throw new RuntimeException('Payment currency does not match server configuration.');
        }

        $expectedAmount = (float) $transaction->amount;
        $providerAmount = (float) ($provider['amount'] ?? -1);
        if ($providerAmountIsMinorUnits) {
            $matches = (int) round($expectedAmount * 100) === (int) round($providerAmount);
        } else {
            $matches = abs($expectedAmount - $providerAmount) < 0.00001;
        }
        if (!$matches) {
            throw new RuntimeException('Payment amount does not match the pending transaction.');
        }
    }

    private function verifyHexHmac(string $algorithm, string $rawBody, ?string $signature, string $secret, string $provider): void
    {
        if ($secret === '' || !$signature) {
            throw new RuntimeException("{$provider} webhook signature is missing.");
        }
        $expected = hash_hmac($algorithm, $rawBody, $secret);
        if (!hash_equals($expected, strtolower(trim($signature)))) {
            throw new RuntimeException("{$provider} webhook signature is invalid.");
        }
    }
}
