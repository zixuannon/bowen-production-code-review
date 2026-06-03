<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SystemSettingsController;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\PayrollSetting;
use App\Models\Holiday;
use App\Models\Expense;
use App\Models\Leave;
use App\Repositories\LeaveDetail\LeaveDetailInterface;
use App\Repositories\Announcement\AnnouncementInterface;
use App\Repositories\AnnouncementClass\AnnouncementClassInterface;
use App\Repositories\Attendance\AttendanceInterface;
use App\Repositories\ClassSection\ClassSectionInterface;
use App\Repositories\ExamResult\ExamResultInterface;
use App\Repositories\Expense\ExpenseInterface;
use App\Repositories\Fees\FeesInterface;
use App\Repositories\FeesPaid\FeesPaidInterface;
use App\Repositories\Files\FilesInterface;
use App\Repositories\Holiday\HolidayInterface;
use App\Repositories\Leave\LeaveInterface;
use App\Repositories\LeaveMaster\LeaveMasterInterface;
use App\Repositories\Notification\NotificationInterface;
use App\Repositories\SchoolSetting\SchoolSettingInterface;
use App\Repositories\SessionYear\SessionYearInterface;
use App\Repositories\Staff\StaffInterface;
use App\Repositories\StaffAttendance\StaffAttendanceInterface;
use App\Repositories\StaffSalary\StaffSalaryInterface;
use App\Repositories\Student\StudentInterface;
use App\Repositories\SystemSetting\SystemSettingInterface;
use App\Repositories\Timetable\TimetableInterface;
use App\Repositories\User\UserInterface;
use App\Services\CachingService;
use App\Services\FeaturesService;
use App\Services\ResponseService;
use Auth;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PDF;
use PHPUnit\Framework\Constraint\Count;
use Throwable;

class StaffApiController extends Controller
{
    //

    private ExpenseInterface $expense;
    private SchoolSettingInterface $schoolSetting;
    private CachingService $cache;
    private LeaveInterface $leave;
    private UserInterface $user;
    private StudentInterface $student;
    private TimetableInterface $timetable;
    private ClassSectionInterface $classSection;
    private AnnouncementInterface $announcement;
    private AnnouncementClassInterface $announcementClass;
    private FilesInterface $files;
    private AttendanceInterface $attendance;
    private NotificationInterface $notification;
    private FeesInterface $fees;
    private LeaveMasterInterface $leaveMaster;
    private ExamResultInterface $examResult;
    private FeaturesService $featureService;
    private SessionYearInterface $sessionYearInterface;
    private StaffInterface $staff;
    private FeesPaidInterface $feesPaid;
    private SystemSettingInterface $systemSetting;
    private SchoolSettingInterface $schoolSettings;
    private StaffSalaryInterface $staffSalary;
    private StaffAttendanceInterface $staffAttendance;
    private HolidayInterface $holiday;
    private LeaveDetailInterface $leaveDetail;

    public function __construct(ExpenseInterface $expense, SchoolSettingInterface $schoolSetting, CachingService $cache, LeaveInterface $leave, UserInterface $user, StudentInterface $student, TimetableInterface $timetable, ClassSectionInterface $classSection, AnnouncementInterface $announcement, AnnouncementClassInterface $announcementClass, FilesInterface $files, AttendanceInterface $attendance, NotificationInterface $notification, FeesInterface $fees, LeaveMasterInterface $leaveMaster, ExamResultInterface $examResult, FeaturesService $featureService, SessionYearInterface $sessionYearInterface, StaffInterface $staff, FeesPaidInterface $feesPaid, SystemSettingInterface $systemSetting, SchoolSettingInterface $schoolSettings, StaffSalaryInterface $staffSalary, StaffAttendanceInterface $staffAttendance, HolidayInterface $holiday, LeaveDetailInterface $leaveDetail)
    {
        $this->expense = $expense;
        $this->schoolSetting = $schoolSetting;
        $this->cache = $cache;
        $this->leave = $leave;
        $this->user = $user;
        $this->student = $student;
        $this->timetable = $timetable;
        $this->classSection = $classSection;
        $this->announcement = $announcement;
        $this->announcementClass = $announcementClass;
        $this->files = $files;
        $this->attendance = $attendance;
        $this->notification = $notification;
        $this->fees = $fees;
        $this->leaveMaster = $leaveMaster;
        $this->examResult = $examResult;
        $this->featureService = $featureService;
        $this->sessionYearInterface = $sessionYearInterface;
        $this->staff = $staff;
        $this->feesPaid = $feesPaid;
        $this->systemSetting = $systemSetting;
        $this->schoolSettings = $schoolSettings;
        $this->staffSalary = $staffSalary;
        $this->staffAttendance = $staffAttendance;
        $this->holiday = $holiday;
        $this->leaveDetail = $leaveDetail;
    }

    public function myPayroll(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        try {

            $sql = $this->expense->builder()->select('id', 'staff_id', 'basic_salary', 'paid_leaves', 'month', 'year', 'title', 'amount', 'date', 'session_year_id')->where('staff_id', Auth::user()->staff->id)
                ->when($request->year, function ($q) use ($request) {
                    $q->whereYear('date', $request->year);
                })->with('staff', 'staff.staffSalary.payrollSetting', );


            $sql = $this->expense->builder()->select('id', 'staff_id', 'basic_salary', 'paid_leaves', 'month', 'year', 'title', 'amount', 'date', 'session_year_id')->where('staff_id', Auth::user()->staff->id)
                ->when($request->year, function ($q) use ($request) {
                    $q->whereYear('date', $request->year);
                })->with('staff');

            if ($request->session_year_id) {
                $sql = $sql->where('session_year_id', $request->session_year_id);
            }

            $sql = $sql->get();


            ResponseService::successResponse('Data Fetched Successfully', $sql);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function myPayrollSlip(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        $validator = Validator::make($request->all(), [
            'slip_id' => 'required',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {

            $schoolSetting = $this->cache->getSchoolSettings();
            $data = explode("storage/", $schoolSetting['horizontal_logo'] ?? '');
            $schoolSetting['horizontal_logo'] = end($data);

            if ($schoolSetting['horizontal_logo'] == null) {
                $systemSettings = $this->cache->getSystemSettings();
                $data = explode("storage/", $systemSettings['horizontal_logo'] ?? '');
                $schoolSetting['horizontal_logo'] = end($data);
            }

            // Salary
            $salary = $this->expense->builder()->with('staff.user:id,first_name,last_name')->where('id', $request->slip_id)->first();
            if (!$salary) {
                ResponseService::successResponse('no_data_found');
            }
            // Get total leaves
            $leaves = $this->leave->builder()->where('status', 1)->where('user_id', $salary->staff->user_id)->withCount([
                'leave_detail as full_leave' => function ($q) use ($salary) {
                    $q->whereMonth('date', $salary->month)->whereYear('date', $salary->year)->where('type', 'Full');
                }
            ])->withCount([
                        'leave_detail as half_leave' => function ($q) use ($salary) {
                            $q->whereMonth('date', $salary->month)->whereYear('date', $salary->year)->whereNot('type', 'Full');
                        }
                    ])->get();

            $total_leaves = $leaves->sum('full_leave') + ($leaves->sum('half_leave') / 2);
            // Total days
            $days = Carbon::now()->year($salary->year)->month($salary->month)->daysInMonth;

            $allow_leaves = 0;
            if ($leaves->first()) {
                $allow_leaves = $leaves->first()->leave_master->leaves;
            }

            $pdf = PDF::loadView('payroll.slip', compact('schoolSetting', 'salary', 'total_leaves', 'days', 'allow_leaves'))->output();

            return $response = array(
                'error' => false,
                'pdf' => base64_encode($pdf),
            );



            // return $pdf->stream($salary->title.'-'.$salary->staff->user->full_name.'.pdf');
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function storePayroll(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        $validator = Validator::make($request->all(), [
            'month' => 'required|in:1,2,3,4,5,6,7,8,9,10,11,12',
            'year' => 'required',
            'payroll' => 'required',
            "allowed_leaves" => 'required'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();
            $month = $request->month;
            $year = $request->year;
            $startDate = Carbon::createFromFormat('Y-m', "$year-$month")->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();
            $sessionYearInterface = $this->sessionYearInterface->builder()->where(function ($query) use ($startDate, $endDate) {
                $query->where(function ($query) use ($startDate, $endDate) {
                    $query->where('start_date', '<=', $endDate)
                        ->where('end_date', '>=', $startDate);
                });
            })->first();

            if (!$sessionYearInterface) {
                ResponseService::errorResponse('Session year not found');
            }

            $date = Carbon::createFromDate($request->year, $request->month, 1)->endOfMonth()->format('Y-m-d');
            $title = Carbon::create()->month($request->month)->format('F') . ' - ' . $request->year;
            $data = array();
            $user_ids = array();
            foreach ($request->payroll as $key => $payroll) {
                $payroll = (object) $payroll;
                $data[] = [
                    'staff_id' => $payroll->staff_id,
                    'basic_salary' => $payroll->basic_salary,
                    'paid_leaves' => $request->allowed_leaves,
                    'month' => $request->month,
                    'year' => $request->year,
                    'title' => $title,
                    'description' => 'Salary',
                    'amount' => $payroll->amount,
                    'date' => $date,
                    'session_year_id' => $sessionYearInterface->id,
                ];
                $user_ids[] = $payroll->staff_id;
            }

            $this->expense->upsert($data, ['staff_id', 'month', 'year'], ['amount', 'session_year_id', 'basic_salary', 'date', 'title', 'description', 'paid_leaves']);
            DB::commit();
            $user = $this->staff->builder()->whereIn('id', $user_ids)->pluck('user_id');

            $title = 'Payroll Update !!!';
            $body = "Your Payroll has been Updated.";
            $type = "payroll";

            DB::commit();
            send_notification($user, $title, $body, $type);
            ResponseService::successResponse('Data Stored Successfully');
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function payrollYear()
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        try {
            $sessionYear = $this->sessionYearInterface->builder()->orderBy('start_date', 'ASC')->pluck('name');
            // dd($sessionYear);
            // $sessionYear = date('Y', strtotime($sessionYear->start_date));

            // $current_year = Carbon::now()->format('Y');
            // $sql = range($sessionYear, $current_year);

            // dd($sql);

            ResponseService::successResponse('Data Fetched Successfully', $sessionYear);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function staffPayrollList(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Expense Management');
        $validator = Validator::make($request->all(), [
            'month' => 'required|in:1,2,3,4,5,6,7,8,9,10,11,12',
            'year' => 'required'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $month = $request->month;
            $year = $request->year;
            $search = null;

            $staff_Salary = $this->staffSalary->builder()->get();
            $payrollSetting = PayrollSetting::where('name', 'Transportation Deduction')->first();
            foreach ($staff_Salary as $Staff_Salary) {
                $staffSalary = $this->staffSalary->builder()
                    ->where('staff_id', $Staff_Salary->staff_id)
                    ->where('payroll_setting_id', $payrollSetting->id ?? 0)
                    ->first();

                if ($staffSalary && $staffSalary->expiry_date) {
                    $expiryDate = Carbon::parse($staffSalary->expiry_date);
                    // Check if expired during the previous month (strict range)
                    if ($expiryDate->between(now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth())) {
                        $this->staffSalary->builder()
                            ->where('staff_id', $staffSalary->staff_id ?? null)
                            ->where('payroll_setting_id', $payrollSetting->id ?? null)
                            ->delete();
                        continue;
                    }
                }
            }

            $leaveMaster = $this->leaveMaster->builder()->whereHas('session_year', function ($q) use ($month, $year) {
                $q->where(function ($q) use ($month, $year) {
                    $q->whereMonth('start_date', '<=', $month)->whereYear('start_date', $year);
                })->orWhere(function ($q) use ($month, $year) {
                    $q->whereMonth('start_date', '>=', $month)->whereYear('end_date', '<=', $year);
                });
            })->first();


            $sql = $this->staff->builder()->with([
                'user:id,first_name,last_name,image',
                'staffSalary.payrollSetting',
                'expense:id,staff_id,basic_salary,paid_leaves,month,year,title,amount,date',
                'leave' => function ($q) use ($month, $year) {
                    $q->where('status', 1)->withCount([
                        'leave_detail as full_leave' => function ($q) use ($month, $year) {
                            $q->whereMonth('date', $month)->whereYear('date', $year)->where('type', 'Full');
                        }
                    ])->withCount([
                                'leave_detail as half_leave' => function ($q) use ($month, $year) {
                                    $q->whereMonth('date', $month)->whereYear('date', $year)->whereNot('type', 'Full');

                                }
                            ])->with([
                                'leave_detail' => function ($q) use ($month, $year) {
                                    $q->whereMonth('date', $month)->whereYear('date', $year);
                                }
                            ]);
                },
                'expense' => function ($q) use ($month, $year) {
                    $q->where('month', $month)->where('year', $year)
                        ->with('staff_payroll.payroll_setting');
                }
            ])

                ->whereHas('user', function ($q) {
                    $q->Owner();
                })->when($search, function ($query) use ($search) {
                    $query->where(function ($query) use ($search) {
                        $query->orwhereHas('user', function ($q) use ($search) {
                            $q->where('first_name', 'LIKE', "%$search%")->orwhere('last_name', 'LIKE', "%$search%");
                        });
                    });
                })->get();

            ResponseService::successResponse('Data Fetched Successfully', $sql, ['leave_master' => $leaveMaster]);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function profile()
    {
        try {
            $sql = $this->user->findById(Auth::user()->id, ['*'], ['staff']);

            ResponseService::successResponse('Data Fetched Successfully', $sql);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function counter()
    {
        try {
            $students = $this->student->builder()->whereHas('user', function ($q) {
                $q->withTrashed()->where('status', 1);
            })->withTrashed()->count();

            $teachers = $this->user->builder()->role('Teacher')->withTrashed()->where('status', 1)->count();

            $staffs = $this->user->builder()->where('status', 1)->whereHas('roles', function ($q) {
                $q->where('custom_role', 1)->whereNot('name', 'Teacher');
            })->withTrashed()->count();

            $leaves = $this->leave->builder()->where('status', 0)->count();
            $data = [
                'students' => $students,
                'teachers' => $teachers,
                'staffs' => $staffs,
                'leaves' => $leaves
            ];
            ResponseService::successResponse('Data Fetched Successfully', $data);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function teacher(Request $request)
    {
        ResponseService::noAnyPermissionThenSendJson(['teacher-list', 'staff-list']);
        try {
            if ($request->teacher_id) {
                $sql = $this->user->findById($request->teacher_id, ['*'], ['staff']);
            } else {
                $sql = $this->user->builder()->role('Teacher')->with('staff');
                if ($request->search) {
                    $sql->where(function ($q) use ($request) {
                        $q->where('first_name', 'LIKE', "%$request->search%")
                            ->orwhere('last_name', 'LIKE', "%$request->search%")
                            ->orwhere('mobile', 'LIKE', "%$request->search%")
                            ->orwhere('email', 'LIKE', "%$request->search%")
                            ->orwhere('gender', 'LIKE', "%$request->search%")
                            ->orWhereRaw('concat(first_name," ",last_name) like ?', "%$request->search%");
                    });
                }

                if ($request->class_section_id) {
                    $sql->whereHas('subjectTeachers', function ($q) use ($request) {
                        $q->where('class_section_id', $request->class_section_id);
                    });

                    $sql->orWhereHas('staff.class_teacher', function ($q) use ($request) {
                        $q->Where('class_section_id', $request->class_section_id);
                    });
                }

                if ($request->status != 1) {
                    if ($request->status == 2) {
                        $sql->onlyTrashed();
                    } else if ($request->status == 0) {
                        $sql->withTrashed();
                    } else {
                        $sql->withTrashed();
                    }
                }


                $sql = $sql->get();
            }
            ResponseService::successResponse('Data Fetched Successfully', $sql);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function teacherTimetable(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Timetable Management');
        $validator = Validator::make($request->all(), [
            'teacher_id' => 'required',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $timetable = $this->timetable->builder()
                ->whereHas('subject_teacher', function ($q) use ($request) {
                    $q->where('teacher_id', $request->teacher_id);
                })
                ->with('class_section.class.stream', 'class_section.section', 'subject')->orderBy('start_time', 'ASC')->get();

            ResponseService::successResponse('Data Fetched Successfully', $timetable);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function staff(Request $request)
    {
        ResponseService::noAnyPermissionThenSendJson(['teacher-list', 'staff-list']);
        try {
            if ($request->staff_id) {
                $sql = $this->user->builder()->whereHas('roles', function ($q) {
                    $q->where('custom_role', 1)->whereNot('name', 'Teacher');
                })->with('staff', 'roles')->where('id', $request->staff_id)->first();
            } else {
                $sql = $this->user->builder()->whereHas('roles', function ($q) {
                    $q->where('custom_role', 1)->whereNot('name', 'Teacher');
                })->with('staff', 'roles')->withTrashed();

                if ($request->status != 1) {
                    if ($request->status == 2) {
                        $sql->onlyTrashed();
                    } else if ($request->status == 0) {
                        $sql->withTrashed();
                    } else {
                        $sql->withTrashed();
                    }
                } else {
                    $sql->where('status', 1);
                }

                if ($request->search) {
                    $sql->where(function ($q) use ($request) {
                        $q->where('first_name', 'LIKE', "%$request->search%")
                            ->orwhere('last_name', 'LIKE', "%$request->search%")
                            ->orwhere('mobile', 'LIKE', "%$request->search%")
                            ->orwhere('email', 'LIKE', "%$request->search%")
                            ->orwhere('gender', 'LIKE', "%$request->search%")
                            ->orWhereRaw('concat(first_name," ",last_name) like ?', "%$request->search%");
                    });
                }

                $sql = $sql->get();
            }

            ResponseService::successResponse('Data Fetched Successfully', $sql);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function leaveRequest(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Staff Leave Management');
        ResponseService::noPermissionThenSendJson('approve-leave');
        try {
            if ($request->leave_id) {
                $sql = $this->leave->findById($request->leave_id, ['*'], ['user:id,first_name,last_name,image,email,mobile', 'leave_detail', 'file'])->orderBy('created_at', 'DESC')->get();
            } else {
                $sql = $this->leave->builder()->where('status', 0)->with('user:id,first_name,last_name,image,email,mobile', 'leave_detail', 'file')->orderBy('created_at', 'DESC')->get();
            }
            ResponseService::successResponse('Data Fetched Successfully', $sql);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function leaveApprove(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Staff Leave Management');
        ResponseService::noPermissionThenSendJson('approve-leave');
        $validator = Validator::make($request->all(), [
            'leave_id' => 'required',
            'status' => 'required|in:0,1,2',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();
            $leave = $this->leave->update($request->leave_id, ['status' => $request->status]);

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
        } catch (\Throwable $e) {
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
                ResponseService::logErrorResponse($e);
                ResponseService::errorResponse();
            }
        }
    }

    public function leaveDelete(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Staff Leave Management');
        ResponseService::noPermissionThenSendJson('approve-leave');
        $validator = Validator::make($request->all(), [
            'leave_id' => 'required',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();
            $this->leave->deleteById($request->leave_id);
            DB::commit();
            ResponseService::successResponse('Data Deleted Successfully');
        } catch (\Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function getAnnouncement(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Announcement Management');
        ResponseService::noPermissionThenSendJson('announcement-list');
        $validator = Validator::make($request->all(), [
            'class_section_id' => 'required',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();
            $sessionYear = $this->cache->getDefaultSessionYear();
            $sql = $this->announcement->builder()->whereHas('announcement_class', function ($q) use ($request) {
                $q->where('class_section_id', $request->class_section_id);
            })->with('announcement_class')->where('session_year_id', $sessionYear->id)->with('file')->paginate(10);
            DB::commit();
            ResponseService::successResponse('Data Fetched Successfully', $sql);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function sendAnnouncement(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Announcement Management');
        ResponseService::noPermissionThenSendJson('announcement-create');
        $validator = Validator::make($request->all(), [
            'class_section_id' => 'required',
            'title' => 'required',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();
            $sessionYear = $this->cache->getDefaultSessionYear();
            $announcementData = array(
                'title' => $request->title,
                'description' => $request->description,
                'session_year_id' => $sessionYear->id,
            );

            $announcement = $this->announcement->create($announcementData); // Store Data
            $announcementClassData = array();

            $notifyUser = $this->student->builder()->select('user_id')->whereIn('class_section_id', $request->class_section_id)->get()->pluck('user_id'); // Get the Student's User ID of Specified Class for Notification

            // Set class sections
            foreach ($request->class_section_id as $class_section) {
                $announcementClassData[] = [
                    'announcement_id' => $announcement->id,
                    'class_section_id' => $class_section
                ];
            }
            $title = trans('New announcement'); // Title for Notification
            $this->announcementClass->upsert($announcementClassData, ['announcement_id', 'class_section_id', 'school_id'], ['announcement_id', 'class_section_id', 'school_id', 'class_subject_id']);

            // If File Exists
            if ($request->hasFile('file')) {
                $fileData = array(); // Empty FileData Array
                $fileInstance = $this->files->model(); // Create A File Model Instance
                $announcementModelAssociate = $fileInstance->modal()->associate($announcement); // Get the Association Values of File with Announcement
                foreach ($request->file as $file_upload) {
                    // Create Temp File Data Array
                    $tempFileData = array(
                        'modal_type' => $announcementModelAssociate->modal_type,
                        'modal_id' => $announcementModelAssociate->modal_id,
                        'file_name' => $file_upload->getClientOriginalName(),
                        'type' => 1,
                        'file_url' => $file_upload
                    );
                    $fileData[] = $tempFileData; // Store Temp File Data in Multi-Dimensional File Data Array
                }
                $this->files->createBulk($fileData); // Store File Data
            }
            DB::commit();
            if ($notifyUser !== null && !empty($title)) {
                $type = trans('Class Section'); // Get The Type for Notification
                $body = $request->title; // Get The Body for Notification
                send_notification($notifyUser, $title, $body, $type); // Send Notification
            }


            ResponseService::successResponse('Data Stored Successfully');
        } catch (\Throwable $e) {
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
                ResponseService::logErrorResponse($e);
                ResponseService::errorResponse();
            }
        }
    }

    public function updateAnnouncement(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Announcement Management');
        ResponseService::noPermissionThenSendJson('announcement-edit');
        $validator = Validator::make($request->all(), [
            'class_section_id' => 'required',
            'title' => 'required',
            'announcement_id' => 'required'
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();
            $sessionYear = $this->cache->getDefaultSessionYear();
            $announcementData = array(
                'title' => $request->title,
                'description' => $request->description,
                'session_year_id' => $sessionYear->id,
            );

            $announcement = $this->announcement->update($request->announcement_id, $announcementData); // Store Data
            $announcementClassData = array();

            $oldClassSection = $this->announcement->findById($request->announcement_id)->announcement_class->pluck('class_section_id')->toArray();

            // When only Class Section is passed
            $notifyUser = $this->student->builder()->select('user_id')->whereIn('class_section_id', $request->class_section_id)->get()->pluck('user_id'); // Get the Student's User ID of Specified Class for Notification


            // Set class sections
            foreach ($request->class_section_id as $class_section) {
                $announcementClassData[] = [
                    'announcement_id' => $announcement->id,
                    'class_section_id' => $class_section
                ];
                // Check class section
                $key = array_search($class_section, $oldClassSection);
                if ($key !== false) {
                    unset($oldClassSection[$key]);
                }
            }
            $title = trans('Updated announcement'); // Title for Notification

            $this->announcementClass->upsert($announcementClassData, ['announcement_id', 'class_section_id', 'school_id'], ['announcement_id', 'class_section_id', 'school_id', 'class_subject_id']);

            // Delete announcement class sections
            $this->announcementClass->builder()->where('announcement_id', $request->announcement_id)->whereIn('class_section_id', $oldClassSection)->delete();


            // If File Exists
            if ($request->hasFile('file')) {
                $fileData = array(); // Empty FileData Array
                $fileInstance = $this->files->model(); // Create A File Model Instance
                $announcementModelAssociate = $fileInstance->modal()->associate($announcement); // Get the Association Values of File with Announcement
                foreach ($request->file as $file_upload) {
                    // Create Temp File Data Array
                    $tempFileData = array(
                        'modal_type' => $announcementModelAssociate->modal_type,
                        'modal_id' => $announcementModelAssociate->modal_id,
                        'file_name' => $file_upload->getClientOriginalName(),
                        'type' => 1,
                        'file_url' => $file_upload
                    );
                    $fileData[] = $tempFileData; // Store Temp File Data in Multi-Dimensional File Data Array
                }
                $this->files->createBulk($fileData); // Store File Data
            }

            if ($notifyUser !== null && !empty($title)) {
                $type = $request->aissgn_to; // Get The Type for Notification
                $body = $request->title; // Get The Body for Notification
                // send_notification($notifyUser, $title, $body, $type); // Send Notification
            }

            DB::commit();
            ResponseService::successResponse('Data Updated Successfully');
        } catch (\Throwable $e) {
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
                ResponseService::logErrorResponse($e);
                ResponseService::errorResponse();
            }
        }
    }

    public function deleteAnnouncement(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Announcement Management');
        ResponseService::noPermissionThenSendJson('announcement-delete');
        $validator = Validator::make($request->all(), [
            'announcement_id' => 'required',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();
            $this->announcement->deleteById($request->announcement_id);
            DB::commit();
            ResponseService::successResponse('Data Deleted Successfully');
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function studentAttendance(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Attendance Management');
        ResponseService::noPermissionThenSendJson('attendance-list');
        $validator = Validator::make($request->all(), [
            'class_section_id' => 'required',
            'date' => 'required'
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $sql = $this->attendance->builder()->where('class_section_id', $request->class_section_id)->whereDate('date', $request->date)->with('user:id,first_name,last_name,image', 'user.student:id,user_id,roll_number');

            if (isset($request->status)) {
                $sql = $sql->where('type', $request->status);
            }
            $sql = $sql->paginate(10);

            ResponseService::successResponse('Data Fetched Successfully', $sql);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function getRoles()
    {
        ResponseService::noFeatureThenSendJson('Announcement Management');
        ResponseService::noPermissionThenSendJson('announcement-list');

        try {
            $reserveRole = [
                'Super Admin',
                'School Admin',
                'Teacher',
                'Guardian',
                'Student'
            ];
            $sql = Role::orderBy('id', 'DESC')->whereNotIn('name', $reserveRole)->get();

            ResponseService::successResponse('Data Fetched Successfully', $sql);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function getUsers(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Announcement Management');
        ResponseService::noPermissionThenSendJson('announcement-list');

        try {
            $search = $request->search;

            $roles = Role::whereNot('name', 'Guardian')->pluck('name');
            $user_ids = $this->user->guardian()->with('roles')->select('id', 'first_name', 'last_name', 'school_id')
                ->whereHas('child.user', function ($q) {
                    $q->owner();
                })->orWhere(function ($q) use ($roles) {
                    $q->where('school_id', Auth::user()->school_id)
                        ->whereHas('roles', function ($q) use ($roles) {
                            $q->whereIn('name', $roles);
                        });
                })
                ->pluck('id');

            $sql = User::whereIn('id', $user_ids)->with('roles')->select('id', 'first_name', 'last_name', 'school_id')
                ->when($search, function ($q) use ($search) {
                    $q->where('first_name', 'LIKE', "%$search%")
                        ->orwhere('last_name', 'LIKE', "%$search%")
                        ->orWhereRaw("concat(first_name,' ',last_name) LIKE ?", ["%{$search}%"]);
                })
                ->paginate(10);

            ResponseService::successResponse('Data Fetched Successfully', $sql);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function storeNotification(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Announcement Management');
        ResponseService::noPermissionThenSendJson('announcement-create');
        $validator = Validator::make($request->all(), [
            'title' => 'required',
            'message' => 'required',
            'type' => 'required|in:All users,Specific users,Over Due Fees,Roles',
            'user_id.*' => 'required_if:type,Specific users',
            'roles.*' => 'required_if:type,Roles',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();
            $sessionYear = $this->cache->getDefaultSessionYear();
            $data = [
                'title' => $request->title,
                'message' => $request->message,
                'send_to' => $request->type,
                'is_custom' => 1,
                'image' => $request->hasFile('file') ? $request->file('file')->store('notification', 'public') : null,
                'session_year_id' => $sessionYear->id
            ];
            $notification = $this->notification->create($data);

            $notifyUser = [];

            // if ($request->send_to == 'All users') {
            //     $notifyUser = $this->user->builder()->role(['Student','Guardian'])->pluck('id');
            // } else if($request->send_to == 'Students') {
            //     $notifyUser = $this->user->builder()->role('Student')->pluck('id');
            // } else if($request->send_to == 'Guardian') {
            //     $notifyUser = $this->user->builder()->role('Guardian')->pluck('id');
            // } else if($request->send_to == 'Over Due Fees') {
            //     // Over due fees
            //     $today = Carbon::now()->format('Y-m-d');
            //     $student_ids = array();
            //     $guardian_ids = array();
            //     $fees = $this->fees->builder()->whereDate('due_date','<',$today)->get();

            //     foreach ($fees as $key => $fee) {
            //         $sql = $this->user->builder()->role('Student')->select('id', 'first_name', 'last_name')->with([
            //             'fees_paid'     => function ($q) use ($fee) {
            //                 $q->where('fees_id', $fee->id);
            //             },
            //             'student:id,guardian_id,user_id','student.guardian:id'])->whereHas('student.class_section', function ($q) use ($fee) {
            //             $q->where('class_id', $fee->class_id);
            //         })->whereDoesntHave('fees_paid', function ($q) use ($fee) {
            //             $q->where('fees_id', $fee->id);
            //         })->orWhereHas('fees_paid', function ($q) use ($fee) {
            //             $q->where(['fees_id' => $fee->id, 'is_fully_paid' => 0]);
            //         });
            //         $student_ids[] = $sql->pluck('id')->toArray();
            //         $guardian_ids[] = $sql->get()->pluck('student.guardian_id')->toArray();
            //     }

            //     $student_ids = array_merge(...$student_ids);
            //     $guardian_ids = array_merge(...$guardian_ids);
            //     $notifyUser = array_merge($student_ids, $guardian_ids);
            // } else {
            //     $notifyUser = $request->user_id;
            // }

            // ====================================================

            if ($request->type == 'All users') {
                // All
                $roles = Role::whereNot('name', 'Guardian')->pluck('name');
                $users = $this->user->guardian()->with('roles')->whereHas('child.user', function ($q) {
                    $q->owner();
                })->orWhere(function ($q) use ($roles) {
                    $q->where('school_id', Auth::user()->school_id)
                        ->whereHas('roles', function ($q) use ($roles) {
                            $q->whereIn('name', $roles);
                        });
                })->get();

                $notifyUser = $users->pluck('id')->toArray();
            } else if ($request->type == 'Specific users') {
                // Specific
                $notifyUser = $request->user_id;
            } else if ($request->type == 'Over Due Fees') {
                // Over due fees
                $today = Carbon::now()->format('Y-m-d');
                $student_ids = array();
                $guardian_ids = array();
                $fees = $this->fees->builder()->whereDate('due_date', '<', $today)->get();

                foreach ($fees as $key => $fee) {
                    $sql = $this->user->builder()->role('Student')->select('id', 'first_name', 'last_name')->with([
                        'fees_paid' => function ($q) use ($fee) {
                            $q->where('fees_id', $fee->id);
                        },
                        'student:id,guardian_id,user_id',
                        'student.guardian:id'
                    ])->whereHas('student.class_section', function ($q) use ($fee) {
                        $q->where('class_id', $fee->class_id);
                    })->whereDoesntHave('fees_paid', function ($q) use ($fee) {
                        $q->where('fees_id', $fee->id);
                    })->orWhereHas('fees_paid', function ($q) use ($fee) {
                        $q->where(['fees_id' => $fee->id, 'is_fully_paid' => 0]);
                    });
                    $student_ids[] = $sql->pluck('id')->toArray();
                    $guardian_ids[] = $sql->get()->pluck('student.guardian_id')->toArray();
                }

                $student_ids = array_merge(...$student_ids);
                $guardian_ids = array_merge(...$guardian_ids);
                $notifyUser = array_merge($student_ids, $guardian_ids);
            } else if ($request->type == 'Roles') {
                $guardian_ids = [];
                if (in_array('Guardian', $request->roles)) {
                    $guardian_ids = $this->user->guardian()->with('roles')->whereHas('child.user', function ($q) {
                        $q->owner();
                    })->pluck('id')->toArray();
                    $roles = array_diff($request->roles, ["Guardian"]);
                    $notifyUser = $this->user->builder()->role($roles)->pluck('id')->toArray();
                } else {
                    $notifyUser = $this->user->builder()->role($request->roles)->pluck('id')->toArray();
                }
                $notifyUser = array_merge($guardian_ids, $notifyUser);
            }


            // ====================================================

            // Store user notifications for user-wise storage
            if (!empty($notifyUser)) {
                $userNotifications = [];
                foreach ($notifyUser as $userId) {
                    $userNotifications[] = [
                        'notification_id' => $notification->id,
                        'user_id' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                UserNotification::insert($userNotifications);
            }

            $customData = [];
            if ($notification->image) {
                $customData = [
                    'image' => $notification->image
                ];
            }
            $title = $request->title; // Title for Notification
            $body = $request->message;
            $type = 'Notification';

            DB::commit();
            send_notification($notifyUser, $title, $body, $type, $customData); // Send Notification

            ResponseService::successResponse('Notification Send Successfully');
        } catch (\Throwable $e) {
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
                ResponseService::logErrorResponse($e);
                ResponseService::errorResponse();
            }
        }
    }

    public function getNotification()
    {
        ResponseService::noFeatureThenSendJson('Announcement Management');
        ResponseService::noPermissionThenSendJson('announcement-list');
        try {
            $sessionYear = $this->cache->getDefaultSessionYear();
            $sql = $this->notification->builder()->where('session_year_id', $sessionYear->id)->orderBy('id', 'DESC')->paginate(10);

            ResponseService::successResponse('Data Fetched Successfully', $sql);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function deleteNotification(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Announcement Management');
        ResponseService::noPermissionThenSendJson('announcement-delete');
        $validator = Validator::make($request->all(), [
            'notification_id' => 'required',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $this->notification->deleteById($request->notification_id);
            ResponseService::successResponse('Data Deleted Successfully');
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function getFees()
    {
        ResponseService::noFeatureThenSendJson('Fees Management');
        ResponseService::noPermissionThenSendJson('fees-list');
        try {
            $sql = $this->fees->builder()->select(['id', 'name'])->get();
            ResponseService::successResponse('Data Fetched Successfully', $sql);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function getFeesPaidList(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Fees Management');
        ResponseService::noPermissionThenSendJson('fees-paid');
        $validator = Validator::make($request->all(), [
            'session_year_id' => 'required',
            'fees_id' => 'required'
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {

            // $fees = $this->fees->findById($request->fees_id, ['*'], ['fees_class_type.fees_type:id,name', 'installments:id,name,due_date,due_charges,fees_id','fees_paid' => function($q) {
            //     $q->withSum('compulsory_fee','amount')
            //     ->withSum('optional_fee','amount');
            // }]);

            $fees = $this->fees->builder()->where('id', $request->fees_id)->where('session_year_id', $request->session_year_id)->with([
                'fees_class_type.fees_type:id,name',
                'installments:id,name,due_date,due_charges,fees_id',
                'fees_paid' => function ($q) {
                    $q->withSum('compulsory_fee', 'amount')
                        ->withSum('optional_fee', 'amount');
                }
            ])->first();

            if (!$fees) {
                ResponseService::successResponse('No Data Found');
            }

            $sql = $this->user->builder()->role('Student')->select('id', 'first_name', 'last_name')->with([
                'student' => function ($query) {
                    $query->select('id', 'class_section_id', 'user_id')->with([
                        'class_section' => function ($query) {
                            $query->select('id', 'class_id', 'section_id', 'medium_id')->with('class:id,name', 'section:id,name', 'medium:id,name');
                        }
                    ]);
                },
                'optional_fees' => function ($query) {
                    $query->with('fees_class_type');
                },
                'fees_paid' => function ($q) use ($fees) {
                    $q->where('fees_id', $fees->id);
                },
                'compulsory_fees'
            ])->whereHas('student.class_section', function ($q) use ($fees) {
                $q->where('class_id', $fees->class_id);
            });


            if ($request->status == 0) {
                $sql->whereDoesntHave('fees_paid', function ($q) use ($fees) {
                    $q->where('fees_id', $fees->id);
                })->orWhereHas('fees_paid', function ($q) use ($fees) {
                    $q->where(['fees_id' => $fees->id, 'is_fully_paid' => 0]);
                });
            } else {
                $sql->whereHas('fees_paid', function ($q) use ($fees) {
                    $q->where(['fees_id' => $fees->id, 'is_fully_paid' => 1]);
                });
            }



            $sql = $sql->paginate(10);

            ResponseService::successResponse('Data Fetched Successfully', $sql, [
                'compolsory_fees' => $fees->total_compulsory_fees,
                'optional_fees' => $fees->total_optional_fees,
            ]);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function getOfflineExamResult(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Exam Management');
        ResponseService::noPermissionThenSendJson('exam-result');
        $validator = Validator::make($request->all(), [
            'session_year_id' => 'required',
            'exam_id' => 'required',
            'class_section_id' => 'required',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {

            $sql = $this->examResult->builder()->with([
                'user:id,first_name,last_name,school_id',
                'user.exam_marks' => function ($q) use ($request) {
                    $q->whereHas('timetable', function ($q) use ($request) {
                        $q->where('exam_id', $request->exam_id);
                    })->with('timetable', 'subject');
                }
            ])
                ->where('exam_id', $request->exam_id)
                ->where('session_year_id', $request->session_year_id)
                ->where('class_section_id', $request->class_section_id)->with('exam:id,name,description,start_date,end_date');

            if ($request->student_id) {
                $sql = $sql->where('student_id', $request->student_id);
            }


            $sql = $sql->paginate(10);


            ResponseService::successResponse('Data Fetched Successfully', $sql);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function getFeaturesPermissions()
    {
        try {
            if (Auth::user()) {
                $features = $this->featureService->getFeatures();
                if (count($features) == 0) {
                    $features = null;
                }
                $permissions = Auth::user()->getAllPermissions()->pluck('name');
                $data = [
                    'features' => $features,
                    'permissions' => $permissions
                ];

                ResponseService::successResponse('Data Fetched Successfully', $data);
            } else {
                ResponseService::errorResponse(trans('your_account_has_been_deactivated_please_contact_admin'), null, config('constants.RESPONSE_CODE.INACTIVATED_USER'));
            }


        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function getClassTimetable(Request $request)
    {

        ResponseService::noFeatureThenSendJson('Timetable Management');
        ResponseService::noPermissionThenSendJson('timetable-list');
        $validator = Validator::make($request->all(), [
            'class_section_id' => 'required',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $sql = $this->timetable->builder()->where('class_section_id', $request->class_section_id)
                ->with('class_section.class.stream', 'class_section.section', 'class_section.medium', 'subject', 'subject_teacher.teacher')
                ->orderBy('start_time')->get();
            ResponseService::successResponse('Data Fetched Successfully', $sql);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function feesReceipt(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Fees Management');
        ResponseService::noPermissionThenSendJson('fees-paid');
        $validator = Validator::make($request->all(), [
            'student_id' => 'required',
            'fees_id' => 'required',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {


            $feesPaid = $this->feesPaid->builder()->where('student_id', $request->student_id)->where('fees_id', $request->fees_id)->with([
                'fees.fees_class_type.fees_type',
                'compulsory_fee.installment_fee:id,name',
                'optional_fee' => function ($q) {
                    $q->with([
                        'fees_class_type' => function ($q) {
                            $q->select('id', 'fees_type_id')->with('fees_type:id,name');
                        }
                    ]);
                }
            ])->firstOrFail();
            $student = $this->student->builder()->with('user:id,first_name,last_name')->whereHas('user', function ($q) use ($feesPaid) {
                $q->where('id', $feesPaid->student_id);
            })->firstOrFail();

            $systemVerticalLogo = $this->systemSetting->builder()->where('name', 'vertical_logo')->first();
            $schoolVerticalLogo = $this->schoolSettings->builder()->where('name', 'vertical_logo')->first();
            $school = $this->cache->getSchoolSettings();

            //            return view('Income.fees_receipt', compact('systemLogo', 'school', 'feesPaid', 'student'));
            $pdf = Pdf::loadView('Income.fees_receipt', compact('systemVerticalLogo', 'school', 'feesPaid', 'student', 'schoolVerticalLogo'))->output();

            return $response = array(
                'error' => false,
                'pdf' => base64_encode($pdf),
            );
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function allowancesDeductions()
    {

        try {
            $sql = Auth::user()->load('staff.staffSalary.payrollSetting');

            ResponseService::successResponse('Data Fetched Successfully', $sql);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function getAttendance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'nullable|numeric',
            'year' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $staff = $request->user()->staff;
            $sessionYear = $this->cache->getDefaultSessionYear();
            $start = Carbon::create($request->year, $request->month, 1)->startOfMonth();
            $end = Carbon::create($request->year, $request->month, 1)->endOfMonth();

            $attendance = $this->staffAttendance->builder()->where(['staff_id' => $staff->user_id, 'session_year_id' => $sessionYear->id]);
            $holidays = $this->holiday->builder();
            $leaves = $this->leave->builder()
                // 1️⃣ Exclude leaves already linked via leave_id
                ->whereDoesntHave('attendance')

                // 2️⃣ Exclude leaves whose leave_detail.date exists in attendance
                ->whereDoesntHave('leave_detail', function ($q) use ($staff, $sessionYear, $start, $end) {
                    $q->whereBetween('date', [
                        $start->format('Y-m-d'),
                        $end->format('Y-m-d')
                    ])
                        ->whereIn('date', function ($sub) use ($staff, $sessionYear) {
                            $sub->select('date')
                                ->from('staff_attendances')
                                ->where('staff_id', $staff->user_id)
                                ->where('session_year_id', $sessionYear->id);
                        });
                })

                ->with([
                    'leave_detail' => function ($q) use ($start, $end) {
                        $q->whereBetween('date', [
                            $start->format('Y-m-d'),
                            $end->format('Y-m-d')
                        ]);
                    }
                ])

                ->where('user_id', $staff->user_id)
                ->where('from_date', '<=', $end->format('Y-m-d'))
                ->where('to_date', '>=', $start->format('Y-m-d'))
                ->where('status', 1)
                ->get();
            $session_year_data = $this->sessionYearInterface->findById($sessionYear->id);

            if (isset($request->month)) {
                $attendance = $attendance->whereMonth('date', $request->month);
                $holidays = $holidays->whereMonth('date', $request->month);
            }

            if (isset($request->year)) {
                $attendance = $attendance->whereYear('date', $request->year);
                $holidays = $holidays->whereYear('date', $request->year);
            }

            $attendance = $attendance->get();
            $holidays = $holidays->get();
            $weeklyOffDays = $this->leaveMaster->builder()
                ->where('session_year_id', $this->cache->getDefaultSessionYear()->id)
                ->pluck('holiday')
                ->filter()
                ->flatMap(function ($day) {
                    return collect(explode(',', $day))
                        ->map(fn($d) => ucfirst(strtolower(trim($d))));
                })
                ->unique()
                ->values();

            // 4️⃣ Generate all weekly-off dates in the selected month
            $weeklyOffDates = collect();
            $current = $start->copy();
            $end = $end->copy();

            while ($current->lte($end)) {
                if ($weeklyOffDays->contains($current->format('l'))) {
                    $weeklyOffDates->push($current->format('d-m-Y'));
                }
                $current->addDay();
            }

            $totalPresent = 0;
            $totalAbsent = 0;

            foreach ($attendance as $record) {

                switch ((int) $record->type) {

                    case 1: // Full present
                        $totalPresent += 1;
                        break;

                    case 5: // Half day present
                    case 4: // Half day present
                        $totalPresent += 0.5;
                        break;

                    case 0: // Absent
                        $totalAbsent += 1;
                        break;
                }
            }

            $data = [
                'attendance' => $attendance,
                'holidays' => $holidays,
                'weekly_off_dates' => $weeklyOffDates,
                'leaves' => $leaves,
                'session_year' => $session_year_data,
                'total_present' => $totalPresent,
                'total_absent' => $totalAbsent,
            ];

            ResponseService::successResponse("Attendance Details Fetched Successfully", $data);
        } catch (\Throwable $e) {
            ResponseService::logErrorResponse($e, "Staff Api Controller -> getAttendance Method");
            ResponseService::errorResponse();
        }
    }

    public function getStaffAttendanceData(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Staff Attendance Management');
        ResponseService::noAnyPermissionThenRedirect(['staff-attendance-list']);

        $validator = Validator::make($request->all(), [
            'mode' => 'nullable|in:daily,monthly',
            'date' => 'nullable|date',
            'month' => 'nullable|numeric',
            'year' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        $mode = $request->get('mode', 'daily'); // daily is default

        if ($mode === 'monthly') {
            return $this->monthlyStaffAttendanceData($request);
        }

        return $this->dailystaffAttendanceData($request);
    }

    private function dailystaffAttendanceData(Request $request)
    {
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'ASC');
        $search = $request->input('search');
        $date = date('Y-m-d', strtotime($request->date));

        $sessionYear = $this->cache->getDefaultSessionYear();
        $leaveMaster = $this->leaveMaster->builder()
            ->where('session_year_id', $sessionYear->id)
            ->first();

        $holiday_days = $leaveMaster->holiday ?? null;

        $dayName = Carbon::parse($date)->format('l');
        $is_holiday_today = false;
        if ($holiday_days != null) {
            $holiday_days = explode(',', $holiday_days);
            if (in_array($dayName, $holiday_days)) {
                $is_holiday_today = true;
            }
        }

        $holidays = Holiday::where('date', $date)->first();
        if ($holidays != null) {
            $holidays = true;
        }

        /* ✅ 2. Load attendance for this date */
        $attendanceRecords = $this->staffAttendance->builder()
            ->with('user.staff')
            ->where('date', $date)
            ->whereHas('user', fn($q) => $q->where('status', 1)->whereNull('deleted_at'))
            ->orderBy($sort, $order)
            ->get()
            ->keyBy('staff_id');

        /* ✅ 3. Load staff + leave info */
        $staffQuery = $this->staff->builder()->with([
            'user',
            'leave' => fn($q) => $q->with([
                'leave_detail' => fn($d) => $d->where('date', $date)
            ])
                ->where('from_date', '<=', $date)
                ->where('to_date', '>=', $date)
                ->where('status', 1)
        ])->whereHas('user', function ($q) {
            $q->where('status', 1)->whereNull('deleted_at');
        });

        if ($request->class_section_id) {
            $staffQuery->whereHas('user.subjectTeachers', function ($q) use ($request) {
                $q->where('class_section_id', $request->class_section_id);
            });

            $staffQuery->orWhereHas('class_teacher', function ($q) use ($request) {
                $q->where('class_section_id', $request->class_section_id);
            });
        }

        if ($search) {
            $staffQuery->where('user_id', 'like', "%{$search}%")
                ->orWhereHas('user', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
                });
        }

        $staffMembers = $staffQuery->get();

        $rows = [];
        $no = 1;

        foreach ($staffMembers as $staff) {

            $attendance = $attendanceRecords->get($staff->user_id);
            $user = $staff->user;
            $userId = $user->id ?? null;
            $staffId = $staff->id ?? null;
            $attendanceMonth = Carbon::parse($date)->format('m');
            $attendanceYear = Carbon::parse($date)->format('Y');
            $payrollExists = Expense::where('staff_id', $staffId)
                ->where('month', $attendanceMonth)
                ->where('year', $attendanceYear)
                ->exists();

            /* ============================================================
                ✅ CLASSIFY LEAVE DETAILS: ADMIN vs ATTENDANCE-CREATED
            ============================================================ */
            $leaves = $staff->leave;
            $adminHalves = [];
            $attnHalves = [];

            if ($leaves->isNotEmpty()) {
                foreach ($leaves as $leave) {
                    foreach ($leave->leave_detail as $detail) {

                        // Attendance-created leave
                        if ($attendance && $attendance->leave_detail_id == $detail->id) {
                            $attnHalves[] = $detail->type;
                        }
                        // Admin-created leave
                        else {
                            $adminHalves[] = $detail->type;
                        }
                    }
                }
            }

            /* ================================
               ✅ Leave type priority
            ================================ */
            $adminHasFull = in_array('Full', $adminHalves);
            $adminFirst = in_array('First Half', $adminHalves);
            $adminSecond = in_array('Second Half', $adminHalves);

            $attnHasFull = in_array('Full', $attnHalves);
            $attnFirst = in_array('First Half', $attnHalves);
            $attnSecond = in_array('Second Half', $attnHalves);

            $leaveType = $adminHasFull ? 'Full'
                : ($attnHasFull ? 'Full'
                    : ($adminFirst ? 'First Half'
                        : ($adminSecond ? 'Second Half'
                            : ($attnFirst ? 'First Half'
                                : ($attnSecond ? 'Second Half' : null)))));


            $isAdminLeave = !empty($adminHalves);
            $isAttendanceLeave = !empty($attnHalves);

            /* ============================================================
                ✅ FINAL STRUCTURE (MOST IMPORTANT PART)
               ============================================================ */

            if ($attendance) {
                if ($is_holiday_today) {
                    // If holiday today, override status to holiday
                    $attendance->type = 3;
                }
                $rows[] = [

                    "record_type" => "already_marked",

                    /* ✅ Record identity */
                    "record_info" => [
                        "attendance_id" => $attendance->id,
                        "row_number" => $no++,
                        "staff_id" => $attendance->staff_id,
                        "date" => $date,
                        "day_name" => Carbon::parse($date)->format('l'),
                    ],

                    /* ✅ Staff information */
                    "staff" => [
                        "user_details" => $user,
                        "staff_details" => [
                            "staff_table_id" => $attendance->user->staff->id ?? '',
                            "user_id" => $attendance->user->staff->user_id ?? '',
                        ]
                    ],

                    /* ✅ Attendance details */
                    "attendance" => [
                        "status_code" => $attendance->type, // 1,0,4,5
                        "status_label" => "Update",
                        "formatted_date" => Carbon::parse($date)->format('l, F j, Y'),
                    ],

                    /* ✅ Leave information (Admin + Attendance) */
                    "leave" => [
                        "detected_leave_type" => $leaveType, // Full / First Half / etc
                        "admin_leave" => [
                            "is_admin_leave" => $isAdminLeave,
                            "types_detected" => $adminHalves
                        ],
                        "attendance_created_leave" => [
                            "is_attendance_leave" => $isAttendanceLeave,
                            "types_detected" => $attnHalves,
                            "reason" => $attendance->reason
                        ]
                    ],

                    /* ✅ Extra info */
                    "holiday_config" => $holiday_days,
                    'holiday' => $holidays ?? false,
                    'is_holiday_today' => $is_holiday_today,
                    'payroll_exists' => $payrollExists ?? false,
                ];

                continue;
            }

            /* ============================================================
                ✅ NOT MARKED RECORD
               ============================================================ */
            $rows[] = [

                "record_type" => $is_holiday_today ? "already_marked" : "not_marked",

                "record_info" => [
                    "attendance_id" => null,
                    "row_number" => $no++,
                    "staff_id" => $staff->user_id,
                    "date" => $date,
                    "day_name" => Carbon::parse($date)->format('l'),
                    "status_label" => $leaveType === 'Full' ? "Full Day Leave" : "not marked",
                ],

                "staff" => [
                    "user_details" => $user,
                    "staff_details" => [
                        "staff_table_id" => $staff->id,
                        "user_id" => $staff->user_id,
                    ]
                ],

                "attendance" => [
                    "status_label" => "Mark",
                    'status_code' => $is_holiday_today ? 3 : null,
                    "formatted_date" => Carbon::parse($date)->format('l, F j, Y'),
                ],

                "leave" => [
                    "detected_leave_type" => $leaveType,
                    "admin_leave" => [
                        "is_admin_leave" => $isAdminLeave,
                        "types_detected" => $adminHalves
                    ],
                    "attendance_created_leave" => [
                        "is_attendance_leave" => $isAttendanceLeave,
                        "types_detected" => $attnHalves,
                    ]
                ],

                "holiday_config" => $holiday_days,
                'holiday' => $holidays ?? false,
                'is_holiday_today' => $is_holiday_today,
                'payroll_exists' => $payrollExists ?? false,
            ];
        }

        return response()->json([
            "date" => $date,
            "total" => count($rows),
            "rows" => $rows,
        ]);
    }

    private function monthlyStaffAttendanceData(Request $request)
    {
        $start = Carbon::create($request->year, $request->month, 1)->startOfMonth();
        $end = Carbon::create($request->year, $request->month, 1)->endOfMonth();

        $attendance = $this->staffAttendance->builder()
            ->with(['user:id,first_name,last_name,email,image'])
            ->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->when($request->search, function ($q) use ($request) {
                $q->whereHas('user', function ($x) use ($request) {
                    $x->where('first_name', 'like', "%{$request->search}%")
                        ->orWhere('last_name', 'like', "%{$request->search}%")
                        ->orWhereRaw("CONCAT(first_name,' ',last_name) LIKE ?", ["%{$request->search}%"]);
                });
            })
            ->get(['id', 'staff_id', 'date', 'type']);

        $holidays = Holiday::whereBetween('date', [
            $start->format('Y-m-d'),
            $end->format('Y-m-d')
        ])->get();

        return response()->json([
            'success' => true,
            'attendance' => $attendance,
            'holiday' => $holidays,
        ]);
    }

    public function storeStaffAttendanceData(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Staff Attendance Management');
        ResponseService::noAnyPermissionThenRedirect(['staff-attendance-edit']);

        $request->validate(['date' => 'required']);

        try {
            DB::beginTransaction();

            $sessionYear = $this->cache->getDefaultSessionYear();
            $dateYmd = date('Y-m-d', strtotime($request->date));
            $attendanceMonth = Carbon::parse($dateYmd)->month;
            $attendanceYear = Carbon::parse($dateYmd)->year;


            $holiday = Holiday::where('date', $dateYmd)->first();
            if ($holiday) {
                DB::rollBack();
                return ResponseService::errorResponse(
                    "The selected date ($dateYmd) is marked as holiday ({$holiday->title}). Attendance cannot be modified."
                );
            }
            $leaveMasterHoliday = $this->leaveMaster->builder()
                ->where('session_year_id', $sessionYear->id)
                ->value('holiday');
            if ($leaveMasterHoliday) {
                $holidayDays = explode(',', $leaveMasterHoliday);
                $dayName = Carbon::parse($dateYmd)->format('l');
                if (in_array($dayName, $holidayDays)) {
                    DB::rollBack();
                    return ResponseService::errorResponse(
                        "Attendance cannot be modified on $dayName."
                    );
                }
            }
            $attendanceRows = [];
            $absentUsers = [];

            /**
             * SAFE HELPERS (NO refresh(), NO broken relations)
             */

            // Create a new leave_detail
            $mkDetail = function (int $leaveId, string $type) use ($dateYmd) {
                return $this->leaveDetail->create([
                    'leave_id' => $leaveId,
                    'date' => $dateYmd,
                    'type' => $type,
                    'school_id' => Auth::user()->school_id,
                ]);
            };

            $reason = $request->attendance_data[0]['reason'] ?? 'System: Attendance';
            // Create complete leave + detail
            $mkLeaveWithDetail = function (int $userId, string $type) use ($dateYmd, $mkDetail, $reason) {
                $leave = $this->leave->create([
                    'user_id' => $userId,
                    'reason' => $reason,
                    'from_date' => $dateYmd,
                    'to_date' => $dateYmd,
                    'leave_master_id' => 1,
                    'status' => 1,
                    'school_id' => Auth::user()->school_id,
                ]);

                $detail = $mkDetail($leave->id, $type);
                return [$leave, $detail];
            };

            // SAFE delete detail + parent if needed (NO relation calls)
            $deleteDetailAndCascade = function ($detail) {
                if (!$detail)
                    return;

                $leaveId = $detail->leave_id;  // capture ID safely
                $detail->delete();

                $leave = Leave::find($leaveId);
                if ($leave && $leave->leave_detail()->count() == 0) {
                    $leave->delete();
                }
            };

            // Helper: find first detail of given type
            $firstByType = function ($details, string $type) {
                return $details->firstWhere('detail.type', $type);
            };

            $isBulk = count($request->attendance_data) > 1;
            $skippedStaffCount = 0;
            $singleSkipped = false;


            foreach ($request->attendance_data as $row) {

                $staffId = (int) $row['staff_id'];
                $attendanceType = (int) ($row['type'] ?? 1);
                $reason = $row['reason'] ?? null;

                $staffIds = $this->staff->builder()->where('user_id', $staffId)->first();
                $payrollExists = Expense::where('staff_id', $staffIds->id)
                    ->where('month', $attendanceMonth)
                    ->where('year', $attendanceYear)
                    ->exists();

                if ($payrollExists) {

                    if ($isBulk) {
                        $skippedStaffCount++;
                    } else {
                        $singleSkipped = true;
                    }

                    continue; // skip processing this staff
                }

                // ✅ HOLIDAY (type=3, do NOT touch leaves)
                if ($attendanceType === 3) {
                    $attendanceRows[] = [
                        'id' => $row['id'] ?? null,
                        'staff_id' => $staffId,
                        'session_year_id' => $sessionYear->id,
                        'type' => 3,
                        'date' => $dateYmd,
                        'reason' => null,
                        'leave_id' => null,
                        'leave_detail_id' => null,
                    ];
                    continue;
                }

                // ✅ Load all leaves for the date
                $leaves = $this->leave->builder()
                    ->where('user_id', $staffId)
                    ->where('from_date', '<=', $dateYmd)
                    ->where('to_date', '>=', $dateYmd)
                    ->where('status', 1)
                    ->with(['leave_detail' => fn($q) => $q->where('date', $dateYmd)])
                    ->get();

                // ✅ Existing attendance record (if any)
                $attendance = $this->staffAttendance->builder()
                    ->where('staff_id', $staffId)
                    ->where('date', $dateYmd)
                    ->first();

                // ✅ Split leave details into admin vs attendance-created
                $adminDetails = collect();
                $attnDetails = collect();

                foreach ($leaves as $leave) {
                    foreach ($leave->leave_detail as $d) {
                        if ($attendance && $attendance->leave_detail_id == $d->id) {
                            $attnDetails->push(['leave' => $leave, 'detail' => $d]);
                        } else {
                            $adminDetails->push(['leave' => $leave, 'detail' => $d]);
                        }
                    }
                }

                $has = function ($set, $type) {
                    return $set->firstWhere('detail.type', $type) !== null;
                };

                // Flags
                $adminHasFull = $has($adminDetails, 'Full');
                $adminHasFirst = $has($adminDetails, 'First Half');
                $adminHasSecond = $has($adminDetails, 'Second Half');

                $attnHasFull = $has($attnDetails, 'Full');
                $attnHasFirst = $has($attnDetails, 'First Half');
                $attnHasSecond = $has($attnDetails, 'Second Half');

                $leaveIdForAttendance = null;
                $leaveDetailIdForAttn = null;

                /**
                 * ✅ APPLY ATTENDANCE LOGIC
                 */

                switch ($attendanceType) {

                    // ✅ FULL PRESENT → delete ALL attendance-created details
                    case 1:
                        foreach (['Full', 'First Half', 'Second Half'] as $t) {
                            $node = $firstByType($attnDetails, $t);
                            if ($node)
                                $deleteDetailAndCascade($node['detail']);
                        }
                        break;

                    // ✅ FULL ABSENT
                    case 0:
                        $absentUsers[] = $staffId;

                        // CASE A: Admin already full → attendance creates nothing
                        if ($adminHasFull || ($adminHasFirst && $adminHasSecond)) {
                            foreach (['Full', 'First Half', 'Second Half'] as $t) {
                                $node = $firstByType($attnDetails, $t);
                                if ($node)
                                    $deleteDetailAndCascade($node['detail']);
                            }
                        }

                        // CASE B: Admin First only → attendance creates Second
                        elseif ($adminHasFirst && !$adminHasSecond) {

                            foreach (['Full', 'First Half'] as $t) {
                                $node = $firstByType($attnDetails, $t);
                                if ($node)
                                    $deleteDetailAndCascade($node['detail']);
                            }

                            $node = $firstByType($attnDetails, 'Second Half');
                            if ($node) {
                                $leaveIdForAttendance = $node['leave']->id;
                                $leaveDetailIdForAttn = $node['detail']->id;
                            } else {
                                [$leave, $detail] = $mkLeaveWithDetail($staffId, 'Second Half');
                                $leaveIdForAttendance = $leave->id;
                                $leaveDetailIdForAttn = $detail->id;
                            }
                        }

                        // CASE C: Admin Second only → attendance creates First
                        elseif ($adminHasSecond && !$adminHasFirst) {

                            foreach (['Full', 'Second Half'] as $t) {
                                $node = $firstByType($attnDetails, $t);
                                if ($node)
                                    $deleteDetailAndCascade($node['detail']);
                            }

                            $node = $firstByType($attnDetails, 'First Half');
                            if ($node) {
                                $leaveIdForAttendance = $node['leave']->id;
                                $leaveDetailIdForAttn = $node['detail']->id;
                            } else {
                                [$leave, $detail] = $mkLeaveWithDetail($staffId, 'First Half');
                                $leaveIdForAttendance = $leave->id;
                                $leaveDetailIdForAttn = $detail->id;
                            }
                        }

                        // CASE D: No admin leaves → attendance creates full
                        else {
                            // convert half to full
                            if ($attnHasFirst || $attnHasSecond) {
                                $node = $attnHasFirst ? $firstByType($attnDetails, 'First Half')
                                    : $firstByType($attnDetails, 'Second Half');

                                $oldLeaveId = $node['leave']->id;
                                $deleteDetailAndCascade($node['detail']);

                                $leave = Leave::find($oldLeaveId);

                                if (!$leave) { // deleted by cascade
                                    [$leave, $detail] = $mkLeaveWithDetail($staffId, 'Full');
                                } else {
                                    $detail = $mkDetail($leave->id, 'Full');
                                }

                                $leaveIdForAttendance = $leave->id;
                                $leaveDetailIdForAttn = $detail->id;
                            }

                            // no attendance leave → create new full
                            elseif (!$attnHasFull) {
                                [$leave, $detail] = $mkLeaveWithDetail($staffId, 'Full');
                                $leaveIdForAttendance = $leave->id;
                                $leaveDetailIdForAttn = $detail->id;
                            } elseif ($attnHasFull) {
                                $node = $firstByType($attnDetails, 'Full');
                                $leaveIdForAttendance = $node['leave']->id;
                                $leaveDetailIdForAttn = $node['detail']->id;
                            }
                        }

                        break;

                    // ✅ FIRST HALF PRESENT → attendance creates Second Half
                    case 4:
                        // admin already second → enforce admin
                        if ($adminHasSecond) {
                            foreach (['First Half', 'Full'] as $t) {
                                $node = $firstByType($attnDetails, $t);
                                if ($node)
                                    $deleteDetailAndCascade($node['detail']);
                            }
                        } else {
                            // remove conflicts
                            foreach (['First Half', 'Full'] as $t) {
                                $node = $firstByType($attnDetails, $t);
                                if ($node)
                                    $deleteDetailAndCascade($node['detail']);
                            }

                            $node = $firstByType($attnDetails, 'Second Half');
                            if ($node) {
                                $leaveIdForAttendance = $node['leave']->id;
                                $leaveDetailIdForAttn = $node['detail']->id;
                            } else {
                                [$leave, $detail] = $mkLeaveWithDetail($staffId, 'Second Half');
                                $leaveIdForAttendance = $leave->id;
                                $leaveDetailIdForAttn = $detail->id;
                            }
                        }
                        break;

                    // ✅ SECOND HALF PRESENT → attendance creates First Half
                    case 5:
                        if ($adminHasFirst) {
                            foreach (['Second Half', 'Full'] as $t) {
                                $node = $firstByType($attnDetails, $t);
                                if ($node)
                                    $deleteDetailAndCascade($node['detail']);
                            }
                        } else {
                            foreach (['Second Half', 'Full'] as $t) {
                                $node = $firstByType($attnDetails, $t);
                                if ($node)
                                    $deleteDetailAndCascade($node['detail']);
                            }

                            $node = $firstByType($attnDetails, 'First Half');
                            if ($node) {
                                $leaveIdForAttendance = $node['leave']->id;
                                $leaveDetailIdForAttn = $node['detail']->id;
                            } else {
                                [$leave, $detail] = $mkLeaveWithDetail($staffId, 'First Half');
                                $leaveIdForAttendance = $leave->id;
                                $leaveDetailIdForAttn = $detail->id;
                            }
                        }
                        break;
                }

                // ✅ BUILD ATTENDANCE ROW
                $attendanceRows[] = [
                    'id' => $row['id'] ?? null,
                    'staff_id' => $staffId,
                    'session_year_id' => $sessionYear->id,
                    'type' => $attendanceType,
                    'date' => $dateYmd,
                    'reason' => $reason,
                    'leave_id' => $leaveIdForAttendance,
                    'leave_detail_id' => $leaveDetailIdForAttn,
                ];
            }

            // ✅ Upsert
            $this->staffAttendance->upsert(
                $attendanceRows,
                ['id'],
                ['staff_id', 'session_year_id', 'type', 'date', 'reason', 'leave_id', 'leave_detail_id']
            );

            DB::commit();

            if ($request->absent_notification && !empty($absentUsers)) {
                $d = Carbon::parse($dateYmd)->format('F jS, Y');
                send_notification($absentUsers, 'Absent', "You are marked absent on $d", 'attendance');
            }

            if ($isBulk) {

                if ($skippedStaffCount > 0) {
                    ResponseService::successResponse(
                        "Data Stored Successfully — {$skippedStaffCount} staff skipped due to payroll lock"
                    );
                } else {
                    ResponseService::successResponse("Data Stored Successfully");
                }

            } else { // single staff mode

                if ($singleSkipped) {
                    ResponseService::errorResponse(
                        "Attendance skipped — payroll for this month is already generated"
                    );
                } else {
                    ResponseService::successResponse("Attendance Stored Successfully");
                }

            }

        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, "Staff API Controller -> storeStaffAttendanceData Method");
            ResponseService::errorResponse();
        }
    }

}
