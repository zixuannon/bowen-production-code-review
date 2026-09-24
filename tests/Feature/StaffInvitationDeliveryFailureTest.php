<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\ExtraFormField\ExtraFormFieldsInterface;
use App\Repositories\Student\StudentInterface;
use App\Repositories\StudentSubject\StudentSubjectInterface;
use App\Repositories\User\UserInterface;
use App\Services\CachingService;
use App\Services\SessionYearsTrackingsService;
use App\Services\UserService;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StaffInvitationDeliveryFailureTest extends TestCase
{
    public function test_staff_invitation_template_failure_is_reported_as_delivery_failure_without_terminating_the_request(): void
    {
        $cache = Mockery::mock(CachingService::class);
        $cache->shouldReceive('getSchoolSettings')
            ->once()
            ->andThrow(new RuntimeException('QA invitation template unavailable'));
        $this->app->instance(CachingService::class, $cache);

        $user = Mockery::mock(User::class);
        $user->shouldReceive('getKey')->once()->andReturn(42);
        $user->shouldReceive('getRawOriginal')->with('school_id')->once()->andReturn(1);

        Log::shouldReceive('warning')->once()->withArgs(static function (string $message, array $context): bool {
            return $message === 'Staff registration email was not sent.'
                && $context['user_id'] === 42
                && $context['school_id'] === 1
                && $context['exception'] === RuntimeException::class
                && $context['message'] === 'QA invitation template unavailable';
        });

        $service = new UserService(
            Mockery::mock(UserInterface::class),
            Mockery::mock(StudentInterface::class),
            Mockery::mock(ExtraFormFieldsInterface::class),
            Mockery::mock(SessionYearsTrackingsService::class),
            Mockery::mock(StudentSubjectInterface::class),
        );

        $this->assertFalse($service->sendStaffRegistrationEmail($user));
    }
}
