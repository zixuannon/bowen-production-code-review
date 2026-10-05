<?php

namespace Tests\Feature;

use App\Exceptions\StaffClassificationException;
use App\Imports\StaffImport;
use App\Imports\TeacherImport;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Repositories\Subscription\SubscriptionInterface;
use App\Services\CachingService;
use App\Services\QaStaffClassificationService;
use App\Services\UserService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class QaStaffImportCreationTest extends TestCase
{
    private int $schoolId;
    private int $teacherRoleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('school_testing', config('database.connections.school.database'));
        $this->assertContains(config('database.connections.school.host'), ['127.0.0.1', 'localhost']);
        DB::setDefaultConnection('school');
        DB::beginTransaction();
        $this->schoolId = DB::table('schools')->insertGetId(['name' => 'Import QA', 'code' => 'QI'.Str::random(10)]);
        $actor = $this->newUser('Actor');
        Auth::setUser($actor);
        $actor->assignRole(Role::create(['name' => 'School Admin', 'guard_name' => 'web', 'school_id' => $this->schoolId]));
        $this->teacherRoleId = Role::create(['name' => 'Teacher', 'guard_name' => 'web', 'school_id' => $this->schoolId])->id;
        foreach (['leave-list', 'leave-create', 'leave-edit', 'leave-delete'] as $name) {
            Permission::withoutGlobalScopes()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $sessionYearId = DB::table('session_years')->insertGetId([
            'name' => 'QA Import year', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'school_id' => $this->schoolId, 'default' => 0,
        ]);
        $cache = Mockery::mock(CachingService::class);
        $cache->shouldReceive('getDefaultSessionYear')->andReturn((object) ['id' => $sessionYearId]);
        $this->app->instance(CachingService::class, $cache);
        $subscription = Mockery::mock(SubscriptionInterface::class);
        $subscription->shouldReceive('builder->doesntHave->whereDate->where->whereHas->first')->andReturnNull();
        $this->app->instance(SubscriptionInterface::class, $subscription);
        $this->app->instance(UserService::class, Mockery::mock(UserService::class));
    }

    protected function tearDown(): void
    {
        while (DB::connection('school')->transactionLevel() > 0) {
            DB::connection('school')->rollBack();
        }
        Auth::forgetUser();
        DB::setDefaultConnection('mysql');
        parent::tearDown();
    }

    public static function creationCases(): array
    {
        $cases = [];
        foreach (['staff', 'teacher'] as $import) {
            foreach (['new-user', 'existing-user', 'existing-staff'] as $state) {
                $cases[$import.' '.$state] = [$import, $state];
            }
        }
        return $cases;
    }

    #[DataProvider('creationCases')]
    public function test_only_a_new_staff_profile_triggers_inheritance(string $import, string $state): void
    {
        $target = $state === 'new-user' ? null : $this->newUser('Before');
        if ($state === 'existing-staff') {
            Staff::create(['user_id' => $target->id, 'salary' => 1]);
        }
        $email = $target?->email ?? Str::uuid().'@qa.test';
        $classifiedIds = [];
        $classifier = Mockery::mock(new QaStaffClassificationService);
        $classifier->shouldReceive('inherit')->times($state === 'existing-staff' ? 0 : 1)
            ->andReturnUsing(function (User $user, User $actor) use (&$classifiedIds): void {
                $this->assertSame(2, DB::transactionLevel());
                $this->assertSame(Auth::id(), $actor->id);
                $this->assertSame(1, Staff::where('user_id', $user->id)->count());
                $classifiedIds[] = $user->id;
            });
        $this->app->instance(QaStaffClassificationService::class, $classifier);

        $this->assertTrue($this->importer($import)->collection(collect([$this->row($email)])));

        $saved = User::withTrashed()->where('email', $email)->sole();
        $this->assertSame($target?->id ?? $saved->id, $saved->id);
        $this->assertSame($state === 'existing-staff' ? [] : [$saved->id], $classifiedIds);
        $this->assertSame(1, Staff::where('user_id', $saved->id)->count());
        $this->assertSame(1, DB::transactionLevel());
    }

    public static function imports(): array { return [['staff'], ['teacher']]; }

    #[DataProvider('imports')]
    public function test_later_classification_failure_rolls_back_the_entire_batch(string $import): void
    {
        $existing = $this->newUser('Before');
        $newEmail = Str::uuid().'@qa.test';
        $calls = 0;
        $classifier = Mockery::mock(new QaStaffClassificationService);
        $classifier->shouldReceive('inherit')->twice()->andReturnUsing(function () use (&$calls): void {
            if (++$calls === 2) {
                throw new StaffClassificationException('Synthetic classification failure');
            }
        });
        $this->app->instance(QaStaffClassificationService::class, $classifier);
        try {
            $this->importer($import)->collection(collect([$this->row($newEmail), $this->row($existing->email)]));
            $this->fail('A classification failure must abort the whole import.');
        } catch (StaffClassificationException $exception) {
            $this->assertSame('Synthetic classification failure', $exception->getMessage());
        }
        $this->assertSame(2, $calls);
        $this->assertSame(0, User::withTrashed()->where('email', $newEmail)->count());
        $this->assertSame('Before', $existing->fresh()->first_name);
        $this->assertSame(0, Staff::where('user_id', $existing->id)->count());
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $existing->id)->where('model_type', User::class)->count());
        $this->assertSame(1, DB::transactionLevel());
    }

    private function newUser(string $name): User
    {
        return User::create([
            'first_name' => $name, 'last_name' => 'QA', 'email' => Str::uuid().'@qa.test',
            'password' => 'disposable', 'school_id' => $this->schoolId, 'status' => 1,
        ]);
    }

    private function importer(string $import): StaffImport|TeacherImport
    {
        return $import === 'staff' ? new StaffImport($this->teacherRoleId, false) : new TeacherImport(false);
    }

    private function row(string $email): \Illuminate\Support\Collection
    {
        return collect([
            'first_name' => 'Imported', 'last_name' => 'QA', 'email' => $email,
            'mobile' => '091234567', 'dob' => '1990-01-01', 'gender' => 'Male',
            'qualification' => 'QA', 'current_address' => 'QA', 'permanent_address' => 'QA', 'salary' => 10,
        ]);
    }
}
