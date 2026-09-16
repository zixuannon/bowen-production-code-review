<?php

namespace Tests\Unit;

use App\Http\Controllers\StaffController;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class StaffRoleAssignmentScopeTest extends TestCase
{
    public function test_school_admin_can_assign_a_custom_role_from_their_own_school(): void
    {
        $this->assertRolesAreAssignable(
            collect([$this->role(9, 'Finance Department', 19, 1)]),
            expectedCount: 1,
            assignedSchoolIds: [],
            authenticatedSchoolId: 19
        );

        self::assertTrue(true);
    }

    public function test_school_admin_cannot_assign_a_custom_role_from_another_school(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Invalid staff role assignment.');

        $this->assertRolesAreAssignable(
            collect([$this->role(9, 'Finance Department', 17, 1)]),
            expectedCount: 1,
            assignedSchoolIds: [],
            authenticatedSchoolId: 19
        );
    }

    public function test_central_admin_uses_the_explicit_target_school_scope(): void
    {
        $this->assertRolesAreAssignable(
            collect([$this->role(9, 'Finance Department', 19, 1)]),
            expectedCount: 1,
            assignedSchoolIds: [19],
            authenticatedSchoolId: null
        );

        self::assertTrue(true);
    }

    public function test_central_admin_cannot_assign_a_role_outside_the_explicit_target_school_scope(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Invalid staff role assignment.');

        $this->assertRolesAreAssignable(
            collect([$this->role(9, 'Finance Department', 17, 1)]),
            expectedCount: 1,
            assignedSchoolIds: [19],
            authenticatedSchoolId: null
        );
    }

    public function test_non_assignable_system_role_remains_rejected(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Invalid staff role assignment.');

        $this->assertRolesAreAssignable(
            collect([$this->role(2, 'Teacher', 19, 0)]),
            expectedCount: 1,
            assignedSchoolIds: [],
            authenticatedSchoolId: 19
        );
    }

    private function assertRolesAreAssignable(
        Collection $roles,
        int $expectedCount,
        array $assignedSchoolIds,
        ?int $authenticatedSchoolId
    ): void {
        $reflection = new ReflectionClass(StaffController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('assertAssignableStaffRoles');

        $method->invoke($controller, $roles, $expectedCount, $assignedSchoolIds, $authenticatedSchoolId);
    }

    private function role(int $id, string $name, ?int $schoolId, int $customRole): object
    {
        return (object) [
            'id' => $id,
            'name' => $name,
            'school_id' => $schoolId,
            'custom_role' => $customRole,
        ];
    }
}
