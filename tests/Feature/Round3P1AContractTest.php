<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class Round3P1AContractTest extends TestCase
{
    public function test_api_families_and_payment_status_routes_have_explicit_family_gates(): void
    {
        foreach ([
            'api/student/subjects' => 'apiFamily:student',
            'api/parent/test' => 'apiFamily:guardian',
            'api/teacher/subjects' => 'apiFamily:teacher',
            'api/staff/profile' => 'apiFamily:staff',
            'api/create-transportation-expense' => 'apiFamily:staff',
            'api/get-transportation-expense' => 'apiFamily:staff',
            'api/transport/expense/categories/list' => 'apiFamily:staff',
            'api/payment-confirmation' => 'apiFamily:any',
            'api/payment-transactions' => 'apiFamily:any',
        ] as $uri => $middleware) {
            $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === $uri);
            $this->assertNotNull($route, $uri);
            $this->assertContains('APISwitchDatabase', $route->gatherMiddleware(), $uri);
            $this->assertContains($middleware, $route->gatherMiddleware(), $uri);
        }

        $studentLogin = file_get_contents(app_path('Http/Controllers/Api/StudentApiController.php'));
        $guardianLogin = file_get_contents(app_path('Http/Controllers/Api/ParentApiController.php'));
        $staffLogin = file_get_contents(app_path('Http/Controllers/Api/TeacherApiController.php'));
        $this->assertStringContainsString("['student-api']", $studentLogin);
        $this->assertStringContainsString("['guardian-api']", $guardianLogin);
        $this->assertStringContainsString("['staff-api']", $staffLogin);
    }

    public function test_every_tenant_finance_route_has_school_admin_direct_url_denial(): void
    {
        foreach ([
            'students.finance.show', 'students.fee-assignment.show', 'fees.index', 'fees-type.index',
            'fees.compulsory.store', 'fees.optional.store', 'expense.index', 'finance-transactions.index',
            'bank-accounts.index', 'bank-transfers.index', 'fund-handovers.index', 'finance-staff.index',
            'student-ledger.index', 'outstanding-fees.index', 'finance-dashboard.index',
            'finance-report.index', 'bank-account-report.index',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('schoolAdminFinanceDenied', $route->gatherMiddleware(), $name);
        }

        foreach ([
            'api/staff/get-fees',
            'api/staff/fees-paid-list',
            'api/staff/student-fees-receipt',
            'api/create-transportation-expense',
            'api/get-transportation-expense',
            'api/transport/expense/categories/list',
        ] as $uri) {
            $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === $uri);
            $this->assertContains('schoolAdminFinanceDenied', $route->gatherMiddleware(), $uri);
        }
    }

    public function test_payment_status_gets_are_owned_minimal_and_read_only(): void
    {
        $service = file_get_contents(app_path('Services/ApiPaymentStatusService.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/ApiController.php'));
        $methods = substr($controller, strpos($controller, 'public function getPaymentConfirmation'), strpos($controller, 'public function getGallery') - strpos($controller, 'public function getPaymentConfirmation'));

        $this->assertStringContainsString("where('school_id', \$actor->school_id)", $service);
        $this->assertStringContainsString("whereIn('user_id', \$ownerIds)", $service);
        $this->assertStringContainsString("\$status === 'pending'", $service);
        $this->assertStringNotContainsString('->update(', $methods);
        $this->assertStringNotContainsString('TransportationPayment', $methods);
        $this->assertStringNotContainsString("'metadata'", $service);
        $this->assertStringNotContainsString("'order_id' =>", $service);
    }

    public function test_money_services_hold_receivable_and_source_account_locks(): void
    {
        $fees = file_get_contents(app_path('Services/FeesPaymentService.php'));
        $optional = file_get_contents(app_path('Services/OfflineFeePaymentAuthorityService.php'));
        $transfers = file_get_contents(app_path('Services/BankTransferService.php'));

        $this->assertStringContainsString("DB::connection('school')->transaction", $fees);
        $this->assertStringContainsString("->where('school_id', \$schoolId)", $fees);
        $this->assertGreaterThanOrEqual(2, substr_count($fees, 'lockForUpdate()'));
        $this->assertStringContainsString('OptionalFee::query()', $optional);
        $this->assertGreaterThanOrEqual(5, substr_count($optional, 'lockForUpdate()'));
        $this->assertStringContainsString("->whereIn('id', [\$fromAccountId, \$toAccountId])", $transfers);
        $this->assertStringContainsString("->orderBy('id')", $transfers);
        $this->assertStringContainsString('->lockForUpdate()', $transfers);
    }
}
