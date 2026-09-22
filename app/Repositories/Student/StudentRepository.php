<?php

namespace App\Repositories\Student;

use App\Models\Students;
use App\Repositories\Saas\SaaSRepository;
use App\Services\TrustedSchoolScopeService;
use Illuminate\Support\Facades\Auth;

class StudentRepository extends SaaSRepository implements StudentInterface {
    public function __construct(Students $model) {
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
