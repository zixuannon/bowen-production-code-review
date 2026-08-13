<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class XiaobailongServiceAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('xiaobailong.service_secret');
        if (strlen($secret) < 32) {
            return $this->deny('service_not_configured', 503);
        }

        $timestamp = (string) $request->header('X-Xiaobailong-Timestamp', '');
        $nonce = (string) $request->header('X-Xiaobailong-Nonce', '');
        $signature = strtolower((string) $request->header('X-Xiaobailong-Signature', ''));
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 60 ||
            !preg_match('/^[A-Za-z0-9_-]{20,64}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return $this->deny('invalid_service_signature', 401);
        }

        $bodyHash = hash('sha256', $request->getContent());
        $expected = hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $bodyHash, $secret);
        if (!hash_equals($expected, $signature)) {
            return $this->deny('invalid_service_signature', 401);
        }

        $nonceKey = 'xiaobailong:sso:nonce:' . hash('sha256', $nonce);
        $stored = Redis::connection('cache')->eval(
            "local result = redis.call('SET', KEYS[1], '1', 'EX', 120, 'NX'); if result then return 1 else return 0 end",
            1,
            $nonceKey
        );
        if (!$stored) {
            return $this->deny('replayed_service_request', 409);
        }

        return $next($request);
    }

    private function deny(string $error, int $status): JsonResponse
    {
        return response()->json(['error' => $error], $status, ['Cache-Control' => 'no-store']);
    }
}
