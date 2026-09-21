<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\TrustedTenantContextService;
use Symfony\Component\HttpFoundation\Response;

class APISwitchDatabase
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return app(TrustedTenantContextService::class)
            ->forApiRequest($request, fn (): Response => $next($request));
    }
}
