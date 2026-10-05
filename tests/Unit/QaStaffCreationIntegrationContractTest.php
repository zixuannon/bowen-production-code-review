<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QaStaffCreationIntegrationContractTest extends TestCase
{
    public static function controllers(): array
    {
        return [
            ['StaffController', "ResponseService::noPermissionThenSendJson('staff-create');"],
            ['TeacherController', "abort_unless(Auth::user()?->canany(['teacher-create', 'teacher-edit']), 403);"],
            ['DriverHelperController', "ResponseService::noPermissionThenSendJson('driver-helper-create');"],
        ];
    }

    #[DataProvider('controllers')]
    public function test_controller_inheritance_is_inside_creation_transaction_after_staff_and_before_invitation(string $name, string $guard): void
    {
        $source = $this->source('app/Http/Controllers/'.$name.'.php');
        $store = explode('public function show', explode('public function store(Request $request)', $source, 2)[1], 2)[0];
        $hook = 'app(QaStaffClassificationService::class)->inherit($user, Auth::user());';

        self::assertSame(1, substr_count($source, $hook), 'Existing Staff updates must not reclassify historical users.');
        self::assertStringContainsString($guard, $store);
        self::assertLessThan(strpos($store, '$this->user->create('), strpos($store, $guard));
        self::assertLessThan(strpos($store, $hook), strpos($store, 'DB::beginTransaction();'));
        self::assertLessThan(strpos($store, $hook), strpos($store, '$this->staff->create('));
        self::assertLessThan(strpos($store, 'DB::commit();'), strpos($store, $hook));
        self::assertLessThan(strpos($store, 'sendStaffRegistrationEmail($user)'), strpos($store, $hook));
        if ($name !== 'TeacherController') {
            self::assertMatchesRegularExpression('/if \(Auth::user\(\)->school_id\) \{\s*app\(QaStaffClassificationService::class\)->inherit/', $store);
        }
    }

    public static function imports(): array
    {
        return [['StaffImport'], ['TeacherImport']];
    }

    #[DataProvider('imports')]
    public function test_import_inherits_for_new_staff_profiles_including_existing_users_before_invitation(string $name): void
    {
        $source = $this->source('app/Imports/'.$name.'.php');
        $hook = 'app(QaStaffClassificationService::class)->inherit($users, Auth::user());';

        self::assertSame(1, substr_count($source, $hook));
        self::assertStringContainsString('$staffProfile = $staff->updateOrCreate(', $source);
        self::assertMatchesRegularExpression('/if \(\$staffProfile->wasRecentlyCreated(?: && \$school_id)?\) \{\s*app\(QaStaffClassificationService::class\)->inherit/', $source);
        self::assertLessThan(strpos($source, $hook), strpos($source, 'DB::beginTransaction();'));
        self::assertLessThan(strpos($source, $hook), strpos($source, '$staff->updateOrCreate('));
        self::assertLessThan(strpos($source, 'sendStaffRegistrationEmail($users)'), strpos($source, $hook));
        self::assertLessThan(strpos($source, 'DB::commit();'), strpos($source, $hook));
    }

    public static function legacyMailCatches(): array
    {
        return [
            ['app/Http/Controllers/TeacherController.php'],
            ['app/Http/Controllers/DriverHelperController.php'],
        ];
    }

    #[DataProvider('legacyMailCatches')]
    public function test_classification_failure_cannot_commit_through_legacy_mail_keyword_heuristic(string $path): void
    {
        $source = $this->source($path);
        $catch = explode('catch (Throwable $e)', $source, 2)[1];

        self::assertStringContainsString('use App\\Exceptions\\StaffClassificationException;', $source);
        self::assertMatchesRegularExpression('/if \(! \(\$e instanceof StaffClassificationException\)\s*&& Str::contains\(\$e->getMessage\(\)/', $catch);
        self::assertMatchesRegularExpression('/DB::roll[Bb]ack\(\);/', $catch);
    }

    #[DataProvider('imports')]
    public function test_import_creation_failure_always_rolls_back_and_rethrows_without_mid_batch_commit(string $name): void
    {
        $source = $this->source('app/Imports/'.$name.'.php');
        $catch = explode('catch (Throwable $e)', $source, 2)[1];

        self::assertSame(1, substr_count($source, 'DB::commit();'), 'Only successful completion may commit the batch.');
        self::assertStringNotContainsString('Str::contains', $catch);
        self::assertStringNotContainsString('continue;', $catch);
        self::assertMatchesRegularExpression('/if \(DB::transactionLevel\(\) > 0\) \{\s*DB::rollBack\(\);\s*\}\s*throw \$e;/', $catch);
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }
}
