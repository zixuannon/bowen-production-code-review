<?php

namespace App\Http\Controllers\Api\Dify;

use App\Http\Controllers\Controller;
use App\Models\Students;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
