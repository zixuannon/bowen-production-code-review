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

    /**
     * Server-session assertion created only after a canonical School Code
     * login has authenticated a user inside the registered tenant database.
     * It is intentionally distinct from the legacy raw database session key:
     * the latter is routing state, never authorization proof.
     */
    public const TENANT_SESSION_ASSERTION = 'trusted_tenant_login_assertion';

    public function __construct(private readonly TenantConnectionScope $connections) {}

    /**
     * Bind a successful tenant login to one canonical School, one registered
     * tenant database and one active tenant-local User for this session.
     */
    public function establishTenantLoginAssertion(Request $request, School $school, User $user): void
    {
        $this->assertActiveInstalledSchool($school);

        if (! $request->hasSession()
            || (int) $user->getKey() <= 0
            || (int) $user->getRawOriginal('school_id') !== (int) $school->id
            || ! $this->isActiveTenantUser($user)) {
            throw new AuthorizationException('The tenant login identity cannot be trusted.');
        }

        $database = trim((string) $school->database_name);
        if ($database === '') {
            throw new AuthorizationException('The tenant database is not registered.');
        }

        $request->session()->put(self::TENANT_SESSION_ASSERTION, [
            'version' => 1,
            'school_id' => (int) $school->id,
            'database_name' => $database,
            'tenant_user_id' => (int) $user->getKey(),
            'tenant_user_school_id' => (int) $user->getRawOriginal('school_id'),
            // Laravel's authenticated session was regenerated before this is
            // written, so a copied assertion cannot be replayed in another
            // browser session.
            'session_id' => (string) $request->session()->getId(),
        ]);
    }

    /** Remove tenant routing and trust data whenever a tenant session ends. */
    public function clearTenantLoginAssertion(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->forget([
            self::TENANT_SESSION_ASSERTION,
            'school_database_name',
            'db_connection_name',
            Auth::getName(),
            'user_id',
            'user_email',
        ]);
        Auth::forgetUser();
    }

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

        $assertion = $this->tenantAssertion($request);
        if ($assertion === null) {
            // Releases before this compatibility fix created no signed tenant
            // assertion. Only entry routes may clear that stale session so a
            // user can authenticate again; all protected routes fail closed.
            if ($this->isTenantRecoveryEntryRequest($request)) {
                $this->clearTenantLoginAssertion($request);

                return $this->connections->preserve(function () use ($callback): mixed {
                    DB::setDefaultConnection('mysql');

                    return $callback();
                });
            }

            throw new AuthorizationException('The tenant session assertion is missing or invalid.');
        }

        if (! hash_equals($assertion['database_name'], $database)
            || $request->session()->get('db_connection_name') !== 'school'
            || (string) $request->session()->getId() !== $assertion['session_id']
            || (string) $request->session()->get(Auth::getName()) !== (string) $assertion['tenant_user_id']) {
            throw new AuthorizationException('The tenant session assertion does not match the current session.');
        }

        $school = $this->schoolForDatabase($database);
        if ((int) $school->id !== $assertion['school_id']) {
            throw new AuthorizationException('The tenant session assertion targets a different School.');
        }
        $resolvedHostSchoolId = $request->attributes->get('resolved_school_host_id');
        if ($resolvedHostSchoolId !== null && (int) $resolvedHostSchoolId !== (int) $school->id) {
            throw new AuthorizationException('The current School host does not match the authenticated tenant session.');
        }
        $this->assertActiveInstalledSchool($school);

        return $this->connections->forSchool($school, function () use ($request, $callback, $school, $assertion): mixed {
            $priorConnectionName = $request->session()->get('db_connection_name');
            $request->session()->put('db_connection_name', 'school');
            $request->attributes->set(self::REQUEST_ACTIVE, true);
            $request->attributes->set('trusted_tenant_school_id', (int) $school->id);
            Auth::forgetUser();

            try {
                $tenantUser = User::on('school')
                    ->whereKey($assertion['tenant_user_id'])
                    ->where('school_id', $school->id)
                    ->first();
                if (! $tenantUser
                    || ! $this->isActiveTenantUser($tenantUser)
                    || (int) $tenantUser->getRawOriginal('school_id') !== $assertion['tenant_user_school_id']) {
                    throw new AuthorizationException('The tenant login identity is no longer valid.');
                }
                Auth::setUser($tenantUser);

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
        $resolvedHostSchoolId = $request->attributes->get('resolved_school_host_id');
        if ($resolvedHostSchoolId !== null && (int) $resolvedHostSchoolId !== (int) $school->id) {
            throw new AuthorizationException('The current School host does not match the API School Code.');
        }
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

    /** @return array{school_id:int,database_name:string,tenant_user_id:int,tenant_user_school_id:int,session_id:string}|null */
    private function tenantAssertion(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $assertion = $request->session()->get(self::TENANT_SESSION_ASSERTION);
        if (! is_array($assertion)
            || ($assertion['version'] ?? null) !== 1
            || filter_var($assertion['school_id'] ?? null, FILTER_VALIDATE_INT) === false
            || filter_var($assertion['tenant_user_id'] ?? null, FILTER_VALIDATE_INT) === false
            || filter_var($assertion['tenant_user_school_id'] ?? null, FILTER_VALIDATE_INT) === false
            || (int) $assertion['school_id'] <= 0
            || (int) $assertion['tenant_user_id'] <= 0
            || (int) $assertion['tenant_user_school_id'] <= 0
            || trim((string) ($assertion['database_name'] ?? '')) === ''
            || trim((string) ($assertion['session_id'] ?? '')) === '') {
            return null;
        }

        return [
            'school_id' => (int) $assertion['school_id'],
            'database_name' => trim((string) $assertion['database_name']),
            'tenant_user_id' => (int) $assertion['tenant_user_id'],
            'tenant_user_school_id' => (int) $assertion['tenant_user_school_id'],
            'session_id' => (string) $assertion['session_id'],
        ];
    }

    private function assertActiveInstalledSchool(School $school): void
    {
        if ((int) $school->getRawOriginal('status') !== 1
            || (int) $school->getRawOriginal('installed') !== 1) {
            throw new AuthorizationException('The requested School is not active and installed.');
        }
    }

    private function isActiveTenantUser(User $user): bool
    {
        return (int) $user->getRawOriginal('status') === 1
            && $user->getRawOriginal('deleted_at') === null;
    }

    private function isTenantRecoveryEntryRequest(Request $request): bool
    {
        return $request->is('/') || $request->is('login');
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
