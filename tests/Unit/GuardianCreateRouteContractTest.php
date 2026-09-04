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
        $this->assertStringContainsString('id="guardian_admission_guardian_id"', $view);
        $this->assertStringContainsString('data-guardian-admission-controller="true"', $view);
        $this->assertStringContainsString('name="guardian_email"', $view);

        $script = file_get_contents(public_path('assets/js/custom/custom.js'));
        $this->assertStringContainsString('function normalizeGuardianSearchResult(repo)', $script);
        $this->assertStringContainsString("id: String(repo?.id ?? ''),", $script);
        $this->assertStringContainsString("text: email || name || field(repo?.text, true),", $script);
        $this->assertStringContainsString('const guardianAdmissionResultCatalog = new Map();', $script);
        $this->assertStringContainsString('function rememberGuardianSearchResult(repo)', $script);
        $this->assertStringContainsString('function resolveGuardianSearchResult(repo)', $script);
        $this->assertStringContainsString('function hydrateGuardianAdmissionSelection(guardian, $search)', $script);
        $this->assertStringContainsString('function synchronizeGuardianAdmissionSelection(repo, $search)', $script);
        $this->assertStringContainsString("'/guardian/' + encodeURIComponent(lookupKey) + '/admission-details'", $script);
        $this->assertStringContainsString('results: guardians.map(rememberGuardianSearchResult)', $script);
        $this->assertStringContainsString("function selectedGuardianSearchData(\$search)", $script);
        $this->assertStringContainsString("function syncSelectedGuardian(repo, \$search = $('.guardian-search'))", $script);
        $this->assertStringContainsString("data('guardianAdmissionSelection', guardian)", $script);
        $this->assertStringContainsString("const cached = \$search.data('guardianAdmissionSelection');", $script);
        $this->assertStringContainsString('const existingGuardianEmail = guardian.email;', $script);
        $this->assertStringContainsString("$('#guardian_email').val(existingGuardianEmail);", $script);
        $this->assertStringContainsString("$('#guardian_first_name').val(guardian.first_name)", $script);
        $this->assertStringContainsString("$('#guardian_last_name').val(guardian.last_name)", $script);
        $this->assertStringContainsString("$('#guardian_mobile').val(guardian.mobile)", $script);
        $this->assertStringContainsString('function guardianSelectionTemplate(repo)', $script);
        $this->assertStringContainsString("select2:select.guardianAdmission", $script);
        $this->assertStringContainsString("select2:clear.guardianAdmission", $script);
        $this->assertStringContainsString("change.guardianAdmission", $script);
        $this->assertStringContainsString("const guardianAdmissionSearchSelector = '#guardian_email_search';", $script);
        $this->assertStringContainsString(".on('select2:select.guardianAdmission', guardianAdmissionSearchSelector", $script);
        $this->assertStringContainsString('function clearGuardianSelection()', $script);
        $this->assertStringContainsString("removeData('guardianAdmissionSelection')", $script);
        $this->assertStringContainsString("removeData('guardianAdmissionLookupKey')", $script);
        $this->assertStringContainsString("studentAdmissionForm.addEventListener('submit'", $script);
        $this->assertStringContainsString('}, true);', $script);
        $this->assertStringContainsString('student-admission-guardian.js', file_get_contents(resource_path('views/students/create.blade.php')));

        $pageController = file_get_contents(public_path('assets/js/custom/student-admission-guardian.js'));
        $this->assertStringContainsString('Select2 chooses only an id', $pageController);
        $this->assertStringContainsString('admission-details', $pageController);
        $this->assertStringContainsString('const applyStateToForm = () =>', $pageController);
        $this->assertStringContainsString("state.mode = 'existing'; state.guardianId = field(id);", $pageController);
        $this->assertStringContainsString('if (state.request) state.request.abort()', $pageController);
        $this->assertStringContainsString('The selected Guardian is still loading', $pageController);
    }

    public function test_guardian_admission_details_route_is_tenant_scoped_and_declared_before_resource_route(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $controller = file_get_contents(app_path('Http/Controllers/GuardianController.php'));

        $this->assertStringContainsString("Route::get('/guardian/{guardianId}/admission-details'", $routes);
        $this->assertStringContainsString("->whereNumber('guardianId')", $routes);
        $this->assertLessThan(
            strpos($routes, "Route::resource('guardian', GuardianController::class);"),
            strpos($routes, "Route::get('/guardian/{guardianId}/admission-details'"),
        );
        $this->assertStringContainsString("ResponseService::noAnyPermissionThenSendJson(['student-create', 'student-edit']);", $controller);
        $this->assertStringContainsString('$this->user->guardian()', $controller);
        $this->assertStringContainsString("->findOrFail(\$guardianId)", $controller);
    }
}
