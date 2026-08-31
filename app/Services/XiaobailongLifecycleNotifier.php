<?php

namespace App\Services;

use App\Jobs\SendXiaobailongLifecycleEvent;
use App\Models\School;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class XiaobailongLifecycleNotifier
{
    public function deferStatus(int $schoolId, int $teacherId, string $accountStatus): void
    {
        if (!config('xiaobailong.enabled') || !config('xiaobailong.lifecycle_notifications_enabled')) {
            return;
        }

        $school = School::on('mysql')->find($schoolId);
        if (!$school) {
            return;
        }
        $this->dispatch([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'teacher_status_changed',
            'claims_version' => XiaobailongClaimsService::CLAIMS_VERSION,
            'school_code' => (string) $school->code,
            'teacher_id' => (string) $teacherId,
            'account_status' => $accountStatus,
            'occurred_at' => now()->utc()->toIso8601String(),
        ]);
    }

    public function deferLogout(int $schoolId, int $teacherId): void
    {
        if (!config('xiaobailong.enabled') || !config('xiaobailong.lifecycle_notifications_enabled')) {
            return;
        }

        $school = School::on('mysql')->find($schoolId);
        if (!$school) {
            return;
        }
        $this->dispatch([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'teacher_logout',
            'claims_version' => XiaobailongClaimsService::CLAIMS_VERSION,
            'school_code' => (string) $school->code,
            'teacher_id' => (string) $teacherId,
            'occurred_at' => now()->utc()->toIso8601String(),
        ]);
    }

    private function dispatch(array $payload): void
    {
        try {
            SendXiaobailongLifecycleEvent::dispatch($payload)->afterCommit();
        } catch (Throwable) {
            // Queue-enqueue failures are covered by XBL's mandatory refresh
            // introspection. Log only the id/type so no identity data leaks.
            Log::error('Xiaobailong lifecycle event could not be queued.', [
                'event_id' => $payload['event_id'] ?? 'unknown',
                'event_type' => $payload['event_type'] ?? 'unknown',
            ]);
        }
    }
}
