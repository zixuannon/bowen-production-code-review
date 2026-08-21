<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Central Finance routes must never inherit a tenant connection selected by a
 * previous School session. The authenticated session is re-resolved only on
 * the trusted central connection; this is not tenant impersonation.
 */
final class UseCentralFinanceConnection
{
    public function handle(Request $request, Closure $next): Response
    {
        DB::setDefaultConnection('mysql');
        Auth::forgetUser();

        return $next($request);
    }
}
