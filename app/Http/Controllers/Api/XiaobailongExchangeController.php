<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class XiaobailongExchangeController extends Controller
{
    public function exchange(Request $request): JsonResponse
    {
        abort_unless(config('xiaobailong.enabled'), 404);

        $code = (string) $request->input('code', '');
        if (!preg_match('/^[A-Za-z0-9]{64}$/', $code)) {
            return response()->json(['error' => 'invalid_sso_code'], 400);
        }

        $key = 'xiaobailong:sso:code:' . hash('sha256', $code);
        $script = "local value = redis.call('GET', KEYS[1]); if value then redis.call('DEL', KEYS[1]); end; return value";
        $raw = Redis::connection('cache')->eval($script, 1, $key);
        if (!$raw) {
            return response()->json(['error' => 'invalid_or_expired_sso_code'], 410, ['Cache-Control' => 'no-store']);
        }

        $ticket = json_decode($raw, true);
        if (!is_array($ticket) || ($ticket['issuer'] ?? null) !== config('xiaobailong.issuer') ||
            (int) ($ticket['expires_at'] ?? 0) < time()) {
            return response()->json(['error' => 'invalid_or_expired_sso_code'], 410, ['Cache-Control' => 'no-store']);
        }

        $school = School::on('mysql')->find((int) ($ticket['school_id'] ?? 0));
        if (!$school || (int) $school->status !== 1) {
            return response()->json(['error' => 'school_unavailable'], 403);
        }

        Config::set('database.connections.school.database', $school->database_name);
        DB::purge('school');
        DB::connection('school')->reconnect();
        DB::setDefaultConnection('school');

        $teacher = User::on('school')
            ->whereKey((int) ($ticket['teacher_id'] ?? 0))
            ->where('school_id', $school->id)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->first();

        if (!$teacher || !$teacher->hasRole('Teacher') || !$teacher->can('xiaobailong-use')) {
            return response()->json(['error' => 'teacher_not_authorized'], 403, ['Cache-Control' => 'no-store']);
        }

        $displayName = trim((string) $teacher->first_name . ' ' . (string) $teacher->last_name);

        return response()->json([
            'school_code' => (string) $school->code,
            'school_name' => (string) $school->name,
            'teacher_id' => (string) $teacher->id,
            'display_name' => $displayName !== '' ? $displayName : 'Bowen 教师',
            'role' => 'Teacher',
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
