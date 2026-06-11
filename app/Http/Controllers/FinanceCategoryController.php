<?php

namespace App\Http\Controllers;

use App\Repositories\FinanceCategory\FinanceCategoryInterface;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class FinanceCategoryController extends Controller
{
    private FinanceCategoryInterface $financeCategory;

    public function __construct(FinanceCategoryInterface $financeCategory)
    {
        $this->financeCategory = $financeCategory;
    }

    public function index()
    {
        ResponseService::noFeatureThenRedirect('Expense Management');
        ResponseService::noAnyPermissionThenRedirect(['expense-category-create', 'expense-category-list']);

        return view('finance-category.index');
    }

    public function list()
    {
        ResponseService::noFeatureThenRedirect('Expense Management');
        ResponseService::noAnyPermissionThenRedirect(['expense-category-create', 'expense-category-list']);

        $offset = request('offset', 0);
        $limit  = request('limit', 10);
        $sort   = request('sort', 'sort_order');
        $order  = request('order', 'ASC');
        $search = request('search');

        // Sort field whitelist to prevent injection
        $allowedSorts = ['id', 'type', 'category_code', 'name', 'local_name', 'sort_order', 'is_active', 'created_at', 'updated_at'];
        $sort = in_array($sort, $allowedSorts) ? $sort : 'sort_order';
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

        $sql = $this->financeCategory->builder()
            ->where(function ($q) use ($search) {
                $q->when($search, function ($query) use ($search) {
                    $query->where('name', 'LIKE', "%$search%")
                        ->orWhere('category_code', 'LIKE', "%$search%")
                        ->orWhere('local_name', 'LIKE', "%$search%");
                });
            });

        $total = $sql->count();

        if ($offset >= $total && $total > 0) {
            $lastPage = floor(($total - 1) / $limit) * $limit;
            $offset = $lastPage;
        }

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = 1;

        foreach ($res as $row) {
            $operate = '';
            $operate .= BootstrapTableService::editButton(route('finance-category.update', $row->id));
            if (!$row->is_default) {
                $operate .= BootstrapTableService::deleteButton(route('finance-category.destroy', $row->id));
            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['type_badge'] = $row->type === 'income'
                ? '<span class="badge badge-success">Income</span>'
                : '<span class="badge badge-danger">Expense</span>';
            $tempRow['status_badge'] = $row->is_active
                ? '<span class="badge badge-success">Active</span>'
                : '<span class="badge badge-warning">Inactive</span>';
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function store(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        ResponseService::noPermissionThenSendJson('expense-category-create');

        $request->validate([
            'type'          => 'required|in:income,expense',
            'category_code' => 'required|string|max:100|unique:finance_categories,category_code',
            'name'          => 'required|string|max:255',
            'local_name'    => 'nullable|string|max:255',
            'description'   => 'nullable|string',
            'sort_order'    => 'nullable|integer|min:0',
        ]);

        try {
            DB::beginTransaction();

            $this->financeCategory->create([
                'type'          => $request->type,
                'category_code' => strtoupper($request->category_code),
                'name'          => $request->name,
                'local_name'    => $request->local_name,
                'description'   => $request->description,
                'is_active'     => 1,  // New categories are always created active by default
                'sort_order'    => $request->sort_order ?? 0,
                'created_by'    => Auth::id(),
            ]);

            DB::commit();
            ResponseService::successResponse('Finance Category created successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, 'FinanceCategoryController -> Store');
            ResponseService::errorResponse();
        }
    }

    public function update(Request $request, $id)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        ResponseService::noPermissionThenSendJson('expense-category-edit');

        $request->validate([
            'type'          => 'required|in:income,expense',
            'category_code' => 'required|string|max:100|unique:finance_categories,category_code,' . $id,
            'name'          => 'required|string|max:255',
            'local_name'    => 'nullable|string|max:255',
            'description'   => 'nullable|string',
            'sort_order'    => 'nullable|integer|min:0',
            'is_active'     => 'nullable|boolean',
        ]);

        try {
            DB::beginTransaction();

            $this->financeCategory->update($id, [
                'type'          => $request->type,
                'category_code' => strtoupper($request->category_code),
                'name'          => $request->name,
                'local_name'    => $request->local_name,
                'description'   => $request->description,
                'is_active'     => $request->boolean('is_active') ? 1 : 0,
                'sort_order'    => $request->sort_order ?? 0,
                'updated_by'    => Auth::id(),
            ]);

            DB::commit();
            ResponseService::successResponse('Finance Category updated successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, 'FinanceCategoryController -> Update');
            ResponseService::errorResponse();
        }
    }

    public function destroy($id)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        ResponseService::noPermissionThenSendJson('expense-category-delete');

        try {
            $category = $this->financeCategory->findById($id);

            if ($category->is_default) {
                ResponseService::errorResponse('Default categories cannot be deleted');
                return;
            }

            DB::beginTransaction();
            $this->financeCategory->deleteById($id);
            DB::commit();
            ResponseService::successResponse('Finance Category deleted successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, 'FinanceCategoryController -> Destroy');
            ResponseService::errorResponse();
        }
    }
}
