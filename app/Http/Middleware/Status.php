<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class Status {
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure(Request): (Response|RedirectResponse) $next
     * @return Response|RedirectResponse
     */
    public function handle(Request $request, Closure $next) {

        if ($request->session()->get('school_database_name')) {
            if (Auth::user()->status != 1) {
                Auth::logout();
                $request->session()->flush();
                $request->session()->regenerate();
                return redirect()->route('login')->withErrors(trans('your_account_has_been_deactivated_please_contact_admin'));
            }
            
        }

        

        return $next($request);
    }
}
