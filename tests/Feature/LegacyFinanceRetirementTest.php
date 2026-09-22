<?php

namespace Tests\Feature;

use App\Models\BankTransfer;
use App\Models\CompulsoryFee;
use App\Models\Expense;
use App\Models\Students;
use App\Models\User;
use App\Repositories\CompulsoryFee\CompulsoryFeeRepository;
use App\Repositories\Expense\ExpenseRepository;
use App\Repositories\Student\StudentRepository;
use App\Services\BankTransferService;
use App\Services\FinanceOperatingWriteService;
use App\Services\FundHandoverService;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Tests\TestCase;

class LegacyFinanceRetirementTest extends TestCase
{
    public function test_all_legacy_operating_routes_are_explicitly_retired(): void
    {
        foreach ([
            ['get', '/bank-accounts/create'],
            ['post', '/bank-accounts'],
            ['get', '/bank-accounts/1/edit'],
            ['put', '/bank-accounts/1'],
            ['delete', '/bank-accounts/1'],
            ['put', '/bank-accounts/1/assignments'],
            ['post', '/bank-transfers'],
            ['delete', '/bank-transfers/1'],
            ['post', '/fund-handovers'],
            ['post', '/fund-handovers/1/confirm'],
            ['post', '/fund-handovers/1/reject'],
            ['post', '/fund-handovers/1/cancel'],
            ['post', '/group-finance/operating/expense'],
            ['post', '/group-finance/operating/receive-money'],
            ['post', '/group-finance/operating/student-fee'],
            ['post', '/group-finance/operating/bank-transfer'],
            ['post', '/group-finance/operating/fund-handover'],
        ] as [$method, $uri]) {
            $this->withoutMiddleware()->{$method}($uri)->assertGone();
        }
    }

    public function test_legacy_services_reject_before_any_database_write(): void
    {
        $actor = new User(['school_id' => 1]);
        $actor->id = 1;

        foreach ([
            fn () => app(BankTransferService::class)->create($actor, []),
            fn () => app(FundHandoverService::class)->create($actor, []),
            fn () => app(FinanceOperatingWriteService::class)->createExpense($actor, []),
        ] as $operation) {
            try {
                $operation();
                $this->fail('A retired legacy Finance service reached a write path.');
            } catch (GoneHttpException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_active_student_fee_and_expense_repositories_use_explicit_school_scope(): void
    {
        $actor = new User(['school_id' => 37]);
        $actor->id = 99;
        Auth::setUser($actor);

        try {
            foreach ([
                [app(StudentRepository::class), Students::class],
                [app(CompulsoryFeeRepository::class), CompulsoryFee::class],
                [app(ExpenseRepository::class), Expense::class],
            ] as [$repository, $model]) {
                $query = $repository->defaultModel();
                $this->assertStringContainsString('`school_id` = ?', $query->toSql());
                $this->assertSame([37], $query->getBindings());
                $this->assertInstanceOf($model, $query->getModel());
            }
        } finally {
            Auth::forgetUser();
        }
    }

    public function test_legacy_operating_navigation_has_no_write_tab(): void
    {
        $view = file_get_contents(resource_path('views/group-finance/operating/_context.blade.php'));
        $this->assertStringNotContainsString('group-finance.operating.operations', $view);
        $this->assertStringNotContainsString('Finance Operations', $view);
    }

    public function test_central_finance_workflows_do_not_depend_on_retired_models(): void
    {
        $central = file_get_contents(app_path('Services/CentralFinanceOperatingDocumentService.php'));
        $this->assertStringNotContainsString('BankAccount::', $central);
        $this->assertStringNotContainsString('BankTransfer::', $central);
    }
}
