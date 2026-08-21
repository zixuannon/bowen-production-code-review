<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\CentralFinanceSchoolCutoverService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Blocks tenant Finance mutations after that School has cut over to Central. */
final class EnsureTenantFinanceWritesAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isMethodSafe()) {
            $actor = Auth::user();
            if ($actor instanceof User) {
                app(CentralFinanceSchoolCutoverService::class)->assertTenantFinanceWritesAllowed($actor);
            }
        }

        return $next($request);
    }
}
