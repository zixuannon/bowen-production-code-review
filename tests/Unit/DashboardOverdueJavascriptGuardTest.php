<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DashboardOverdueJavascriptGuardTest extends TestCase
{
    public function test_overdue_fee_callback_treats_missing_response_data_as_an_empty_list(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/public/assets/js/custom/custom.js');

        $this->assertStringContainsString(
            'const overdueStudents = response && Array.isArray(response.data) ? response.data : [];',
            $script,
        );
        $this->assertStringContainsString('if (overdueStudents.length)', $script);
        $this->assertStringContainsString('$.each(overdueStudents', $script);
    }

    public function test_super_admin_dashboard_never_requests_the_storage_placeholder(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/resources/views/dashboard.blade.php');

        $this->assertStringContainsString("'/storage/no_image_available.jpg'", $view);
        $this->assertStringContainsString("asset('/assets/no_image_available.jpg')", $view);
        $this->assertStringContainsString('src="{{ $schoolLogoUrl }}"', $view);
        $this->assertStringNotContainsString('src="{{ $school->logo }}"', $view);
    }
}
