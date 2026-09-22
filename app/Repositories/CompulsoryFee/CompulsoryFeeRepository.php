<?php

namespace App\Repositories\CompulsoryFee;

use App\Models\CompulsoryFee;
use App\Repositories\Saas\SaaSRepository;
use App\Services\TrustedSchoolScopeService;
use Illuminate\Support\Facades\Auth;

class CompulsoryFeeRepository extends SaaSRepository implements CompulsoryFeeInterface {

    public function __construct(CompulsoryFee $model) {
        parent::__construct($model);
    }

    public function defaultModel()
    {
        return app(TrustedSchoolScopeService::class)->apply(
            $this->model->newQuery(),
            Auth::user(),
        );
    }
}
