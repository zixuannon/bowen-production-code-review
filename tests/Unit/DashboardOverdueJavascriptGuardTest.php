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
}
