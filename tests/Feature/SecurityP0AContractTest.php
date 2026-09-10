<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityP0AContractTest extends TestCase
{
    public function test_teacher_update_file_route_requires_the_dedicated_role_and_ability_gate(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/teacher/update-file');

        $this->assertNotNull($route);
        $this->assertContains('teacherFileUpload', $route->gatherMiddleware());
        $source = file_get_contents(app_path('Http/Controllers/Api/TeacherApiController.php'));
        $this->assertStringContainsString("'teacher-files:update'", $source);
        $this->assertStringContainsString('Lesson::query()', $source);
        $this->assertStringContainsString('LessonTopic::query()', $source);
        $this->assertStringContainsString('Assignment::query()', $source);
        $this->assertStringContainsString('->owner()->whereKey($file->modal_id)', $source);
        $this->assertStringContainsString('UploadService::uploadAllowed', $source);

        $middleware = file_get_contents(app_path('Http/Middleware/RequireTeacherFileUpload.php'));
        $this->assertStringContainsString('ownsRequestedClasses', $middleware);
        $this->assertStringContainsString('SubjectTeacher::query()', $middleware);
        $this->assertStringContainsString("where('school_id', \$schoolId)->owner()", $middleware);
    }

    public function test_all_subscription_webhooks_are_signed_and_cannot_create_transactions(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/SubscriptionWebhookController.php'));
        $guard = substr($source, strpos($source, 'private function guardSubscriptionWebhook'));

        $this->assertStringContainsString('verifyRazorpay', $guard);
        $this->assertStringContainsString('verifyFlutterwave', $guard);
        $this->assertStringContainsString('verifyPaystack', $guard);
        $this->assertStringContainsString('assertPendingTransaction', $guard);
        $this->assertStringNotContainsString('Creating new payment transaction', $source);

        $initiation = file_get_contents(app_path('Http/Controllers/SubscriptionController.php'));
        $this->assertStringContainsString("->update(['order_id' => \$orderData['id']])", $initiation);
    }

    public function test_fee_flutterwave_webhook_uses_signature_provider_verification_and_server_transaction_match(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/WebhookController.php'));
        $method = substr($source, strpos($source, 'public function flutterwave'));

        $this->assertStringContainsString('verifyFlutterwave', $method);
        $this->assertStringContainsString('verifyFlutterwave(', $method);
        $this->assertStringContainsString('assertPendingTransaction', $method);
        $this->assertStringContainsString('lockForUpdate()', $method);
        $this->assertStringContainsString("LOWER(payment_status) = ?", $method);
    }

    public function test_database_restore_route_ui_and_sql_execution_path_are_absent(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('database-backup.restore'));
        $controller = file_get_contents(app_path('Http/Controllers/DatabaseBackupController.php'));
        $view = file_get_contents(resource_path('views/database-backup/index.blade.php'));

        $this->assertStringNotContainsString('public function restore(', $controller);
        $this->assertStringNotContainsString('DB::unprepared', $controller);
        $this->assertStringNotContainsString('->truncate()', $controller);
        $this->assertStringNotContainsString('database-backup.restore', $view);
        $this->assertStringNotContainsString('restore-form', $view);
    }

    public function test_updater_and_restore_do_not_trigger_broad_migrations(): void
    {
        $updater = file_get_contents(app_path('Http/Controllers/SystemUpdateController.php'));
        $backup = file_get_contents(app_path('Http/Controllers/DatabaseBackupController.php'));

        $this->assertStringNotContainsString("Artisan::call('migrate')", $updater);
        $this->assertStringNotContainsString("Artisan::call('migrate'", $backup);
    }

    public function test_nginx_storage_locations_cannot_fall_through_to_php_handler(): void
    {
        $source = file_get_contents(config_path('nginx/eschool-upload-security.conf'));
        $this->assertSame(2, substr_count($source, 'location ^~'));
        $this->assertStringContainsString('/storage/', $source);
        $this->assertStringContainsString('/uploads/', $source);
        $this->assertStringContainsString('try_files $uri =404;', $source);
    }
}
