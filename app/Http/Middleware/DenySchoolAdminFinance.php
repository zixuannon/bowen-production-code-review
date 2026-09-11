<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DenySchoolAdminFinance
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->hasRole('School Admin') && !$user->hasRole('Super Admin')) {
            abort(403, 'School Admin is not authorized for Finance.');
        }

        return $next($request);
    }
}
