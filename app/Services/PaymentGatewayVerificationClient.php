<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class PaymentGatewayVerificationClient
{
    private const PAYSTACK_BASE_URL = 'https://api.paystack.co';
    private const FLUTTERWAVE_BASE_URL = 'https://api.flutterwave.com';

    public function verifyPaystack(string $secretKey, string $reference): Response
    {
        $reference = $this->validateReference($reference);

        return $this->client($secretKey, self::PAYSTACK_BASE_URL)
            ->get('/transaction/verify/'.rawurlencode($reference));
    }

    public function verifyFlutterwave(string $secretKey, string $transactionId): Response
    {
        if (!preg_match('/\A[0-9]{1,40}\z/D', $transactionId)) {
            throw new InvalidArgumentException('Invalid Flutterwave transaction identifier.');
        }

        return $this->client($secretKey, self::FLUTTERWAVE_BASE_URL)
            ->get('/v3/transactions/'.rawurlencode($transactionId).'/verify');
    }

    private function client(string $secretKey, string $baseUrl)
    {
        return Http::baseUrl($baseUrl)
            ->withHeaders([
                'Authorization' => 'Bearer '.$secretKey,
                'Accept' => 'application/json',
            ])
            ->withOptions(['allow_redirects' => false]);
    }

    private function validateReference(string $reference): string
    {
        if (!preg_match('/\A[A-Za-z0-9._=-]{1,100}\z/D', $reference)) {
            throw new InvalidArgumentException('Invalid Paystack transaction reference.');
        }

        return $reference;
    }
}
