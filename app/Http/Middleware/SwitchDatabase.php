<?php

namespace App\Http\Middleware;

use App\Services\CachingService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SwitchDatabase
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {

        if ($request->session()->get('school_database_name')) {
            if (Auth::user()) {
                return $next($request);
            }

            return redirect()->back()->with('error','Invalid credential.');
        }

        return $next($request);
    }
}
