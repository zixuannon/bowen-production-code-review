<?php

namespace App\Http\Controllers;

use App\Repositories\FeesType\FeesTypeInterface;
use App\Models\FeesType;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use App\Services\CentralFinanceDataIsolationService;
use App\Services\QaFeeClassificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class FeesTypeController extends Controller
{
    private FeesTypeInterface $feesType;

    public function __construct(FeesTypeInterface $feesType)
    {
        $this->feesType = $feesType;
    }

    public function index()
    {
        ResponseService::noFeatureThenRedirect('Fees Management');
        ResponseService::noPermissionThenRedirect('fees-type-list');
        return view('Income.fees_types');
    }

    private function workflowQuery(bool $withTrashed = false)
    {
        $schoolId = (int) auth()->user()->school_id;
        $query = FeesType::query()->where('school_id', $schoolId);
        if ($withTrashed) $query->withTrashed();
        return app(CentralFinanceDataIsolationService::class)->applyTenantForSchoolWorkflow($query, 'fee_type', $schoolId, 'fees_types.id');
    }

    private function feeResponse(string $message, bool $error = false)
    {
        return response()->json(['error' => $error, 'message' => __($message), 'data' => null,
            'code' => config($error ? 'constants.RESPONSE_CODE.EXCEPTION_ERROR' : 'constants.RESPONSE_CODE.SUCCESS')]);
    }

    public function store(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Fees Management');
        ResponseService::noPermissionThenSendJson('fees-type-create');
        try {
            DB::beginTransaction();
            $feesType = FeesType::create(array_merge($request->only('name', 'description'), ['school_id' => (int) auth()->user()->school_id]));
            app(QaFeeClassificationService::class)->inheritCreated('fee_type', $feesType, auth()->user());
            DB::commit();
            return $this->feeResponse('Data Stored Successfully');
        } catch (Throwable $e) {
            DB::rollback();
            ResponseService::logErrorResponse($e, "FeesTypeController -> store method", 'Error Occurred', false);
            return $this->feeResponse('Error Occurred', true);
        }
    }

    public function show()
    {
        ResponseService::noFeatureThenRedirect('Fees Management');
        ResponseService::noPermissionThenRedirect('fees-type-list');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');
        $showDeleted = request('show_deleted');

        $sql = $this->workflowQuery()
            ->when($search, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('id', 'LIKE', "%$search%")
                        ->orwhere('name', 'LIKE', "%$search%")
                        ->orwhere('description', 'LIKE', "%$search%")
                        ->orwhere('created_at', 'LIKE', "%" . date('Y-m-d H:i:s', strtotime($search)) . "%")
                        ->orwhere('updated_at', 'LIKE', "%" . date('Y-m-d H:i:s', strtotime($search)) . "%");
                });
            })
            ->when(!empty($showDeleted), function ($query) {
                $query->onlyTrashed();
            });
        $total = $sql->count();
        if ($offset >= $total && $total > 0) {
            $lastPage = floor(($total - 1) / $limit) * $limit; // calculate last page offset
            $offset = $lastPage;
        }
        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();
        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;
        foreach ($res as $row) {
            if ($showDeleted) {
                $operate = BootstrapTableService::reactivateButton(route('fees-type.reactivate', $row->id));
            } else {
                $operate = BootstrapTableService::editButton(route('fees-type.update', $row->id));
                $operate .= BootstrapTableService::deactivateButton(route('fees-type.deactivate', $row->id));
            }
            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function update(Request $request, $id)
    {
        ResponseService::noFeatureThenSendJson('Fees Management');
        ResponseService::noPermissionThenSendJson('fees-type-edit');
        try {
            DB::transaction(function () use ($request, $id): void {
                $this->workflowQuery()->lockForUpdate()->findOrFail($id)->update([
                    'name' => $request->edit_name,
                    'description' => $request->edit_description,
                ]);
            });
            return $this->feeResponse('Data Updated Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "FeesTypeController -> Update method", 'Error Occurred', false);
            return $this->feeResponse('Error Occurred', true);
        }
    }

    public function destroy($id)
    {
        ResponseService::errorResponse('Fee type configuration must be deactivated through the lifecycle action.');
    }

    public function deactivate(Request $request, int $id)
    {
        ResponseService::noFeatureThenSendJson('Fees Management');
        ResponseService::noPermissionThenSendJson('fees-type-delete');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        try {
            DB::beginTransaction();
            $feesType = $this->workflowQuery()->lockForUpdate()->findOrFail($id);
            $feesType->delete();
            app(\App\Services\SchoolRecordLifecycleAuditService::class)->record(auth()->user(), $feesType, \App\Models\SchoolRecordLifecycleAudit::DEACTIVATE, $data['reason']);
            DB::commit();
            return $this->feeResponse('Fee type configuration deactivated.');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, "FeesTypeController -> destroy method", 'Error Occurred', false);
            return $this->feeResponse('Error Occurred', true);
        }
    }

    public function reactivate(Request $request, int $id)
    {
        ResponseService::noFeatureThenSendJson('Fees Management');
        ResponseService::noPermissionThenSendJson('fees-type-delete');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        try {
            DB::beginTransaction();
            $feesType = $this->workflowQuery(true)->lockForUpdate()->findOrFail($id);
            $feesType->restore();
            app(\App\Services\SchoolRecordLifecycleAuditService::class)->record(auth()->user(), $feesType, \App\Models\SchoolRecordLifecycleAudit::REACTIVATE, $data['reason']);
            DB::commit();
            return $this->feeResponse('Fee type configuration reactivated.');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, "FeesTypeController -> reactivate method", 'Error Occurred', false);
            return $this->feeResponse('Error Occurred', true);
        }
    }

    public function restore(int $id)
    {
        ResponseService::errorResponse('Fee type configuration must be reactivated through the lifecycle action.');
    }

    public function trash($id)
    {
        ResponseService::noFeatureThenRedirect('Fees Management');
        ResponseService::noPermissionThenSendJson('fees-type-delete');
        ResponseService::errorResponse('Permanent deletion is not available for fee type configuration. Keep it deactivated instead.');
    }
}
