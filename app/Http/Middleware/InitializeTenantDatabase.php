<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class InitializeTenantDatabase
{
    /**
     * Establish the school connection before any web middleware resolves the
     * authenticated user. Spatie role relations are tenant-local, so a user
     * resolved on mysql must never survive a switch to the school connection.
     */
    public function handle(Request $request, Closure $next)
    {
        $database = $request->session()->get('school_database_name');

        if (!$database) {
            DB::setDefaultConnection('mysql');

            return $next($request);
        }

        Config::set('database.connections.school.database', $database);
        DB::purge('school');
        DB::connection('school')->reconnect();
        DB::setDefaultConnection('school');

        // Do not retain a guard user (or its loaded Spatie roles relation)
        // resolved before this tenant context was established. The session ID
        // remains intact and the next Auth::user() reloads from this tenant.
        Auth::forgetUser();

        return $next($request);
    }
}
