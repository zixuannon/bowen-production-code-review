<?php

namespace App\Http\Controllers;

use App\Models\School;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\View\View;

class XiaobailongController extends Controller
{
    public function index(): View
    {
        abort_unless(config('xiaobailong.enabled'), 404);
        $user = Auth::user();
        abort_unless($user && $user->hasRole('Teacher') && $user->can('xiaobailong-use'), 403);

        return view('teacher.xiaobailong');
    }

    public function launch(): RedirectResponse
    {
        abort_unless(config('xiaobailong.enabled'), 404);

        $user = Auth::user();
        abort_unless($user && $user->hasRole('Teacher') && $user->can('xiaobailong-use'), 403);

        $school = School::on('mysql')->find($user->school_id);
        abort_unless($school && (int) $school->status === 1, 403);

        $ttl = max(30, min(120, (int) config('xiaobailong.launch_ttl_seconds', 60)));
        $code = Str::random(64);
        $key = 'xiaobailong:sso:code:' . hash('sha256', $code);
        $payload = json_encode([
            'issuer' => config('xiaobailong.issuer', 'bowen'),
            'school_id' => (int) $school->id,
            'teacher_id' => (int) $user->id,
            'issued_at' => time(),
            'expires_at' => time() + $ttl,
        ], JSON_THROW_ON_ERROR);

        Redis::connection('cache')->setex($key, $ttl, $payload);

        $path = '/' . ltrim((string) config('xiaobailong.embed_launch_path'), '/');

        return redirect()->to($path . '?code=' . rawurlencode($code));
    }
}
