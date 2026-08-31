<?php

namespace App\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

class XiaobailongRequestSigner
{
    public function __construct(private readonly ?string $configuredSecret = null)
    {
    }

    /**
     * @return array{body:string,headers:array<string,string>}
     * @throws JsonException
     */
    public function sign(array $payload, ?int $timestamp = null, ?string $nonce = null): array
    {
        $secret = $this->configuredSecret ?? (string) config('xiaobailong.service_secret');
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('xiaobailong_service_secret_invalid');
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestampText = (string) ($timestamp ?? time());
        $nonceValue = $nonce ?? Str::random(32);
        if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $nonceValue)) {
            throw new InvalidArgumentException('xiaobailong_nonce_invalid');
        }

        $bodyHash = hash('sha256', $body);
        $signature = hash_hmac('sha256', $timestampText . "\n" . $nonceValue . "\n" . $bodyHash, $secret);

        return [
            'body' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Xiaobailong-Timestamp' => $timestampText,
                'X-Xiaobailong-Nonce' => $nonceValue,
                'X-Xiaobailong-Signature' => $signature,
            ],
        ];
    }
}
