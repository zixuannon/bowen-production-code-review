<?php

namespace Tests\Unit;

use App\Http\Controllers\GuardianController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GuardianCreateRouteContractTest extends TestCase
{
    public function test_guardian_create_route_targets_an_implemented_controller_action(): void
    {
        $route = Route::getRoutes()->match(Request::create('/guardian/create', 'GET'));

        $this->assertSame('guardian.create', $route->getName());
        $this->assertTrue(method_exists(GuardianController::class, 'create'));
        $this->assertFileExists(resource_path('views/guardian/create.blade.php'));
        $compiledViews = sys_get_temp_dir() . '/eschool_guardian_create_views';
        File::ensureDirectoryExists($compiledViews);
        config(['view.compiled' => $compiledViews]);
        $this->assertNotSame('', Blade::compileString(file_get_contents(resource_path('views/guardian/create.blade.php'))));
    }

    public function test_student_admission_has_one_guardian_email_submission_field_and_a_separate_search_control(): void
    {
        $view = file_get_contents(resource_path('views/students/create.blade.php'));

        $this->assertSame(1, substr_count($view, 'id="guardian_email"'));
        $this->assertStringContainsString('id="guardian_email_search"', $view);
        $this->assertStringContainsString('name="guardian_email"', $view);

        $script = file_get_contents(public_path('assets/js/custom/custom.js'));
        $this->assertStringContainsString('function applyGuardianSelection(repo)', $script);
        $this->assertStringContainsString('const existingGuardianEmail = typeof repo?.email', $script);
        $this->assertStringContainsString("$('#guardian_email').val(existingGuardianEmail);", $script);
        $this->assertStringContainsString('function guardianSelectionTemplate(repo)', $script);
        $this->assertStringContainsString("select2:select.guardianAdmission", $script);
        $this->assertStringContainsString("select2:clear.guardianAdmission", $script);
        $this->assertStringContainsString("change.guardianAdmission", $script);
        $this->assertStringContainsString('function clearGuardianSelection()', $script);
    }
}
