<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\School;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DifyTokenMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Validate school-code header
        $schoolCode = $request->header('school-code');
        if (empty($schoolCode)) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'MISSING_SCHOOL_CODE',
                    'message' => '缺少 school-code header',
                ],
            ], 400);
        }

        // 2. Look up school in main database
        $school = School::on('mysql')->where('code', $schoolCode)->first();
        if (!$school) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'INVALID_SCHOOL_CODE',
                    'message' => '无效的 school-code',
                ],
            ], 400);
        }

        // 3. Validate Bearer token
        $bearerToken = $request->bearerToken();
        if (empty($bearerToken)) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'MISSING_TOKEN',
                    'message' => '缺少 Authorization: Bearer token',
                ],
            ], 401);
        }

        // 4. Look up API token in main database
        $apiToken = ApiToken::findValid($bearerToken);
        if (!$apiToken) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'INVALID_TOKEN',
                    'message' => 'API Token 无效或已过期',
                ],
            ], 401);
        }

        // 5. Check token belongs to this school
        if ((int) $apiToken->school_id !== (int) $school->id) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'TOKEN_SCHOOL_MISMATCH',
                    'message' => 'API Token 不属于该学校',
                ],
            ], 403);
        }

        // 6. Check minimum permission
        $requiredPermission = $this->resolvePermission($request);
        if ($requiredPermission && !$apiToken->hasPermission($requiredPermission)) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'INSUFFICIENT_PERMISSION',
                    'message' => "API Token 无权限: {$requiredPermission}",
                ],
            ], 403);
        }

        // 7. Switch to school database
        DB::setDefaultConnection('school');
        Config::set('database.connections.school.database', $school->database_name);
        DB::purge('school');
        DB::connection('school')->reconnect();
        DB::setDefaultConnection('school');

        // 8. Inject school info into request attributes
        $request->attributes->set('dify_school_id', $school->id);
        $request->attributes->set('dify_school_code', $school->code);
        $request->attributes->set('dify_database_name', $school->database_name);
        $request->attributes->set('dify_token_id', $apiToken->id);

        // 9. Update last_used_at
        $apiToken->markUsed();

        return $next($request);
    }

    /**
     * Map route path to required permission.
     */
    protected function resolvePermission(Request $request): ?string
    {
        $path = $request->path();

        $map = [
            'api/dify/admission/today-count'    => 'admission:read',
            'api/dify/admission/list'           => 'admission:read',
            'api/dify/teacher/today-schedule'   => 'timetable:read',
            'api/dify/class/list'               => 'class:read',
            'api/dify/class/student-list'       => 'class:read',
            'api/dify/student/payment-status'   => 'fees:read',
            'api/dify/student/fee-detail'      => 'fees:read',
        ];

        // Strip trailing slash for matching
        $path = rtrim($path, '/');

        return $map[$path] ?? null;
    }
}
