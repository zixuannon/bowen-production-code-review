<?php

namespace Tests\Feature;

use App\Http\Middleware\DenySchoolAdminFinance;
use App\Http\Middleware\RequireApiFamily;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class RequireApiFamilyTest extends TestCase
{
    public function test_each_family_requires_matching_explicit_ability_and_role(): void
    {
        $this->assertSame(204, $this->handleFamily('student', ['Student'], ['student-api'])->getStatusCode());
        $this->assertSame(204, $this->handleFamily('guardian', ['Guardian'], ['guardian-api'])->getStatusCode());
        $this->assertSame(204, $this->handleFamily('teacher', ['Teacher'], ['teacher-api'])->getStatusCode());
        $this->assertSame(204, $this->handleFamily('staff', ['Accountant'], ['staff-api'])->getStatusCode());
    }

    public function test_wildcard_cross_family_and_teacher_staff_tokens_fail_closed(): void
    {
        foreach ([
            ['staff', ['Student'], ['*']],
            ['staff', ['Accountant'], ['staff-api', '*']],
            ['staff', ['Student'], ['staff-api']],
            ['staff', ['Guardian'], ['staff-api']],
            ['staff', ['Teacher'], ['staff-api']],
            ['student', ['Student'], ['staff-api']],
            ['teacher', ['Accountant'], ['teacher-api']],
        ] as [$family, $roles, $abilities]) {
            try {
                $this->handleFamily($family, $roles, $abilities);
                $this->fail("{$family} should have rejected the mismatched identity.");
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_school_admin_finance_gate_denies_direct_requests_but_preserves_super_admin(): void
    {
        $request = Request::create('/finance-dashboard', 'GET');
        $request->setUserResolver(fn () => $this->identity(['School Admin'], ['staff-api']));
        try {
            (new DenySchoolAdminFinance())->handle($request, fn () => response('', 204));
            $this->fail('School Admin must be denied from Finance.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $request->setUserResolver(fn () => $this->identity(['School Admin', 'Super Admin'], ['staff-api']));
        $this->assertSame(204, (new DenySchoolAdminFinance())->handle($request, fn () => response('', 204))->getStatusCode());
    }

    private function handleFamily(string $family, array $roles, array $abilities): Response
    {
        $request = Request::create('/api/test', 'GET');
        $request->setUserResolver(fn () => $this->identity($roles, $abilities));
        return (new RequireApiFamily())->handle($request, fn () => response('', 204), $family);
    }

    private function identity(array $roles, array $abilities): object
    {
        return new class($roles, $abilities) {
            public function __construct(private array $roles, private array $abilities) {}
            public function hasRole(string $role): bool { return in_array($role, $this->roles, true); }
            public function hasAnyRole(array $roles): bool { return (bool) array_intersect($roles, $this->roles); }
            public function getRoleNames() { return collect($this->roles); }
            public function currentAccessToken(): object { return (object) ['abilities' => $this->abilities]; }
        };
    }
}
