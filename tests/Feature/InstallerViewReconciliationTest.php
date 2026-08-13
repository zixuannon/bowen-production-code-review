<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class InstallerViewReconciliationTest extends TestCase
{
    public function test_required_custom_installer_views_exist_and_render_without_installing(): void
    {
        $this->registerInstallerApplicationRoutes();

        $purchaseCode = view('vendor.installer.steps.purchase-code')->render();
        $symlinkCheck = view('vendor.installer.steps.symlink_basedir_check', ['result' => true])->render();
        $finish = view('vendor.installer.steps.finish', ['path' => 'http://localhost'])->render();

        $this->assertStringContainsString('name="purchase_code"', $purchaseCode);
        $this->assertStringContainsString('install/purchase-code', $purchaseCode);
        $this->assertStringContainsString('Enable php Symlink', $symlinkCheck);
        $this->assertStringContainsString('install/database', $symlinkCheck);
        $this->assertStringContainsString('href="http://localhost"', $finish);
        $this->assertStringNotContainsString('/auth/login', $finish);
    }

    public function test_folders_override_routes_successful_checks_to_purchase_code_step(): void
    {
        $this->registerInstallerApplicationRoutes();

        $view = file_get_contents(resource_path('views/vendor/installer/steps/folders.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("route('install.purchase-code.index')", $view);
        $this->assertStringNotContainsString("route('LaravelWizardInstaller::install.database')", $view);
    }

    public function test_application_specific_installer_routes_are_absent_when_installer_is_disabled(): void
    {
        $this->assertFalse(Route::has('install.purchase-code.index'));
        $this->assertFalse(Route::has('install.php-function.index'));
        $this->assertTrue(Route::has('LaravelWizardInstaller::install.index'));
    }

    private function registerInstallerApplicationRoutes(): void
    {
        if (!Route::has('install.purchase-code.index')) {
            Route::get('/install/purchase-code', static fn () => '')->name('install.purchase-code.index');
        }

        if (!Route::has('install.purchase-code.post')) {
            Route::post('/install/purchase-code', static fn () => '')->name('install.purchase-code.post');
        }

        if (!Route::has('install.php-function.index')) {
            Route::get('/install/php-function', static fn () => '')->name('install.php-function.index');
        }

        Route::getRoutes()->refreshNameLookups();
    }
}
