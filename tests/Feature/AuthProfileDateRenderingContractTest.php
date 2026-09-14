<?php

namespace Tests\Feature;

use Tests\TestCase;

final class AuthProfileDateRenderingContractTest extends TestCase
{
    public function test_profile_formats_the_canonical_raw_date_instead_of_reparsing_the_accessor_value(): void
    {
        $view = file_get_contents(resource_path('views/auth/profile.blade.php'));

        $this->assertStringContainsString("getRawOriginal('dob')", $view);
        $this->assertStringNotContainsString('createFromFormat($originalDateFormat, $userData->dob)', $view);
    }
}
