<?php

namespace App\Http\Middleware;

use App\Models\Language;
use Auth;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;

class LanguageManager
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure(Request): (Response|RedirectResponse) $next
     * @return Response|RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {

        try {            
            if (Session::has('locale') && Auth::user() && Session::get('locale') == Auth::user()->language) {
                // Admin dashboard
                app()->setLocale($this->resolvedLocale($request, (string) Session::get('locale')));
            } else {
                // When users log in to the system, make sure to set their preferred panel language.
                if (Auth::user()) {
                    Session::put('locale', Auth::user()->language);
                    Session::save();
                    $language = Language::where('code', Auth::user()->language)->first();
                    Session::put('language', $language);
                    app()->setLocale($this->resolvedLocale($request, (string) Auth::user()->language));
                } else {
                    // Landing page
                    if (Session::has('landing_locale')) {
                        app()->setLocale(Session::get('landing_locale'));
                    } else {
                        $lang = env('APPLANG');
                        if (is_null($lang)) {
                            $lang = "en";
                        }

                        $language = Language::where('code', $lang)->first();
                        Session::put('landing_locale', $lang);
                        Session::save();
                        Session::put('language', $language);
                        app()->setLocale(Session::get('landing_locale'));
                    }
                }
            }
        } catch (\Throwable $th) {
            if (Session::has('locale')) {
                app()->setLocale($this->resolvedLocale($request, (string) Session::get('locale')));
            }
        }
        return $next($request);
    }

    /**
     * The installed School-language record uses the historic `cn` code, while
     * Central Finance's audited JSON catalog is maintained as `zh-cn`.
     * Normalize only the Central Finance workspace so legacy School pages keep
     * their existing locale contract.
     */
    private function resolvedLocale(Request $request, string $locale): string
    {
        return $request->routeIs('central-finance.*') && strtolower($locale) === 'cn'
            ? 'zh-cn'
            : $locale;
    }
}
