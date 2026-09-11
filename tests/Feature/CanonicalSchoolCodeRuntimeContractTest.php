<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class CanonicalSchoolCodeRuntimeContractTest extends TestCase
{
    public function test_deprecated_zixuan_code_is_absent_from_runtime_identity_paths(): void
    {
        $roots = ['app', 'config', 'routes', 'resources', 'scripts', 'qa'];
        $allowed = [base_path('app/Console/Commands/MigrateOperationalIdentitySchema.php')];
        $violations = [];

        foreach ($roots as $root) {
            $directory = base_path($root);
            if (!is_dir($directory)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
                if (!$file->isFile() || in_array($file->getPathname(), $allowed, true)) {
                    continue;
                }
                if (str_contains((string) file_get_contents($file->getPathname()), 'SCH202615')) {
                    $violations[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $violations, 'Deprecated Zixuan code remains in runtime paths: '.implode(', ', $violations));
    }

    public function test_every_school_identity_family_uses_canonical_only_resolution(): void
    {
        $canonicalConsumers = [
            'app/Http/Controllers/Auth/LoginController.php',
            'app/Http/Controllers/Auth/ForgotPasswordController.php',
            'app/Http/Controllers/Auth/ResetPasswordController.php',
            'app/Http/Controllers/Auth/DingTalkLoginController.php',
            'app/Http/Middleware/APISwitchDatabase.php',
            'app/Http/Middleware/CheckChild.php',
            'app/Http/Middleware/CheckSchoolStatus.php',
            'app/Http/Middleware/DifyTokenMiddleware.php',
            'app/Services/StudentImportV2Service.php',
            'app/Services/CentralFinanceGroupImportService.php',
            'app/Services/CentralFinanceGateASchoolScope.php',
            'app/Services/StaffInvitationService.php',
            'app/Http/Controllers/SchoolController.php',
        ];

        foreach ($canonicalConsumers as $path) {
            $source = (string) file_get_contents(base_path($path));
            $this->assertTrue(
                str_contains($source, 'whereCanonicalCode') || str_contains($source, 'resolveCanonical'),
                "{$path} must resolve only current canonical School Codes."
            );
            $this->assertStringNotContainsString('school_code_alias', $source);
            $this->assertStringNotContainsString('whereCodeOrAlias', $source);
        }
    }

    public function test_super_admin_forms_submit_a_full_server_validated_code(): void
    {
        $controller = (string) file_get_contents(app_path('Http/Controllers/SchoolController.php'));
        $index = (string) file_get_contents(resource_path('views/schools/index.blade.php'));
        $inquiry = (string) file_get_contents(resource_path('views/schools/school_inquiry.blade.php'));

        $this->assertGreaterThanOrEqual(2, substr_count($controller, "regex:/^MMBOWEN[0-9]{2,}$/i"));
        $this->assertGreaterThanOrEqual(2, substr_count($controller, 'claimRequestedCode'));
        foreach ([$index, $inquiry] as $view) {
            $this->assertStringContainsString('name="school_code"', $view);
            $this->assertStringNotContainsString('name="school_code_prefix"', $view);
        }
    }
}
