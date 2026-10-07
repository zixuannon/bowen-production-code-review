<?php

namespace App\Http\Middleware;

use App\Services\SchoolHostResolver;
use Closure;
use Illuminate\Http\Request;

final class ResolveSchoolHost
{
    public function handle(Request $request, Closure $next)
    {
        $school = app(SchoolHostResolver::class)->schoolForRequestHost($request->getHost());
        if ($school !== null) {
            $request->attributes->set('resolved_school_host_id', (int) $school->id);
            $request->attributes->set('resolved_school_host_type', (string) $school->domain_type);
        }

        return $next($request);
    }
}
