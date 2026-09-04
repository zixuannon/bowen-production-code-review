<?php
namespace App\Services;
use App\Models\CentralFinanceUser;
use App\Models\School;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
final class CentralFinanceSchoolScopeService {
    public function assertCanSubmitCollections(CentralFinanceUser $actor, int $schoolId): void {
        School::on('mysql')->findOrFail($schoolId);
        $scope = DB::connection('mysql')->table('central_finance_user_school_scopes')->where(['user_id' => $actor->id, 'school_id' => $schoolId])->first();
        if (!$scope || !$scope->can_view || !$scope->can_submit_collections) {
            throw new AuthorizationException('The central actor cannot submit pending collections for this School.');
        }
    }

    public function assertCanOperate(CentralFinanceUser $actor, int $schoolId): void {
        School::on('mysql')->findOrFail($schoolId);
        $scope=DB::connection('mysql')->table('central_finance_user_school_scopes')->where(['user_id'=>$actor->id,'school_id'=>$schoolId])->first();
        if (!$scope || !$scope->can_view || !$scope->can_operate) throw new AuthorizationException('The central actor is not authorized for this School.');
    }

    public function assertCanApproveReimbursements(CentralFinanceUser $actor, int $schoolId): void {
        $this->assertCanOperate($actor, $schoolId);
        $scope = DB::connection('mysql')->table('central_finance_user_school_scopes')
            ->where(['user_id' => $actor->id, 'school_id' => $schoolId])
            ->first();
        if (!$scope || !$scope->can_approve_reimbursements) {
            throw new AuthorizationException('The central actor cannot approve reimbursements for this School.');
        }
    }

    public function assertCanConfirmFunding(CentralFinanceUser $actor, int $schoolId): void {
        $this->assertCanOperate($actor, $schoolId);
        $scope = DB::connection('mysql')->table('central_finance_user_school_scopes')
            ->where(['user_id' => $actor->id, 'school_id' => $schoolId])
            ->first();
        if (!$scope || !$scope->can_confirm_funding) {
            throw new AuthorizationException('The central actor cannot confirm HQ funding for this School.');
        }
    }
}
