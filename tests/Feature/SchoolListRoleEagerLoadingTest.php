<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SchoolListRoleEagerLoadingTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    public function test_school_admin_roles_are_loaded_in_one_query_for_the_whole_page(): void
    {
        DB::setDefaultConnection('mysql');
        config(['database.default' => 'mysql']);

        $roleId = DB::connection('mysql')->table('roles')->insertGetId([
            'name' => 'School List Performance QA',
            'guard_name' => 'web',
            'school_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $schoolIds = [];
        for ($index = 1; $index <= 12; $index++) {
            $userId = DB::connection('mysql')->table('users')->insertGetId([
                'first_name' => 'School',
                'last_name' => "Admin {$index}",
                'email' => "school-list-performance-{$index}@example.test",
                'password' => 'not-used',
                'status' => 1,
                'two_factor_enabled' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection('mysql')->table('model_has_roles')->insert([
                'role_id' => $roleId,
                'model_type' => User::class,
                'model_id' => $userId,
            ]);
            $schoolIds[] = DB::connection('mysql')->table('schools')->insertGetId([
                'name' => "School List Performance {$index}",
                'address' => 'Local test only',
                'support_phone' => "10000{$index}",
                'support_email' => "school-list-performance-{$index}@example.test",
                'tagline' => 'Local test only',
                'logo' => '',
                'admin_id' => $userId,
                'status' => 1,
                'installed' => 1,
                'code' => "PERF{$index}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $queries = [];
        DB::connection('mysql')->listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $schools = School::on('mysql')
            ->whereIn('id', $schoolIds)
            ->with(
                'user:id,first_name,last_name,email,image,mobile,email_verified_at,two_factor_enabled',
                'user.roles',
            )
            ->get();

        $payload = $schools->map(static function (School $school): array {
            $school->user?->makeHidden('roles');

            return $school->toArray();
        });

        $roleQueries = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'from `roles`') || str_contains($sql, 'from "roles"'),
        ));

        $this->assertCount(12, $schools);
        $this->assertCount(1, $roleQueries, 'The School list must issue one eager-load query for all admin roles.');
        $this->assertLessThanOrEqual(3, count($queries), 'School, User, and Role query counts must remain constant as the page grows.');
        $this->assertTrue($schools->every(static fn (School $school): bool => $school->user?->relationLoaded('roles') === true));
        $this->assertTrue($payload->every(static fn (array $school): bool => ($school['user']['role'] ?? null) === 'School List Performance QA'));
        $this->assertTrue($payload->every(static fn (array $school): bool => !array_key_exists('roles', $school['user'])));
    }

    public function test_school_list_controller_uses_the_eager_loaded_relation(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/SchoolController.php'));

        $this->assertStringContainsString("'user.roles'", $source);
        $this->assertStringContainsString("makeHidden('roles')", $source);
    }
}
