<?php

namespace App\Http\Middleware;

use App\Models\School;
use Auth;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
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
        $schoolCode = $request->header('school-code');
        if ($schoolCode) {
            $school = School::on('mysql')->whereCanonicalCode($schoolCode)->first();

            if ($school) {
                DB::setDefaultConnection('school');
                Config::set('database.connections.school.database', $school->database_name);
                DB::purge('school');
                DB::connection('school')->reconnect();
                DB::setDefaultConnection('school');
                $token = $request->bearerToken();
                $user = PersonalAccessToken::findToken($token);
                
                if ($user) {
                    $tokenable = $user->tokenable;
                    if (!$tokenable) {
                        return response()->json(array('error' => true, 'message' => 'Unauthenticated.', 'code' => 401), 401);
                    }
                    $tokenable->withAccessToken($user);
                    Auth::setUser($tokenable);
                } else {
                    return response()->json(array('error' => true, 'message' => 'Unauthenticated.', 'code' => 401), 401);
                }

                $exclude_uri = array(
                    '/api/student/login',
                    '/api/parent/login',
                    '/api/teacher/login',
                    '/contact',
                    '/api/student/submit-online-exam-answers',
                    '/api/get-vehicle-assignment-status',
                    '/api/transport/requests',
                    '/api/transport/dashboard',
                    '/api/transport/plans/current',
                    '/api/transport/routes/stops',
                    '/api/transportation/live-route'
                );

                if (env('DEMO_MODE') && !$request->isMethod('get') && Auth::user() && !in_array($request->getRequestUri(), $exclude_uri)) {
                    return response()->json(array(
                        'error'   => true,
                        'message' => "This is not allowed in the Demo Version.",
                        'code'    => 112
                    ));
                }

            } else {
                return response()->json(array('error' => true, 'message' => 'Invalid school code', 'code' => 400));
            }
        } else {
            return response()->json(array('error' => false, 'message' => 'School Code is Required', 'code' => 200));
        }
        return $next($request);
    }
}
