<?php

namespace App\Repositories\Expense;

use App\Models\Expense;
use App\Repositories\Saas\SaaSRepository;
use App\Services\TrustedSchoolScopeService;
use Illuminate\Support\Facades\Auth;

class ExpenseRepository extends SaaSRepository implements ExpenseInterface {

    public function __construct(Expense $model) {
        parent::__construct($model, 'expense');
    }

    public function defaultModel()
    {
        return app(TrustedSchoolScopeService::class)->apply(
            $this->model->newQuery(),
            Auth::user(),
        );
    }
}
