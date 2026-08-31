<?php

namespace App\Jobs;

use App\Services\XiaobailongRequestSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SendXiaobailongLifecycleEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 15;

    public function __construct(public array $payload)
    {
        // Queueable already owns the $afterCommit property. Calling the
        // framework method avoids a PHP 8.3 trait-property type conflict and
        // keeps the guarantee even if a future caller omits ->afterCommit().
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    public function handle(XiaobailongRequestSigner $signer): void
    {
        $eventType = (string) ($this->payload['event_type'] ?? '');
        $url = match ($eventType) {
            'teacher_status_changed' => (string) config('xiaobailong.status_webhook_url'),
            'teacher_logout' => (string) config('xiaobailong.logout_webhook_url'),
            default => '',
        };
        if (!$this->validHttpsUrl($url)) {
            throw new RuntimeException('xiaobailong_lifecycle_url_invalid');
        }

        $signed = $signer->sign($this->payload);
        $response = Http::withHeaders($signed['headers'])
            ->withBody($signed['body'], 'application/json')
            ->connectTimeout(2)
            ->timeout(max(2, min(10, (int) config('xiaobailong.notification_timeout_seconds', 4))))
            ->send('POST', $url);
        if (!$response->successful()) {
            // Throwing is intentional: the queue must retry transient 4xx/5xx
            // responses. The XBL event_id makes every attempt idempotent.
            throw new RuntimeException('xiaobailong_lifecycle_http_' . $response->status());
        }
    }

    public function failed(Throwable $error): void
    {
        // Do not log teacher, school, URL, body, signature, or exception text.
        Log::error('Xiaobailong lifecycle event permanently failed.', [
            'event_id' => $this->payload['event_id'] ?? 'unknown',
            'event_type' => $this->payload['event_type'] ?? 'unknown',
        ]);
    }

    private function validHttpsUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && empty($parts['user'])
            && empty($parts['pass'])
            && !empty($parts['host']);
    }
}
