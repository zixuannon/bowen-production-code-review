<?php

namespace App\Http\Controllers\Api\Dify;

use App\Http\Controllers\Controller;
use App\Models\Students;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DifyApiController extends Controller
{
    /**
     * 今日报名数统计
     *
     * GET /api/dify/admission/today-count?date=2026-06-25
     *
     * Header:
     *   Authorization: Bearer dify_sk_xxx
     *   school-code: BOWEN-MYANMAR
     *   Accept: application/json
     */
    public function todayAdmissionCount(Request $request): JsonResponse
    {
        try {
            // Parse date, default to today
            $date = $request->query('date', Carbon::now()->toDateString());

            // Validate date format
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'INVALID_DATE',
                        'message' => '日期格式错误，请使用 YYYY-MM-DD',
                    ],
                ], 422);
            }

            $targetDate = Carbon::parse($date);

            // Count students with admission_date = target date
            // Students table is in the switched school database
            $baseQuery = Students::whereDate('admission_date', $targetDate->toDateString());

            $total = $baseQuery->count();

            // Online applications (application_type = 'online')
            $onlineCount = (clone $baseQuery)
                ->where('application_type', 'online')
                ->count();

            // Offline applications (application_type = 'offline')
            $offlineCount = (clone $baseQuery)
                ->where('application_type', 'offline')
                ->count();

            // Not-yet-accepted (application_status = 0)
            // Note: status 0 may include both pending and rejected applications
            $pendingCount = (clone $baseQuery)
                ->where('application_status', 0)
                ->count();

            // Accepted (application_status = 1)
            $approvedCount = (clone $baseQuery)
                ->where('application_status', 1)
                ->count();

            return response()->json([
                'success' => true,
                'data'    => [
                    'date'           => $date,
                    'total'          => $total,
                    'offline_count'  => $offlineCount,
                    'online_count'   => $onlineCount,
                    'pending_count'  => $pendingCount,
                    'approved_count' => $approvedCount,
                ],
                'message' => 'OK',
            ]);
        } catch (\Exception) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'SERVER_ERROR',
                    'message' => '服务器内部错误',
                ],
            ], 500);
        }
    }

    /**
     * 报名学生列表（分页）
     *
     * GET /api/dify/admission/list
     *
     * Query params:
     *   admission_date      可选，YYYY-MM-DD 单日筛选
     *   admission_date_from 可选，YYYY-MM-DD 起始日期
     *   admission_date_to   可选，YYYY-MM-DD 结束日期
     *   search              可选，按学生姓名或 admission_no 搜索
     *   application_type    可选，online / offline
     *   application_status  可选，0=pending / 1=approved
     *   page                可选，默认 1
     *   per_page            可选，默认 20，最大 50
     *
     * 输出字段仅含非敏感信息：id, admission_no, admission_date,
     *   student_name, class_name, section_name,
     *   application_type, application_status, application_status_label
     */
    public function admissionList(Request $request): JsonResponse
    {
        try {
            // Validate query params
            $request->validate([
                'admission_date'      => 'nullable|date_format:Y-m-d',
                'admission_date_from' => 'nullable|date_format:Y-m-d',
                'admission_date_to'   => 'nullable|date_format:Y-m-d',
                'search'              => 'nullable|string|max:100',
                'application_type'    => 'nullable|in:online,offline',
                'application_status'  => 'nullable|integer|in:0,1',
                'page'                => 'nullable|integer|min:1',
                'per_page'            => 'nullable|integer|min:1|max:50',
            ]);

            // Pagination
            $page    = (int) $request->query('page', 1);
            $perPage = (int) $request->query('per_page', 20);
            $perPage = min($perPage, 50);

            // Base query with eager-loaded relations for name display
            $query = Students::query()
                ->with(['user', 'class_section.class', 'class_section.section']);

            // Filter: single date
            if ($date = $request->query('admission_date')) {
                $query->whereDate('admission_date', $date);
            }

            // Filter: date range
            if ($from = $request->query('admission_date_from')) {
                $query->whereDate('admission_date', '>=', $from);
            }
            if ($to = $request->query('admission_date_to')) {
                $query->whereDate('admission_date', '<=', $to);
            }

            // Filter: application_type
            if ($type = $request->query('application_type')) {
                $query->where('application_type', $type);
            }

            // Filter: application_status (use has() to allow value 0)
            if ($request->has('application_status')) {
                $query->where('application_status', (int) $request->query('application_status'));
            }

            // Search: by admission_no or student name
            if ($search = $request->query('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('admission_no', 'like', "%{$search}%")
                      ->orWhereHas('user', function ($uq) use ($search) {
                          $uq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
                      });
                });
            }

            // Paginate, ordered by newest first
            $paginator = $query->orderBy('id', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            // Map to safe output fields only
            $items = $paginator->map(function (Students $student) {
                return [
                    'id'                       => $student->id,
                    'admission_no'             => $student->admission_no,
                    'admission_date'           => $student->admission_date,
                    'student_name'             => trim(
                        ($student->user->first_name ?? '') . ' ' . ($student->user->last_name ?? '')
                    ),
                    'class_name'               => $student->class_section->class->name ?? null,
                    'section_name'             => $student->class_section->section->name ?? null,
                    'application_type'         => $student->application_type,
                    'application_status'       => $student->application_status,
                    'application_status_label' => $student->application_status == 1 ? 'Approved' : 'Pending',
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => [
                    'items'      => $items->values(),
                    'pagination' => [
                        'page'        => $paginator->currentPage(),
                        'per_page'    => $paginator->perPage(),
                        'total'       => $paginator->total(),
                        'total_pages' => $paginator->lastPage(),
                    ],
                ],
                'message' => 'OK',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (\Exception) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'SERVER_ERROR',
                    'message' => '服务器内部错误',
                ],
            ], 500);
        }
    }
}
