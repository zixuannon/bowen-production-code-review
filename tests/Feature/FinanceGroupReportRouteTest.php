<?php

namespace Tests\Feature;

use App\Models\FinanceGroup;
use App\Models\FinanceGroupUser;
use App\Models\User;
use App\Http\Middleware\CheckForMaintenanceMode;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckSchoolStatus;
use App\Http\Middleware\CheckTwoFactorAuthenticated;
use App\Http\Middleware\DemoMiddleware;
use App\Http\Middleware\InitializeTenantDatabase;
use App\Http\Middleware\LanguageManager;
use App\Http\Middleware\MustVerifyEmail;
use App\Http\Middleware\Status;
use App\Http\Middleware\SwitchDatabase;
use App\Http\Middleware\WizardSettings;
use dacoto\LaravelWizardInstaller\Middleware\ToInstallMiddleware;
use App\Services\FinanceGroupReportService;
use App\Services\FinanceGroupScopeService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinanceGroupReportRouteTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $mysqlConnection;
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mysqlConnection = config('database.connections.mysql');
        $this->database = tempnam(sys_get_temp_dir(), 'eschool_group_route_');
        Config::set('database.connections.mysql', [
            'driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        Schema::connection('mysql')->create('schools', function ($table): void {
            $table->id(); $table->string('name'); $table->string('code')->unique(); $table->string('database_name')->unique(); $table->timestamp('deleted_at')->nullable(); $table->timestamps();
        });
        Schema::connection('mysql')->create('users', function ($table): void {
            $table->id(); $table->string('first_name')->nullable(); $table->string('last_name')->nullable(); $table->string('email')->nullable(); $table->unsignedBigInteger('school_id')->nullable(); $table->timestamp('deleted_at')->nullable(); $table->timestamps();
        });
        Schema::connection('mysql')->create('roles', function ($table): void {
            $table->id(); $table->string('name'); $table->string('guard_name'); $table->unsignedBigInteger('school_id')->nullable(); $table->timestamps();
        });
        Schema::connection('mysql')->create('model_has_roles', function ($table): void {
            $table->unsignedBigInteger('role_id'); $table->string('model_type'); $table->unsignedBigInteger('model_id');
        });
        DB::connection('mysql')->table('schools')->insert(['id' => 1, 'name' => 'School A', 'code' => 'GROUP_A', 'database_name' => 'group_a']);
        DB::connection('mysql')->table('users')->insert([
            ['id' => 100, 'first_name' => 'HQ', 'last_name' => 'Reporter', 'email' => 'hq@example.test', 'school_id' => null],
            ['id' => 101, 'first_name' => 'Out', 'last_name' => 'Scope', 'email' => 'out@example.test', 'school_id' => null],
        ]);
        (require database_path('migrations/2026_08_18_000001_create_finance_group_scope_tables.php'))->up();

        $scope = app(FinanceGroupScopeService::class);
        $group = $scope->createGroup(['name' => 'Route QA Group', 'code' => 'ROUTE_QA', 'status' => 'active']);
        $scope->addSchool($group, 1);
        $groupUser = $scope->addUser($group, 100);
        $scope->grantScope($groupUser, 'view_reports', 'SCHOOL', 1);
        $scope->grantScope($groupUser, 'export_reports', 'SCHOOL', 1);

        app()->instance(FinanceGroupReportService::class, new class extends FinanceGroupReportService {
            public function __construct() {}
            public function register(\App\Models\FinanceGroupUser $groupUser, array $filters = []): array
            {
                return [
                    'rows' => collect([[
                        'ledger_key' => 'tenant:1:other_income:1', 'posting_date' => '2026-08-18', 'school_name' => 'School A',
                        'transaction_class' => 'OTHER_INCOME', 'reference_no' => 'GROUP_QA_OTHER', 'fund_account_name' => 'Cash',
                        'money_in' => 40.0, 'money_out' => 0.0, 'operating_income' => 40.0, 'operating_expense' => 0.0,
                        'internal_transfer_amount' => 0.0,
                    ]]),
                    'summary' => ['operating_income' => 40.0, 'operating_expense' => 0.0, 'operating_net' => 40.0, 'internal_transfer_amount' => 0.0],
                    'schools' => collect([['school_id' => 1, 'school_name' => 'School A', 'status' => 'complete', 'operating_income' => 40.0, 'operating_expense' => 0.0, 'internal_transfer_amount' => 0.0]]),
                    'incomplete' => collect(),
                ];
            }
        });
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        Config::set('database.connections.mysql', $this->mysqlConnection);
        DB::setDefaultConnection('mysql');
        @unlink($this->database);
        parent::tearDown();
    }

    public function test_scoped_group_report_and_csv_export_are_read_only_and_authorized(): void
    {
        $group = FinanceGroup::on('mysql')->where('code', 'ROUTE_QA')->firstOrFail();
        $this->assertNotNull($group->id);
        $this->assertSame(1, FinanceGroupUser::on('mysql')->where('group_id', $group->id)->where('central_user_id', 100)->where('status', 'active')->count());
        $before = $this->centralHash();

        $this->withoutRouteGuards()->actingAs(User::on('mysql')->findOrFail(100));
        $this->assertSame(100, auth()->id());
        $response = $this->get(route('finance-groups.reports.export', $group));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('tenant:1:other_income:1', $response->streamedContent());

        $this->assertSame($before, $this->centralHash());
    }

    public function test_central_user_without_explicit_group_scope_is_denied(): void
    {
        $group = FinanceGroup::on('mysql')->where('code', 'ROUTE_QA')->firstOrFail();
        $this->withoutRouteGuards()->actingAs(User::on('mysql')->findOrFail(101))
            ->get(route('finance-groups.reports.index', $group))
            ->assertForbidden();
    }

    private function centralHash(): string
    {
        $parts = [];
        foreach (['finance_groups', 'finance_group_schools', 'finance_group_users', 'finance_group_user_scopes', 'finance_group_user_tenant_identities'] as $table) {
            $parts[] = $table . ':' . json_encode(DB::connection('mysql')->table($table)->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all(), JSON_THROW_ON_ERROR);
        }

        return hash('sha256', implode('|', $parts));
    }

    private function withoutRouteGuards(): static
    {
        return $this->withoutMiddleware([
            CheckRole::class, CheckSchoolStatus::class, Status::class, SwitchDatabase::class,
            MustVerifyEmail::class, CheckForMaintenanceMode::class, CheckTwoFactorAuthenticated::class,
            WizardSettings::class, LanguageManager::class, InitializeTenantDatabase::class,
            DemoMiddleware::class, ToInstallMiddleware::class,
        ]);
    }
}
