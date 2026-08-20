<?php

namespace Tests\Feature;

use App\Http\Controllers\GroupFinanceController;
use App\Http\Controllers\FinanceOperatingWorkspaceController;
use App\Models\FinanceGroup;
use App\Models\User;
use App\Services\FinanceGroupReportService;
use App\Services\FinanceGroupScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class GroupFinanceEntryRouteTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $mysqlConnection;
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mysqlConnection = config('database.connections.mysql');
        $this->database = tempnam(sys_get_temp_dir(), 'eschool_group_entry_');
        Config::set('database.connections.mysql', [
            'driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        Schema::connection('mysql')->create('schools', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('database_name')->unique();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('users', function ($table): void {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        // User::school_id has a legacy Guardian accessor that consults the
        // Spatie role relation for central users. These empty tables keep the
        // route characterization faithful without granting a test role.
        Schema::connection('mysql')->create('roles', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->unsignedBigInteger('school_id')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('model_has_roles', function ($table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        DB::connection('mysql')->table('schools')->insert([
            ['id' => 1, 'name' => 'Zixuan', 'code' => 'GROUP_ZX', 'database_name' => 'group_zx'],
            ['id' => 2, 'name' => 'Timecity', 'code' => 'GROUP_TC', 'database_name' => 'group_tc'],
        ]);
        DB::connection('mysql')->table('users')->insert([
            ['id' => 100, 'first_name' => 'Central', 'last_name' => 'Head Finance', 'email' => 'head@example.test', 'school_id' => null],
            ['id' => 101, 'first_name' => 'Central', 'last_name' => 'Unscoped', 'email' => 'none@example.test', 'school_id' => null],
        ]);
        (require database_path('migrations/2026_08_18_000001_create_finance_group_scope_tables.php'))->up();

        $scope = app(FinanceGroupScopeService::class);
        $group = $scope->createGroup(['name' => 'Bowen QA Group', 'code' => 'BOWEN_QA', 'status' => 'active']);
        $scope->addSchool($group, 1);
        $groupUser = $scope->addUser($group, 100);
        $scope->grantScope($groupUser, 'view_reports', 'SCHOOL', 1);
        $scope->grantScope($groupUser, 'export_reports', 'SCHOOL', 1);
        $scope->grantScope($groupUser, 'operate_finance', 'SCHOOL', 1);

        app()->instance(FinanceGroupReportService::class, new class extends FinanceGroupReportService {
            public function __construct()
            {
            }

            public function register(\App\Models\FinanceGroupUser $groupUser, array $filters = []): array
            {
                return [
                    'rows' => collect([[
                        'ledger_key' => 'tenant:1:other_income:1', 'posting_date' => '2026-08-19', 'school_name' => 'Zixuan',
                        'transaction_class' => 'OTHER_INCOME', 'reference_no' => 'GROUP_QA_OTHER', 'fund_account_name' => 'Cash',
                        'money_in' => 40.0, 'money_out' => 0.0, 'operating_income' => 40.0, 'operating_expense' => 0.0,
                        'internal_transfer_amount' => 0.0,
                    ]]),
                    'summary' => ['operating_income' => 40.0, 'operating_expense' => 0.0, 'operating_net' => 40.0, 'internal_transfer_amount' => 0.0],
                    'schools' => collect([['school_id' => 1, 'school_name' => 'Zixuan', 'status' => 'complete', 'operating_income' => 40.0, 'operating_expense' => 0.0, 'internal_transfer_amount' => 0.0]]),
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

    public function test_configured_central_head_finance_enters_group_finance_and_exports_without_writing(): void
    {
        $group = FinanceGroup::on('mysql')->where('code', 'BOWEN_QA')->firstOrFail();
        $before = $this->centralHash();

        $this->withoutMiddleware()->actingAs(User::on('mysql')->findOrFail(100));
        $this->get(route('group-finance.index'))
            ->assertRedirect(route('group-finance.show', $group));

        $view = app(GroupFinanceController::class)->show(new Request(), $group);
        $this->assertSame('group-finance.show', $view->name());
        $this->assertSame($group->id, $view->getData()['financeGroup']->id);
        $this->assertSame([1], $view->getData()['schools']->pluck('school_id')->all());
        $this->assertSame([1], app(FinanceGroupScopeService::class)
            ->accessibleSchools($group->users()->sole(), 'export_reports')->pluck('school_id')->all());
        $this->assertSame([1], $view->getData()['operatingSchools']->pluck('school_id')->all());

        // The first request intentionally has every route guard disabled;
        // bind the central web user again before exercising the export action.
        $this->actingAs(User::on('mysql')->findOrFail(100));
        $route = app('router')->getRoutes()->getByName('group-finance.export');
        $this->assertSame(GroupFinanceController::class, $route->getControllerClass());
        $response = app(GroupFinanceController::class)->export(new Request(), $group);
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('content-type'));
        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();
        $this->assertStringContainsString('tenant:1:other_income:1', $csv);
        $this->assertSame($before, $this->centralHash());

        $operatingEnter = app('router')->getRoutes()->getByName('group-finance.operating.enter');
        $this->assertSame(['POST'], $operatingEnter->methods());
        $this->assertSame(FinanceOperatingWorkspaceController::class, $operatingEnter->getControllerClass());
        $this->assertStringNotContainsString('database', $operatingEnter->uri());
    }

    public function test_unscoped_central_user_and_forged_school_are_rejected(): void
    {
        $group = FinanceGroup::on('mysql')->where('code', 'BOWEN_QA')->firstOrFail();
        $this->withoutMiddleware()->actingAs(User::on('mysql')->findOrFail(101))
            ->get(route('group-finance.index'))
            ->assertForbidden();

        $this->actingAs(User::on('mysql')->findOrFail(100));
        try {
            app(GroupFinanceController::class)->show(new Request(['school_id' => 2]), $group);
            $this->fail('A Group Finance user selected a School outside their explicit scope.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function centralHash(): string
    {
        $parts = [];
        foreach (['finance_groups', 'finance_group_schools', 'finance_group_users', 'finance_group_user_scopes', 'finance_group_user_tenant_identities'] as $table) {
            $parts[] = $table . ':' . json_encode(DB::connection('mysql')->table($table)->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all(), JSON_THROW_ON_ERROR);
        }

        return hash('sha256', implode('|', $parts));
    }
}
