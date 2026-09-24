<?php

namespace App\Services;

use App\Http\Middleware\RequireApiFamily;
use App\Models\School;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Resolves an actor and a canonical target School separately.  A raw session
 * database name is never treated as proof that the actor may use that tenant.
 */
final class TrustedTenantContextService
{
    private const REQUEST_ACTIVE = '_trusted_tenant_context_active';

    public function __construct(private readonly TenantConnectionScope $connections) {}

    /**
     * Returns the trusted tenant target for the current request only after this
     * service has configured the tenant connection. Consumers such as global
     * view composers must use this instead of treating a session connection
     * name (or an authenticated User model) as proof of tenant context.
     */
    public function trustedSchoolIdForCurrentRequest(Request $request): ?int
    {
        $schoolId = $request->attributes->get('trusted_tenant_school_id');

        if ($request->attributes->get(self::REQUEST_ACTIVE) !== true
            || filter_var($schoolId, FILTER_VALIDATE_INT) === false
            || (int) $schoolId <= 0
            || DB::getDefaultConnection() !== 'school'
            || trim((string) config('database.connections.school.database')) === '') {
            return null;
        }

        return (int) $schoolId;
    }

    /**
     * Resolves the tenant User only after the request has a trusted initialized
     * tenant context. This prevents global consumers from dereferencing a
     * session-aware User model while its school connection has no database.
     */
    public function trustedTenantUserForCurrentRequest(Request $request): ?User
    {
        $schoolId = $this->trustedSchoolIdForCurrentRequest($request);
        if ($schoolId === null) {
            return null;
        }

        $user = Auth::user();

        if (! $user || (int) $user->getRawOriginal('school_id') !== $schoolId) {
            return null;
        }

        return $user;
    }

    /**
     * Error views must never invoke Laravel's session guard against an
     * unconfigured tenant connection. Tenant users are safe only through the
     * trusted request contract; central users are safe only while mysql is the
     * active connection and the session does not name the tenant resolver.
     */
    public function hasSafeAuthenticatedUserForErrorPage(Request $request): bool
    {
        if ($this->trustedTenantUserForCurrentRequest($request) !== null) {
            return true;
        }

        if (($request->hasSession() && $request->session()->get('db_connection_name') === 'school')
            || DB::getDefaultConnection() !== 'mysql') {
            return false;
        }

        return Auth::check();
    }

    /** @template T @param Closure():T $callback @return T */
    public function forWebRequest(Request $request, Closure $callback): mixed
    {
        if ($request->attributes->get(self::REQUEST_ACTIVE) === true) {
            return $callback();
        }

        $database = trim((string) $request->session()->get('school_database_name'));
        if ($database === '') {
            // A worker may have previously processed a tenant request. Central
            // routes must never inherit that default connection.
            return $this->connections->preserve(function () use ($callback): mixed {
                DB::setDefaultConnection('mysql');

                return $callback();
            });
        }

        $school = $this->schoolForDatabase($database);
        $actorId = $request->session()->get(Auth::getName());
        if (!is_numeric($actorId)) {
            // Guests must never be switched into a tenant from an old session value.
            return $this->connections->preserve(function () use ($callback): mixed {
                DB::setDefaultConnection('mysql');

                return $callback();
            });
        }

        // App\Models\User resolves its connection from db_connection_name in
        // the session.  This pre-scope registry lookup must not use that
        // model: a retained tenant session intentionally says "school", but
        // TenantConnectionScope has not configured that connection yet.
        // Pin the lookup to Central mysql without changing session identity.
        $actor = DB::connection('mysql')->table('users')
            ->select(['id', 'school_id'])
            ->where('id', (int) $actorId)
            ->whereNull('deleted_at')
            ->first();

        if (!$actor || $actor->school_id === null || (int) $actor->school_id !== (int) $school->id) {
            throw new AuthorizationException('The authenticated actor is not authorized for the requested School context.');
        }

        return $this->connections->forSchool($school, function () use ($request, $callback, $school): mixed {
            $priorConnectionName = $request->session()->get('db_connection_name');
            $request->session()->put('db_connection_name', 'school');
            $request->attributes->set(self::REQUEST_ACTIVE, true);
            $request->attributes->set('trusted_tenant_school_id', (int) $school->id);
            Auth::forgetUser();

            try {
                return $callback();
            } finally {
                Auth::forgetUser();
                $request->attributes->remove(self::REQUEST_ACTIVE);
                $request->attributes->remove('trusted_tenant_school_id');
                if ($priorConnectionName === null) {
                    $request->session()->forget('db_connection_name');
                } else {
                    $request->session()->put('db_connection_name', $priorConnectionName);
                }
            }
        });
    }

    /** @template T @param Closure():T $callback @return T */
    public function forApiRequest(Request $request, Closure $callback): mixed
    {
        $token = trim((string) $request->bearerToken());
        if ($token === '') {
            abort(401, 'Unauthenticated.');
        }

        $school = $this->canonicalSchool((string) $request->header('school-code'));
        $family = $this->apiFamily($request);

        // Sanctum personal tokens are tenant-local. Resolve the token using an
        // isolated explicit connection without changing the request default
        // connection; the context is restored before authorization returns.
        [$principal, $accessToken] = $this->connections->forSchool($school, function () use ($token, $school, $family): array {
            $accessToken = $this->findTenantToken($token);
            if (!$accessToken || $accessToken->tokenable_type !== User::class) {
                abort(401, 'Unauthenticated.');
            }

            $principal = User::on('school')->find($accessToken->tokenable_id);
            if (!$principal || (int) $principal->getRawOriginal('school_id') !== (int) $school->id) {
                throw new AccessDeniedHttpException('The API token is not authorized for this School.');
            }

            $principal->withAccessToken($accessToken);
            RequireApiFamily::assertAuthorized($principal, $family);

            return [$principal, $accessToken];
        });

        return $this->connections->forSchool($school, function () use ($request, $callback, $principal, $accessToken, $school): mixed {
            $previousUser = Auth::user();
            $principal->withAccessToken($accessToken);
            Auth::setUser($principal);
            $request->attributes->set(self::REQUEST_ACTIVE, true);
            $request->attributes->set('trusted_tenant_school_id', (int) $school->id);

            try {
                if ($response = $this->demoModeResponse($request)) {
                    return $response;
                }

                return $callback();
            } finally {
                $request->attributes->remove(self::REQUEST_ACTIVE);
                $request->attributes->remove('trusted_tenant_school_id');
                if ($previousUser) {
                    Auth::setUser($previousUser);
                } else {
                    Auth::forgetUser();
                }
            }
        });
    }

    private function canonicalSchool(string $code): School
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            abort(400, 'School Code is Required');
        }

        $school = School::on('mysql')->whereCanonicalCode($code)->first();
        if (!$school) {
            abort(400, 'Invalid school code');
        }

        return $school;
    }

    private function schoolForDatabase(string $database): School
    {
        $schools = School::on('mysql')->where('database_name', $database)->get();
        if ($schools->count() !== 1) {
            throw new AuthorizationException('The requested tenant context is not registered.');
        }

        return $schools->first();
    }

    private function apiFamily(Request $request): string
    {
        $route = $request->route();
        $middleware = $route ? $route->gatherMiddleware() : [];

        if ($route && $middleware === []) {
            $middleware = $route->middleware();
        }

        foreach ($middleware as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            if (str_starts_with($entry, 'apiFamily:')) {
                return substr($entry, strlen('apiFamily:'));
            }

            if (str_starts_with($entry, RequireApiFamily::class.':')) {
                return substr($entry, strlen(RequireApiFamily::class.':'));
            }
        }

        throw new AccessDeniedHttpException('This API route has no authorized API family.');
    }

    private function demoModeResponse(Request $request): mixed
    {
        if (! env('DEMO_MODE') || $request->isMethod('get') || ! Auth::user()) {
            return null;
        }

        $excludedUris = [
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
            '/api/transportation/live-route',
        ];

        if (in_array($request->getRequestUri(), $excludedUris, true)) {
            return null;
        }

        return response()->json([
            'error' => true,
            'message' => 'This is not allowed in the Demo Version.',
            'code' => 112,
        ]);
    }

    private function findTenantToken(string $plainTextToken): ?PersonalAccessToken
    {
        if (str_contains($plainTextToken, '|')) {
            [$id, $token] = explode('|', $plainTextToken, 2);
            if (ctype_digit($id)) {
                $accessToken = PersonalAccessToken::on('school')->find((int) $id);
                if ($accessToken && hash_equals($accessToken->token, hash('sha256', $token))) {
                    return $accessToken;
                }
            }

            return null;
        }

        return PersonalAccessToken::on('school')
            ->where('token', hash('sha256', $plainTextToken))
            ->first();
    }
}
