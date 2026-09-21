<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\TrustedTenantContextService;

class InitializeTenantDatabase
{
    /**
     * Establish the school connection before any web middleware resolves the
     * authenticated user. Spatie role relations are tenant-local, so a user
     * resolved on mysql must never survive a switch to the school connection.
     */
    public function handle(Request $request, Closure $next)
    {
        return app(TrustedTenantContextService::class)
            ->forWebRequest($request, fn () => $next($request));
    }
}
