<?php

namespace App\Http\Controllers\Api\Dify;

use App\Http\Controllers\Controller;
use App\Models\ClassSection;
use App\Models\Students;
use App\Models\Timetable;
use App\Models\User;
use App\Services\CachingService;
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

    /**
     * 班级学生名单（分页）
     *
     * GET /api/dify/class/student-list
     *
     * Query params:
     *   class_section_id   必填，班级 section ID
     *   session_year_id    可选，默认当前学年
     *   search             可选，按学生姓名或 admission_no 搜索
     *   page               可选，默认 1
     *   per_page           可选，默认 20，最大 50
     *
     * 输出：class{class_section_id,class_name,section_name}, items[], pagination
     * 不返回电话、地址、生日、证件等隐私字段
     */
    public function classStudentList(Request $request): JsonResponse
    {
        try {
            // class_section_id is required
            if (!$request->query('class_section_id')) {
                return response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'MISSING_PARAMETER',
                        'message' => '缺少 class_section_id 参数',
                    ],
                ], 422);
            }

            // Validate optional params
            $request->validate([
                'class_section_id' => 'integer|min:1',
                'session_year_id'  => 'nullable|integer|min:1',
                'search'           => 'nullable|string|max:100',
                'page'             => 'nullable|integer|min:1',
                'per_page'         => 'nullable|integer|min:1|max:50',
            ]);

            $classSectionId = (int) $request->query('class_section_id');

            // Check class_section exists
            $classSection = ClassSection::with(['class', 'section'])->find($classSectionId);
            if (!$classSection) {
                return response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'NOT_FOUND',
                        'message' => '班级不存在',
                    ],
                ], 404);
            }

            // Resolve session year (default to current)
            $sessionYearId = $request->query('session_year_id');
            if (!$sessionYearId) {
                $schoolId = $request->attributes->get('dify_school_id');
                $cache = app(CachingService::class);
                $sessionYear = $cache->getDefaultSessionYear($schoolId);
                $sessionYearId = $sessionYear ? $sessionYear->id : null;
            }

            // Pagination
            $page    = (int) $request->query('page', 1);
            $perPage = (int) $request->query('per_page', 20);
            $perPage = min($perPage, 50);

            // Query students in this class section
            $query = Students::query()
                ->where('class_section_id', $classSectionId)
                ->with(['user']);

            if ($sessionYearId) {
                $query->where('session_year_id', $sessionYearId);
            }

            // Search by student name or admission_no
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

            // Order by roll_number ascending
            $paginator = $query->orderBy('roll_number', 'asc')
                ->paginate($perPage, ['*'], 'page', $page);

            // Map to safe fields (no guardian_phone, address, birthday, etc.)
            $items = $paginator->map(function (Students $student) {
                return [
                    'student_id'          => $student->id,
                    'admission_no'        => $student->admission_no,
                    'roll_number'         => $student->roll_number,
                    'student_name'        => trim(
                        ($student->user->first_name ?? '') . ' ' . ($student->user->last_name ?? '')
                    ),
                    'gender'              => $student->user->gender ?? null,
                    'application_status'  => $student->application_status,
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => [
                    'class'      => [
                        'class_section_id' => $classSection->id,
                        'class_name'       => $classSection->class->name ?? null,
                        'section_name'     => $classSection->section->name ?? null,
                    ],
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

    /**
     * 教师今日课表
     *
     * GET /api/dify/teacher/today-schedule
     *
     * Query params:
     *   teacher_id    可选，教师 user_id（与 teacher_name 至少传一个）
     *   teacher_name  可选，按教师姓名模糊搜索（与 teacher_id 至少传一个）
     *   day           可选，英文星期名，默认今天，例如 Monday / Friday
     *
     * 输出：date, day, day_label, teacher{id,name}, total_periods, periods[]
     */
    public function teacherTodaySchedule(Request $request): JsonResponse
    {
        try {
            // Validate inputs
            $request->validate([
                'teacher_id'   => 'nullable|integer|min:1',
                'teacher_name' => 'nullable|string|max:100',
                'day'          => 'nullable|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            ]);

            // At least one of teacher_id/teacher_name must be provided
            $teacherId   = $request->query('teacher_id');
            $teacherName = $request->query('teacher_name');
            if (!$teacherId && !$teacherName) {
                return response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'MISSING_PARAMETER',
                        'message' => '请至少提供 teacher_id 或 teacher_name',
                    ],
                ], 422);
            }

            // Resolve day (default today)
            $day = $request->query('day', Carbon::now()->format('l'));

            // Resolve teacher
            $teacherQuery = User::query()->role('Teacher');

            if ($teacherId) {
                $teacherQuery->where('id', $teacherId);
            } else {
                $teacherQuery->where(function ($q) use ($teacherName) {
                    $q->where('first_name', 'like', "%{$teacherName}%")
                      ->orWhere('last_name', 'like', "%{$teacherName}%")
                      ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$teacherName}%"]);
                });
            }

            $teacher = $teacherQuery->first();

            if (!$teacher) {
                return response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'NOT_FOUND',
                        'message' => '未找到匹配的教师',
                    ],
                ], 404);
            }

            // Query timetable periods for this teacher on the given day
            $periods = Timetable::query()
                ->whereHas('subject_teacher', function ($q) use ($teacher) {
                    $q->where('teacher_id', $teacher->id);
                })
                ->where('day', $day)
                ->with([
                    'class_section.class',
                    'class_section.section',
                    'subject',
                ])
                ->orderBy('start_time')
                ->get();

            // Build period list
            $periodList = $periods->map(function (Timetable $t) {
                return [
                    'start_time'   => $t->start_time,
                    'end_time'     => $t->end_time,
                    'subject_name' => $t->subject->name ?? null,
                    'class_name'   => $t->class_section->class->name ?? null,
                    'section_name' => $t->class_section->section->name ?? null,
                    'type'         => $t->type,
                    'note'         => $t->note,
                ];
            });

            $dayLabels = [
                'Monday'    => '星期一',
                'Tuesday'   => '星期二',
                'Wednesday' => '星期三',
                'Thursday'  => '星期四',
                'Friday'    => '星期五',
                'Saturday'  => '星期六',
                'Sunday'    => '星期日',
            ];

            return response()->json([
                'success' => true,
                'data'    => [
                    'date'          => Carbon::now()->toDateString(),
                    'day'           => $day,
                    'day_label'     => $dayLabels[$day] ?? $day,
                    'teacher'       => [
                        'id'   => $teacher->id,
                        'name' => trim($teacher->first_name . ' ' . $teacher->last_name),
                    ],
                    'total_periods' => $periodList->count(),
                    'periods'       => $periodList->values(),
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
