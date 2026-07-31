<?php

namespace App\Http\Controllers;

use App\Models\LeaveDetail;
use App\Models\Staff;
use App\Repositories\Expense\ExpenseInterface;
use App\Repositories\Holiday\HolidayInterface;
use App\Repositories\Leave\LeaveInterface;
use App\Repositories\LeaveDetail\LeaveDetailInterface;
use App\Repositories\LeaveMaster\LeaveMasterInterface;
use App\Repositories\SessionYear\SessionYearInterface;
use App\Repositories\Staff\StaffInterface;
use App\Repositories\User\UserInterface;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\ResponseService;
use App\Services\SessionYearsTrackingsService;
use App\Services\StaffLeave\TwoStageLeaveService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use App\Repositories\Files\FilesInterface;
use Illuminate\Support\Facades\Validator;
use App\Rules\MaxFileSize;
use Storage;

class LeaveController extends Controller
{

    private LeaveInterface $leave;
    private SessionYearInterface $sessionYear;
    private LeaveDetailInterface $leaveDetail;
    private CachingService $cache;
    private LeaveMasterInterface $leaveMaster;
    private ExpenseInterface $expense;
    private UserInterface $user;
    private HolidayInterface $holiday;
    private StaffInterface $staff;
    private FilesInterface $files;
    private SessionYearsTrackingsService $sessionYearsTrackingsService;

    public function __construct(LeaveInterface $leave, SessionYearInterface $sessionYear, LeaveDetailInterface $leaveDetail, CachingService $cache, LeaveMasterInterface $leaveMaster, ExpenseInterface $expense, UserInterface $user, HolidayInterface $holiday, StaffInterface $staff, FilesInterface $files, SessionYearsTrackingsService $sessionYearsTrackingsService)
    {
        $this->leave = $leave;
        $this->sessionYear = $sessionYear;
        $this->leaveDetail = $leaveDetail;
        $this->cache = $cache;
        $this->leaveMaster = $leaveMaster;
        $this->expense = $expense;
        $this->user = $user;
        $this->holiday = $holiday;
        $this->staff = $staff;
        $this->files = $files;
        $this->sessionYearsTrackingsService = $sessionYearsTrackingsService;
    }

    /**
     * Detect whether the supervisor_user_id column exists on staffs.
     * Delegates to TwoStageLeaveService for Phase 3/4 unified check.
     */
    protected function isSupervisorFeatureEnabled(): bool
    {
        return TwoStageLeaveService::isEnabled();
    }

    /**
     * Determine whether the two-stage leave approval flow should be active.
     *
     * Delegates to the shared TwoStageLeaveService (Phase 3 + Phase 4 unified).
     */
    protected function isTwoStageEnabled(): bool
    {
        return TwoStageLeaveService::isEnabled();
    }

    public function index()
    {
        ResponseService::noFeatureThenRedirect('Staff Leave Management');
        ResponseService::noPermissionThenRedirect('leave-list');

        $sessionYear = $this->sessionYear->builder()->pluck('name', 'id');
        $current_session_year = app(CachingService::class)->getDefaultSessionYear();
        $leaveMaster = $this->leaveMaster->builder()->where('session_year_id', $current_session_year->id)->first();
        $months = sessionYearWiseMonth();
        $holiday = $this->holiday->builder()->whereDate('date', '>=', $current_session_year->start_date)->whereDate('date', '<=', $current_session_year->end_date)->get()->pluck('dmyFormat')->toArray();
        $holiday = implode(',', $holiday);
        return view('leave.index', compact('sessionYear', 'current_session_year', 'leaveMaster', 'months', 'holiday'));
    }

    public function create()
    {
        ResponseService::noFeatureThenRedirect('Staff Leave Management');
        ResponseService::noPermissionThenRedirect('leave-create');
    }

    public function store(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Staff Leave Management');
        ResponseService::noPermissionThenRedirect('leave-create');
        $file_upload_size_limit = $this->cache->getSystemSettings('file_upload_size_limit');

        $request->validate([
            'reason' => 'required',
            'from_date' => 'required',
            'to_date' => 'required|after_or_equal:from_date',
            'leave_master_id' => 'required',

            'type' => 'required',
            'files.*' => [
                'nullable',
                'mimes:jpg,jpeg,png,pdf,doc,docx',
                new MaxFileSize($file_upload_size_limit),
            ],
        ], [

            'leave_master_id.required' => 'Kindly contact the school admin to update settings for continued access.',
            'type.required' => 'Please select Full Day or Half Day for each leave date.',
            'files.*.mimes' => 'Only JPG, JPEG, PNG, PDF, DOC, and DOCX files are allowed.',
            'files.*.max_file_size' => "Each file must not exceed {$file_upload_size_limit} MB.",
        ]);

        try {
            DB::beginTransaction();

            $from = date('Y-m-d', strtotime($request->from_date));
            $to = date('Y-m-d', strtotime($request->to_date));

            $exists = $this->leave->builder()
                ->where('user_id', Auth::user()->id)
                ->where(function ($q) use ($from, $to) {
                    $q->where('from_date', '<=', $to)
                        ->where('to_date', '>=', $from);
                })
                ->exists();

            if ($exists) {
                ResponseService::errorResponse('You already have a leave request during this period.');
            }

            // ---- Two-stage leave: validate and resolve supervisor ----
            $resolvedSupervisorUserId = null;
            if ($this->isTwoStageEnabled()) {
                $staff = Staff::where('user_id', Auth::user()->id)->first();
                if (!$staff || !$staff->supervisor_user_id) {
                    ResponseService::errorResponse(trans('no_valid_supervisor_configured'));
                }
                $resolvedSupervisorUserId = $staff->supervisor_user_id;

                // Supervisor user must exist, belong to same school, be a valid staff
                $supervisorUser = \App\Models\User::where('id', $resolvedSupervisorUserId)
                    ->where('school_id', Auth::user()->school_id)
                    ->whereHas('staff')
                    ->first();
                if (!$supervisorUser) {
                    ResponseService::errorResponse(trans('no_valid_supervisor_configured'));
                }

                // Cannot set self as supervisor
                if ($resolvedSupervisorUserId == Auth::user()->id) {
                    ResponseService::errorResponse(trans('no_valid_supervisor_configured'));
                }

            }
            // ----------------------------------------------------------------

            $data = [
                'user_id' => Auth::user()->id,
                'reason' => $request->reason,
                'from_date' => date('Y-m-d', strtotime($request->from_date)),
                'to_date' => date('Y-m-d', strtotime($request->to_date)),
                'leave_master_id' => $request->leave_master_id
            ];

            // Write two-stage fields explicitly when enabled
            if ($resolvedSupervisorUserId !== null) {
                $data['status'] = \App\Models\Leave::STATUS_PENDING;
                $data['supervisor_status'] = \App\Models\Leave::APPROVAL_PENDING;
                $data['supervisor_user_id'] = $resolvedSupervisorUserId;
                $data['supervisor_comment'] = null;
                $data['supervisor_reviewed_at'] = null;
                $data['hr_status'] = null;
                $data['hr_user_id'] = null;
                $data['hr_comment'] = null;
                $data['hr_reviewed_at'] = null;
                $data['withdrawn_at'] = null;
            }
            $leave = $this->leave->create($data);
            $data = array();
            foreach ($request->type as $key => $type) {
                $data[] = [
                    'leave_id' => $leave->id,
                    'date' => date('Y-m-d', strtotime($key)),
                    'type' => $type[0]
                ];
            }


            if ($request->hasFile('files')) {
                $fileData = []; // Empty FileData Array
                // Create A File Model Instance
                $leaveModelAssociate = $this->files->model()->modal()->associate($leave); // Get the Association Values of File with Assignment

                foreach ($request->file('files') as $file_upload) {
                    // Create Temp File Data Array
                    $tempFileData = [
                        'modal_type' => $leaveModelAssociate->modal_type,
                        'modal_id' => $leaveModelAssociate->modal_id,
                        'file_name' => $file_upload->getClientOriginalName(),
                        'type' => 1,
                        'file_url' => $file_upload // Store file and get the file path
                    ];
                    $fileData[] = $tempFileData; // Store Temp File Data in Multi-Dimensional File Data Array
                }
                $this->files->createBulk($fileData); // Store File Data
            }


            $this->leaveDetail->createBulk($data);

            $sessionYear = $this->cache->getDefaultSessionYear();
            $semester = $this->cache->getDefaultSemesterData();
            if ($semester) {
                $this->sessionYearsTrackingsService->storeSessionYearsTracking('App\Models\Leave', $leave->id, Auth::user()->id, $sessionYear->id, Auth::user()->school_id, $semester->id);
            } else {
                $this->sessionYearsTrackingsService->storeSessionYearsTracking('App\Models\Leave', $leave->id, Auth::user()->id, $sessionYear->id, Auth::user()->school_id, null);
            }

            // Determine notification recipients based on flow
            if ($resolvedSupervisorUserId !== null) {
                // Two-stage: notify the designated supervisor (from the leave record, not current staff)
                $notifyUser = [$resolvedSupervisorUserId];
                $title = Auth::user()->full_name . ' has submitted a new leave request.';
            } else {
                // Old flow: notify all approve-leave users
                $notifyUser = $this->user->builder()->whereHas('roles.permissions', function ($q) {
                    $q->where('name', 'approve-leave');
                })->pluck('id');
                $title = Auth::user()->full_name . ' has submitted a new leave request.';
            }
            $type = "Leave";
            $body = $request->reason;

            DB::commit();

            send_notification($notifyUser, $title, $body, $type);

            ResponseService::successResponse('Data Stored Successfully');
        } catch (Throwable $e) {
            if (
                Str::contains($e->getMessage(), [
                    'does not exist',
                    'file_get_contents'
                ])
            ) {
                DB::commit();
                ResponseService::warningResponse("Data Stored successfully. But App push notification not send.");
            } else {
                DB::rollBack();
                ResponseService::logErrorResponse($e, "Leave Controller -> Store Method");
                ResponseService::errorResponse();
            }
        }
    }

    public function show()
    {
        ResponseService::noFeatureThenRedirect('Staff Leave Management');
        ResponseService::noPermissionThenRedirect('leave-list');

        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');
        $session_year_id = request('session_year_id');
        $filter_upcoming = request('filter_upcoming');
        $month_id = request('month_id');


        $sql = $this->leave->builder()->with('leave_detail', 'file')->where('user_id', Auth::user()->id)
            ->where(function ($query) use ($search) {
                $query->when($search, function ($query) use ($search) {
                    $query->where(function ($query) use ($search) {
                        $query->where('id', 'LIKE', "%$search%")->orwhere('reason', 'LIKE', "%$search%")->orwhere('from_date', 'LIKE', "%$search%")->orwhere('to_date', 'LIKE', "%$search%");
                    });
                });
            });

        if ($session_year_id) {
            $sql->whereHas('leave_master', function ($q) use ($session_year_id) {
                $q->where('session_year_id', $session_year_id);
            });
        }

        $sql = $sql->withCount([
            'leave_detail as full_leave' => function ($q) {
                $q->where('type', 'Full');
            }
        ]);

        $sql = $sql->withCount([
            'leave_detail as half_leave' => function ($q) {
                $q->whereNot('type', 'Full');
            }
        ]);

        if ($filter_upcoming) {
            if ($filter_upcoming == 'Today') {
                $sql->whereDate('from_date', '<=', Carbon::now()->format('Y-m-d'))->whereDate('to_date', '>=', Carbon::now()->format('Y-m-d'));
            }
            if ($filter_upcoming == 'Tomorrow') {
                $tomorrow_date = Carbon::now()->addDay()->format('Y-m-d');
                $sql->whereHas('leave_detail', function ($q) use ($tomorrow_date) {
                    $q->whereDate('date', '<=', $tomorrow_date)->whereDate('date', '>=', $tomorrow_date);
                });
            }
            if ($filter_upcoming == 'Upcoming') {
                $upcoming_date = Carbon::now()->addDays(1)->format('Y-m-d');
                $sql->whereHas('leave_detail', function ($q) use ($upcoming_date) {
                    $q->whereDate('date', '>', $upcoming_date);
                });
            }
        }

        if ($month_id) {
            $sql->whereHas('leave_detail', function ($q) use ($month_id) {
                $q->whereMonth('date', $month_id);
            });
        }

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
            $operate = '';
            $operate = BootstrapTableService::button('fa fa-eye', '#', ['edit-data', 'btn-gradient-info'], ['title' => trans("view"), "data-toggle" => "modal", "data-target" => "#editModal"]);
            if ($row->status == 0) {
                // $operate .= BootstrapTableService::editButton(route('leave.update', $row->id));
                $operate .= BootstrapTableService::deleteButton(route('leave.destroy', $row->id));
            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['days'] = $row->full_leave + ($row->half_leave / 2);
            $tempRow['original_from_date'] = $row->getRawOriginal('from_date');
            $tempRow['original_to_date'] = $row->getRawOriginal('to_date');
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;
        // dd($bulkData);
        return response()->json($bulkData);
    }

    public function update(Request $request, $id)
    {
        ResponseService::noFeatureThenRedirect('Staff Leave Management');
        ResponseService::noPermissionThenRedirect('leave-edit');

        $request->validate([
            'reason' => 'required',
            'from_date' => 'required',
            'to_date' => 'required|after_or_equal:from_date',
        ]);
        try {
            DB::beginTransaction();
            $data = [
                'reason' => $request->reason,
                'from_date' => date('Y-m-d', strtotime($request->from_date)),
                'to_date' => date('Y-m-d', strtotime($request->to_date)),
            ];
            $this->leave->update($id, $data);
            DB::commit();
            ResponseService::successResponse('Data Updated Successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, "Leave Controller -> Update Method");
            ResponseService::errorResponse();
        }
    }

    public function destroy($id)
    {
        ResponseService::noFeatureThenRedirect('Staff Leave Management');
        ResponseService::noAnyPermissionThenRedirect(['leave-delete', 'approve-leave']);
        try {
            DB::beginTransaction();
            // $this->leave->deleteById($id);
            $leave = $this->leave->findById($id);

            // Phase 4 guard: block deletion of new-flow records
            if (!is_null($leave->supervisor_status)) {
                DB::rollBack();
                ResponseService::errorResponse(trans('two_stage_leave_cannot_delete'));
            }
            foreach ($leave->file as $key => $file) {
                if (Storage::disk('public')->exists($file->getRawOriginal('file_url'))) {
                    Storage::disk('public')->delete($file->getRawOriginal('file_url'));
                }
            }
            $leave->file()->delete();
            $leave->delete();
            $sessionYear = $this->cache->getDefaultSessionYear();
            $semester = $this->cache->getDefaultSemesterData();
            if ($semester) {
                $this->sessionYearsTrackingsService->deleteSessionYearsTracking('App\Models\Leave', $id, Auth::user()->id, $sessionYear->id, Auth::user()->school_id, $semester->id);
            } else {
                $this->sessionYearsTrackingsService->deleteSessionYearsTracking('App\Models\Leave', $id, Auth::user()->id, $sessionYear->id, Auth::user()->school_id, null);
            }
            DB::commit();
            ResponseService::successResponse('Data Deleted Successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, "Leave Controller -> Destroy Method");
            ResponseService::errorResponse();
        }
    }

    public function leave_request()
    {
        ResponseService::noFeatureThenRedirect('Staff Leave Management');
        ResponseService::noPermissionThenRedirect('approve-leave');

        $sessionYear = $this->sessionYear->builder()->pluck('name', 'id');
        $current_session_year = app(CachingService::class)->getDefaultSessionYear();
        $leaveMaster = $this->leaveMaster->builder()->where('session_year_id', $current_session_year->id)->first();
        $holiday_days = '';
        if ($leaveMaster) {
            $holiday_days = $leaveMaster->holiday;
        }
        $users = $this->user->builder()->has('staff')->get()->pluck('full_name', 'id');
        $months = sessionYearWiseMonth();

        $holiday = $this->holiday->builder()->whereDate('date', '>=', $current_session_year->start_date)->whereDate('date', '<=', $current_session_year->end_date)->get()->pluck('default_date_format')->toArray();
        $public_holiday = implode(',', $holiday);

        return view('leave.leave_request', compact('sessionYear', 'current_session_year', 'holiday_days', 'users', 'months', 'public_holiday'))
            ->with('isTwoStageEnabled', $this->isTwoStageEnabled());
    }

    public function leave_request_show()
    {
        ResponseService::noFeatureThenRedirect('Staff Leave Management');
        ResponseService::noPermissionThenRedirect('approve-leave');

        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');
        $session_year_id = request('session_year_id');
        $filter_upcoming = request('filter_upcoming');
        $month_id = request('month_id');
        $user_id = request('user_id');

        $sql = $this->leave->builder()->with('leave_detail', 'file', 'user')
            ->where(function ($query) use ($search) {
                $query->when($search, function ($query) use ($search) {
                    $query->where(function ($query) use ($search) {
                        $query->where('id', 'LIKE', "%$search%")->orwhere('reason', 'LIKE', "%$search%")->orwhere('from_date', 'LIKE', "%$search%")->orwhere('to_date', 'LIKE', "%$search%")->orwhereHas('user', function ($q) use ($search) {
                            $q->whereRaw('concat(first_name," ",last_name) like ?', "%$search%");
                        });
                    });
                });
            });

        if (!Auth::user()->hasRole('School Admin')) {
            $sql->whereNot('user_id', Auth::user()->id);
        }

        // When two-stage is enabled, old admin approval list only shows old-flow records
        if ($this->isTwoStageEnabled()) {
            $sql->whereNull('supervisor_status');
        }

        if ($session_year_id) {
            $sql->whereHas('leave_master', function ($q) use ($session_year_id) {
                $q->where('session_year_id', $session_year_id);
            });
        }

        if ($filter_upcoming != 'All') {
            if ($filter_upcoming == 'Today') {
                $sql->whereDate('from_date', '<=', Carbon::now()->format('Y-m-d'))->whereDate('to_date', '>=', Carbon::now()->format('Y-m-d'));
            }
            if ($filter_upcoming == 'Tomorrow') {
                $tomorrow_date = Carbon::now()->addDay()->format('Y-m-d');
                $sql->whereHas('leave_detail', function ($q) use ($tomorrow_date) {
                    $q->whereDate('date', '<=', $tomorrow_date)->whereDate('date', '>=', $tomorrow_date);
                });
            }
            if ($filter_upcoming == 'Upcoming') {
                $upcoming_date = Carbon::now()->addDays(1)->format('Y-m-d');
                $sql->whereHas('leave_detail', function ($q) use ($upcoming_date) {
                    $q->whereDate('date', '>', $upcoming_date);
                });
            }
        }

        if ($month_id) {
            $sql->whereHas('leave_detail', function ($q) use ($month_id) {
                $q->whereMonth('date', $month_id);
            });
        }

        if ($user_id) {
            $sql->where('user_id', $user_id);
        }

        $sql = $sql->withCount([
            'leave_detail as full_leave' => function ($q) {
                $q->where('type', 'Full');
            }
        ]);

        $sql = $sql->withCount([
            'leave_detail as half_leave' => function ($q) {
                $q->whereNot('type', 'Full');
            }
        ]);
        $total = $sql->count();
        if ($offset >= $total && $total > 0) {
            $lastPage = floor(($total - 1) / $limit) * $limit; // calculate last page offset
            $offset = $lastPage;
        }
        $sql->orderBy('created_at', 'DESC')->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;
        foreach ($res as $row) {
            $operate = '';
            $operate .= BootstrapTableService::editButton(route('leave.status.update', $row->id));
            $operate .= BootstrapTableService::deleteButton(route('leave.destroy', $row->id));

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['days'] = $row->full_leave + ($row->half_leave / 2);
            $tempRow['from_date'] = $row->from_date;
            $tempRow['to_date'] = $row->to_date;
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function leave_status_update(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Staff Leave Management');
        ResponseService::noPermissionThenRedirect('approve-leave');
        try {
            DB::beginTransaction();

            $leave = $this->leave->findById($request->id);

            // Guard: if two-stage is enabled, block old endpoint for new-flow records
            if ($this->isTwoStageEnabled() && !is_null($leave->supervisor_status)) {
                DB::rollBack();
                ResponseService::errorResponse(trans('leave_new_flow_old_endpoint_error'));
            }

            $this->leave->update($request->id, ['status' => $request->status]);
            $user[] = $leave->user_id;

            $type = "Leave";

            DB::commit();

            if ($request->status == 1) {
                $title = 'Approved';
                $body = 'Your Leave Request Has Been Approved!';
                send_notification($user, $title, $body, $type);
            }
            if ($request->status == 2) {
                $title = 'Rejcted';
                $body = 'Your Leave Request Has Been Rejcted!';
                send_notification($user, $title, $body, $type);
            }

            ResponseService::successResponse('Data Updated Successfully');
        } catch (Throwable $e) {
            if (
                Str::contains($e->getMessage(), [
                    'does not exist',
                    'file_get_contents'
                ])
            ) {
                DB::commit();
                ResponseService::warningResponse("Data Stored successfully. But App push notification not send.");
            } else {
                DB::rollBack();
                ResponseService::logErrorResponse($e, "Leave Controller -> Leave Status Method");
                ResponseService::errorResponse();
            }
        }
    }

    // ========================================================
    //  Supervisor leave approval (Phase 3 – Two-Stage)
    // ========================================================

    /**
     * Render the supervisor leave requests page.
     */
    public function supervisorRequests()
    {
        ResponseService::noFeatureThenRedirect('Staff Leave Management');

        // Unified gate: must pass TwoStageLeaveService::isEnabled()
        if (!$this->isTwoStageEnabled()) {
            return redirect()->route('leave.request')->with('error', trans('supervisor_not_authorized'));
        }

        $sessionYear = $this->sessionYear->builder()->pluck('name', 'id');
        $current_session_year = app(CachingService::class)->getDefaultSessionYear();
        $leaveMaster = $this->leaveMaster->builder()->where('session_year_id', $current_session_year->id)->first();
        $holiday_days = '';
        if ($leaveMaster) {
            $holiday_days = $leaveMaster->holiday;
        }
        $months = sessionYearWiseMonth();

        $holiday = $this->holiday->builder()->whereDate('date', '>=', $current_session_year->start_date)->whereDate('date', '<=', $current_session_year->end_date)->get()->pluck('default_date_format')->toArray();
        $public_holiday = implode(',', $holiday);

        return view('leave.supervisor_requests', compact('sessionYear', 'current_session_year', 'holiday_days', 'months', 'public_holiday'));
    }

    /**
     * Bootstrap-table data endpoint for supervisor leave requests.
     */
    public function supervisorRequestsShow()
    {
        ResponseService::noFeatureThenSendJson('Staff Leave Management');

        // Unified gate: prevent Unknown column on un-migrated schools
        if (!$this->isTwoStageEnabled()) {
            return response()->json(['error' => true, 'message' => trans('supervisor_not_authorized')], 403);
        }

        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');
        $session_year_id = request('session_year_id');
        $filter_upcoming = request('filter_upcoming');
        $month_id = request('month_id');

        $sql = $this->leave->builder()->with('leave_detail', 'file', 'user')
            ->where('supervisor_user_id', Auth::id())
            ->where('user_id', '!=', Auth::id())
            ->where(function ($query) use ($search) {
                $query->when($search, function ($query) use ($search) {
                    $query->where(function ($query) use ($search) {
                        $query->where('id', 'LIKE', "%$search%")
                            ->orwhere('reason', 'LIKE', "%$search%")
                            ->orwhere('from_date', 'LIKE', "%$search%")
                            ->orwhere('to_date', 'LIKE', "%$search%")
                            ->orwhereHas('user', function ($q) use ($search) {
                                $q->whereRaw('concat(first_name," ",last_name) like ?', "%$search%");
                            });
                    });
                });
            });

        if ($session_year_id) {
            $sql->whereHas('leave_master', function ($q) use ($session_year_id) {
                $q->where('session_year_id', $session_year_id);
            });
        }

        if ($filter_upcoming != 'All') {
            if ($filter_upcoming == 'Today') {
                $sql->whereDate('from_date', '<=', Carbon::now()->format('Y-m-d'))->whereDate('to_date', '>=', Carbon::now()->format('Y-m-d'));
            }
            if ($filter_upcoming == 'Tomorrow') {
                $tomorrow_date = Carbon::now()->addDay()->format('Y-m-d');
                $sql->whereHas('leave_detail', function ($q) use ($tomorrow_date) {
                    $q->whereDate('date', '<=', $tomorrow_date)->whereDate('date', '>=', $tomorrow_date);
                });
            }
            if ($filter_upcoming == 'Upcoming') {
                $upcoming_date = Carbon::now()->addDays(1)->format('Y-m-d');
                $sql->whereHas('leave_detail', function ($q) use ($upcoming_date) {
                    $q->whereDate('date', '>', $upcoming_date);
                });
            }
        }

        if ($month_id) {
            $sql->whereHas('leave_detail', function ($q) use ($month_id) {
                $q->whereMonth('date', $month_id);
            });
        }

        $sql = $sql->withCount([
            'leave_detail as full_leave' => function ($q) {
                $q->where('type', 'Full');
            }
        ]);
        $sql = $sql->withCount([
            'leave_detail as half_leave' => function ($q) {
                $q->whereNot('type', 'Full');
            }
        ]);

        $total = $sql->count();
        if ($offset >= $total && $total > 0) {
            $lastPage = floor(($total - 1) / $limit) * $limit;
            $offset = $lastPage;
        }
        $sql->orderBy('created_at', 'DESC')->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;
        foreach ($res as $row) {
            $operate = '';
            // Show approve/reject only when supervisor_status is still pending
            if ($row->supervisor_status == 0 && $row->status == 0) {
                $operate .= BootstrapTableService::editButton(route('leave.supervisor.status.update', $row->id), false);
            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['days'] = $row->full_leave + ($row->half_leave / 2);
            $tempRow['from_date'] = $row->from_date;
            $tempRow['to_date'] = $row->to_date;
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    /**
     * Supervisor approve or reject a leave request.
     *
     * Security: lockForUpdate + in-lock re-check of all fields.
     * Only the designated supervisor (leave.supervisor_user_id) may act.
     */
    public function supervisorStatusUpdate(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Staff Leave Management');

        $request->validate([
            'id' => 'required|integer',
            'status' => 'required|in:approved,rejected',
            'comment' => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();

            // Pessimistic lock
            $leave = $this->leave->builder()
                ->where('id', $request->id)
                ->lockForUpdate()
                ->first();

            if (!$leave) {
                DB::rollBack();
                ResponseService::errorResponse(trans('leave_not_found'));
            }

            // ---- In-lock re-checks ----
            // 1. Feature still enabled + schema complete
            if (!$this->isTwoStageEnabled()) {
                DB::rollBack();
                // Distinguish: feature flag ON but schema incomplete vs feature flag OFF
                if (config('features.staff_leave_two_stage_enabled')) {
                    ResponseService::errorResponse(trans('two_stage_schema_incomplete'));
                }
                ResponseService::errorResponse(trans('leave_new_flow_old_endpoint_error'));
            }

            // 2. Must be a new-flow record
            if (is_null($leave->supervisor_status)) {
                DB::rollBack();
                ResponseService::errorResponse(trans('leave_new_flow_old_endpoint_error'));
            }

            // 3. Supervisor status must be pending
            if ($leave->supervisor_status != \App\Models\Leave::APPROVAL_PENDING) {
                DB::rollBack();
                ResponseService::errorResponse(trans('supervisor_already_reviewed_error'));
            }

            // 4. Overall status must be pending
            if ($leave->status != \App\Models\Leave::STATUS_PENDING) {
                DB::rollBack();
                ResponseService::errorResponse(trans('supervisor_already_reviewed_error'));
            }

            // 5. Must be the designated supervisor
            if ($leave->supervisor_user_id != Auth::id()) {
                DB::rollBack();
                ResponseService::errorResponse(trans('supervisor_not_authorized'));
            }

            // 6. Cannot approve own leave
            if ($leave->user_id == Auth::id()) {
                DB::rollBack();
                ResponseService::errorResponse(trans('supervisor_self_approve_error'));
            }

            // 7. Leave not withdrawn
            if (!is_null($leave->withdrawn_at)) {
                DB::rollBack();
                ResponseService::errorResponse(trans('supervisor_already_reviewed_error'));
            }
            // ---- end in-lock checks ----

            $comment = $request->input('comment', '');

            if ($request->status === 'approved') {
                // Supervisor final approval: status=1, supervisor_status=1
                // No HR approval stage needed
                $leave->status = \App\Models\Leave::STATUS_APPROVED;
                $leave->supervisor_status = \App\Models\Leave::APPROVAL_APPROVED;
                $leave->supervisor_comment = $comment;
                $leave->supervisor_reviewed_at = now();
                // supervisor_user_id unchanged (already set at submission)
                // hr_status / hr_user_id / hr_comment / hr_reviewed_at stay NULL
            } else {
                // rejected
                $leave->supervisor_status = \App\Models\Leave::APPROVAL_REJECTED;
                $leave->supervisor_comment = $comment;
                $leave->supervisor_reviewed_at = now();
                $leave->status = \App\Models\Leave::STATUS_REJECTED;
                // hr_status stays null
            }

            $leave->save();

            $leaveUserId = $leave->user_id;

            DB::commit();

            // Notify the employee (after commit)
            $notifyUser = [$leaveUserId];
            $type = "Leave";
            if ($request->status === 'approved') {
                $title = trans('leave_approved_by_supervisor');
                $body = trans('leave_approved_by_supervisor');

                // Notify HR viewers (read-only) that a leave has been approved by supervisor
                try {
                    $hrViewerIds = TwoStageLeaveService::getHrNotificationRecipients();
                    if (!empty($hrViewerIds)) {
                        $hrTitle = Auth::user()->full_name . ' ' . trans('leave_approved_by_supervisor');
                        $hrBody = trans('leave_approved_notification_for_hr');
                        send_notification($hrViewerIds, $hrTitle, $hrBody, $type);
                    }
                } catch (\Throwable $e) {
                    Log::error('HR viewer notification failed: ' . $e->getMessage());
                }
            } else {
                $title = trans('leave_rejected_by_supervisor');
                $body = trans('leave_rejected_by_supervisor');
            }

            try {
                send_notification($notifyUser, $title, $body, $type);
            } catch (\Throwable $e) {
                // Notification failure must not rollback the approval
                Log::error('Supervisor leave notification failed: ' . $e->getMessage());
            }

            ResponseService::successResponse(trans('Data Updated Successfully'));
        } catch (Throwable $e) {
            DB::rollBack();
            if (
                Str::contains($e->getMessage(), [
                    'does not exist',
                    'file_get_contents'
                ])
            ) {
                DB::commit();
                ResponseService::warningResponse("Data updated successfully. But App push notification not send.");
            } else {
                ResponseService::logErrorResponse($e, "Leave Controller -> Supervisor Status Update Method");
                ResponseService::errorResponse();
            }
        }
    }

    // ========================================================
    //  HR read-only view (Supervisor Final Approval)
    // ========================================================

    /**
     * Render the HR leave requests page (read-only).
     */
    public function hrRequests()
    {
        ResponseService::noFeatureThenRedirect('Staff Leave Management');
        ResponseService::noPermissionThenRedirect('hr-view-leave');

        if (!$this->isTwoStageEnabled()) {
            return redirect()->route('leave.request')->with('error', trans('hr_not_authorized'));
        }

        $sessionYear = $this->sessionYear->builder()->pluck('name', 'id');
        $current_session_year = app(CachingService::class)->getDefaultSessionYear();
        $leaveMaster = $this->leaveMaster->builder()->where('session_year_id', $current_session_year->id)->first();
        $holiday_days = '';
        if ($leaveMaster) {
            $holiday_days = $leaveMaster->holiday;
        }
        $months = sessionYearWiseMonth();

        $holiday = $this->holiday->builder()->whereDate('date', '>=', $current_session_year->start_date)->whereDate('date', '<=', $current_session_year->end_date)->get()->pluck('default_date_format')->toArray();
        $public_holiday = implode(',', $holiday);

        return view('leave.hr_requests', compact('sessionYear', 'current_session_year', 'holiday_days', 'months', 'public_holiday'));
    }

    /**
     * Bootstrap-table data endpoint for HR leave requests (read-only).
     *
     * HR can view all two-stage leave records with a supervisor_status
     * (pending = 0, approved = 1, rejected = 2).
     * Excludes old-flow records (supervisor_status IS NULL) and withdrawn records.
     * Compatible with historical hr_status=0/1/2 records.
     */
    public function hrRequestsShow()
    {
        ResponseService::noFeatureThenSendJson('Staff Leave Management');
        ResponseService::noPermissionThenSendJson('hr-view-leave');

        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');
        $session_year_id = request('session_year_id');
        $filter_upcoming = request('filter_upcoming');
        $month_id = request('month_id');

        // HR sees all two-stage records: pending (0), approved (1), rejected (2)
        // Excludes old-flow (supervisor_status IS NULL) and withdrawn records
        $sql = $this->leave->builder()->with('leave_detail', 'file', 'user')
            ->whereNotNull('supervisor_status')
            ->whereNull('withdrawn_at')
            ->where(function ($query) use ($search) {
                $query->when($search, function ($query) use ($search) {
                    $query->where(function ($query) use ($search) {
                        $query->where('id', 'LIKE', "%$search%")
                            ->orwhere('reason', 'LIKE', "%$search%")
                            ->orwhere('from_date', 'LIKE', "%$search%")
                            ->orwhere('to_date', 'LIKE', "%$search%")
                            ->orwhereHas('user', function ($q) use ($search) {
                                $q->whereRaw('concat(first_name," ",last_name) like ?', "%$search%");
                            });
                    });
                });
            });

        if ($session_year_id) {
            $sql->whereHas('leave_master', function ($q) use ($session_year_id) {
                $q->where('session_year_id', $session_year_id);
            });
        }

        if ($filter_upcoming != 'All') {
            if ($filter_upcoming == 'Today') {
                $sql->whereDate('from_date', '<=', Carbon::now()->format('Y-m-d'))->whereDate('to_date', '>=', Carbon::now()->format('Y-m-d'));
            }
            if ($filter_upcoming == 'Tomorrow') {
                $tomorrow_date = Carbon::now()->addDay()->format('Y-m-d');
                $sql->whereHas('leave_detail', function ($q) use ($tomorrow_date) {
                    $q->whereDate('date', '<=', $tomorrow_date)->whereDate('date', '>=', $tomorrow_date);
                });
            }
            if ($filter_upcoming == 'Upcoming') {
                $upcoming_date = Carbon::now()->addDays(1)->format('Y-m-d');
                $sql->whereHas('leave_detail', function ($q) use ($upcoming_date) {
                    $q->whereDate('date', '>', $upcoming_date);
                });
            }
        }

        if ($month_id) {
            $sql->whereHas('leave_detail', function ($q) use ($month_id) {
                $q->whereMonth('date', $month_id);
            });
        }

        $sql = $sql->withCount([
            'leave_detail as full_leave' => function ($q) {
                $q->where('type', 'Full');
            }
        ]);
        $sql = $sql->withCount([
            'leave_detail as half_leave' => function ($q) {
                $q->whereNot('type', 'Full');
            }
        ]);

        $total = $sql->count();
        if ($offset >= $total && $total > 0) {
            $lastPage = floor(($total - 1) / $limit) * $limit;
            $offset = $lastPage;
        }
        $sql->orderBy('created_at', 'DESC')->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;
        foreach ($res as $row) {
            // Read-only: no operate column
            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['days'] = $row->full_leave + ($row->half_leave / 2);
            $tempRow['from_date'] = $row->from_date;
            $tempRow['to_date'] = $row->to_date;
            $tempRow['operate'] = '';

            // Sanitize nullable fields to prevent "null"/"undefined" rendering in JS
            // Use toArray() processed values where possible; fall back to raw model
            $tempRow['supervisor_comment'] = $tempRow['supervisor_comment'] ?? '';
            $tempRow['supervisor_reviewed_at'] = (!empty($tempRow['supervisor_reviewed_at']) && $tempRow['supervisor_reviewed_at'] !== null)
                ? $tempRow['supervisor_reviewed_at']
                : '-';
            $tempRow['hr_comment'] = $tempRow['hr_comment'] ?? '';
            $tempRow['hr_reviewed_at'] = (!empty($tempRow['hr_reviewed_at']) && $tempRow['hr_reviewed_at'] !== null)
                ? $tempRow['hr_reviewed_at']
                : '-';

            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function filter_leave(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Staff Leave Management');
        try {
            DB::beginTransaction();
            $leave = $this->leaveDetail->builder()->with('leave:id,user_id', 'leave.user:id,first_name,last_name')
                ->whereHas('leave', function ($q) {
                    $q->where('status', 1);
                });
            if ($request->filter_leave == 'Today') {
                $leave->whereDate('date', '<=', Carbon::now()->format('Y-m-d'))->whereDate('date', '>=', Carbon::now()->format('Y-m-d'));
            }
            if ($request->filter_leave == 'Tomorrow') {
                $tomorrow_date = Carbon::now()->addDay()->format('Y-m-d');
                $leave->whereDate('date', '<=', $tomorrow_date)->whereDate('date', '>=', $tomorrow_date);
            }
            if ($request->filter_leave == 'Upcoming') {
                $upcoming_date = Carbon::now()->addDays(1)->format('Y-m-d');
                $leave->whereDate('date', '>', $upcoming_date);
            }


            $response = [
                'error' => false,
                'data' => $leave->orderBy('date', 'ASC')->get()->append(['leave_date']),
                'message' => trans('data_fetch_successfully')
            ];

            return response()->json($response);
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, "Leave Controller -> Filter Leave Method");
            ResponseService::errorResponse();
        }
    }

    public function report()
    {
        ResponseService::noFeatureThenRedirect('Staff Leave Management');
        ResponseService::noAnyPermissionThenRedirect(['leave-create', 'approve-leave']);
        $sessionYear = $this->sessionYear->builder()->pluck('name', 'id');
        $current_session_year = $this->cache->getDefaultSessionYear();

        $staffs = null;
        if (Auth::user()->can('approve-leave')) {
            $staffs = $this->user->builder()->has('staff')->select('id', 'first_name', 'last_name')->get()->pluck('full_name', 'id');
        }
        return view('leave.detail', compact('sessionYear', 'current_session_year', 'staffs'));
    }

    public function detail()
    {
        ResponseService::noFeatureThenRedirect('Staff Leave Management');
        ResponseService::noAnyPermissionThenRedirect(['leave-create', 'approve-leave']);

        $session_year_id = request('session_year_id');
        $staff_id = request('staff_id');

        if (!$staff_id) {
            $staff_id = Auth::user()->id;
        }

        $leaveMaster = $this->leaveMaster->builder()->with('session_year')->where('session_year_id', $session_year_id)->first();
        // Get months starting from session year
        $months = sessionYearWiseMonth();

        $bulkData = array();
        $bulkData['total'] = count($months);
        $rows = array();
        $no = 1;

        $expenses = $this->expense->builder()->whereHas('staff', function ($q) use ($staff_id) {
            $q->where('user_id', $staff_id);
        })->where('session_year_id', $session_year_id)->get();

        foreach ($months as $key => $month) {
            $expense = null;
            foreach ($expenses as $index => $expense_data) {
                if ($expense_data->month == $key) {
                    $expense = $expense_data;
                    break;
                }
            }
            $leaves = $this->leaveDetail->builder()->whereMonth('date', $key)
                ->whereHas('leave', function ($q) use ($session_year_id, $staff_id) {
                    $q->where('user_id', $staff_id)->where('status', 1)
                        ->whereHas('leave_master', function ($q) use ($session_year_id) {
                            $q->where('session_year_id', $session_year_id);
                        });
                });

            $allocated = 0;
            $total_used_leaves = 0;

            if ($leaveMaster) {
                $tempRow['allocated'] = $leaveMaster->leaves;
                $allocated = $leaveMaster->leaves;
            }
            if ($expense) {
                $tempRow['allocated'] = $expense->paid_leaves;
                $allocated = $expense->paid_leaves;
            }
            $tempRow['lwp'] = '-';
            $lwp = 0;
            $total_leaves = $leaves->count();
            $total_used_leaves = $total_leaves - ($leaves->whereNot('type', 'Full')->count() / 2);
            if ($allocated < $total_used_leaves) {
                $lwp = $total_used_leaves - $allocated;
                ;
                $tempRow['lwp'] = $lwp;
                $tempRow['used_cl'] = $total_used_leaves - $lwp;
            } else {
                $tempRow['used_cl'] = '-';
                if ($total_used_leaves) {
                    $tempRow['used_cl'] = $total_used_leaves;
                }
            }
            $tempRow['total'] = '-';
            if ($total_used_leaves) {
                $tempRow['total'] = $total_used_leaves;
            }

            if ($total_used_leaves >= $allocated) {
                $tempRow['remaining_cl'] = '-';
                $tempRow['remaining_total'] = '-';
            } else {
                $tempRow['remaining_cl'] = $total_used_leaves != 0 ? $allocated - $total_used_leaves : '-';
                $tempRow['remaining_total'] = $total_used_leaves != 0 ? $allocated - $total_used_leaves : '-';
            }

            $tempRow['no'] = $no++;
            $tempRow['month'] = $month;
            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }
}
