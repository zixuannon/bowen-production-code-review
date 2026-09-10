<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class InstallerViewReconciliationTest extends TestCase
{
    public function test_web_installer_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('install.purchase-code.index'));
        $this->assertFalse(Route::has('install.php-function.index'));
        $this->assertFalse(Route::has('LaravelWizardInstaller::install.index'));
    }

    public function test_installer_dependency_and_default_credentials_are_removed(): void
    {
        $composer = file_get_contents(base_path('composer.json'));

        $this->assertStringNotContainsString('sagar/laravel-wizard-installer', $composer);
        $this->assertFileDoesNotExist(config_path('installer.php'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/InstallerController.php'));
        $this->assertDirectoryDoesNotExist(base_path('packages/laravel-wizard-installer'));
        $this->assertDirectoryDoesNotExist(resource_path('views/vendor/installer'));
    }
}
