<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireTeacherFileUpload;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class RequireTeacherFileUploadTest extends TestCase
{
    public function test_wildcard_token_is_not_accepted_for_teacher_file_upload(): void
    {
        $response = $this->handle(true, ['*']);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_explicit_teacher_file_ability_is_required_with_teacher_role(): void
    {
        $this->assertSame(204, $this->handle(true, ['teacher-files:update'])->getStatusCode());
        $this->assertSame(403, $this->handle(false, ['teacher-files:update'])->getStatusCode());
    }

    private function handle(bool $teacher, array $abilities): Response
    {
        $user = new class($teacher, $abilities) {
            public int $id = 100;
            public int $school_id = 10;
            public function __construct(private bool $teacher, private array $abilities) {}
            public function hasRole(string $role): bool { return $this->teacher && $role === 'Teacher'; }
            public function currentAccessToken(): object { return (object) ['abilities' => $this->abilities]; }
        };
        $request = Request::create('/api/teacher/update-file', 'POST');
        $request->setUserResolver(fn () => $user);

        return (new RequireTeacherFileUpload())->handle($request, fn () => response('', 204));
    }
}
