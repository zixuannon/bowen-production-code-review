<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SchoolLoginTenantBootstrapContractTest extends TestCase
{
    public function test_successful_school_login_persists_the_resolved_tenant_after_the_final_guard_login(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Auth/LoginController.php'));
        $finalLogin = strpos($source, 'Auth::login($user);');

        $this->assertNotFalse($finalLogin);
        $tail = substr($source, $finalLogin, 1000);
        $this->assertStringContainsString("Session::put('db_connection_name', 'school')", $tail);
        $this->assertStringContainsString("Session::put('school_database_name', \$school->database_name)", $tail);
        $this->assertStringNotContainsString('School::where(', $tail);
        $this->assertStringNotContainsString('orWhere(', $tail);
    }
}
