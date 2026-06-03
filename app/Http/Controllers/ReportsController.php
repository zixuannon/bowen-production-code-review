<?php

namespace App\Http\Controllers;

use App\Repositories\ClassSection\ClassSectionInterface;
use App\Repositories\FormField\FormFieldsInterface;
use App\Repositories\SessionYear\SessionYearInterface;
use App\Repositories\Student\StudentInterface;
use App\Repositories\User\UserInterface;
use App\Repositories\Fees\FeesInterface;
use App\Repositories\FeesPaid\FeesPaidInterface;
use App\Repositories\ExpenseCategory\ExpenseCategoryInterface;
use App\Repositories\Expense\ExpenseInterface;
use App\Repositories\Exam\ExamInterface;
use App\Repositories\Attendance\AttendanceInterface;
use App\Repositories\OnlineExam\OnlineExamInterface;
use App\Repositories\OnlineExamQuestion\OnlineExamQuestionInterface;
use App\Repositories\OnlineExamQuestionChoice\OnlineExamQuestionChoiceInterface;
use App\Repositories\OnlineExamQuestionOption\OnlineExamQuestionOptionInterface;
use App\Repositories\OnlineExamStudentAnswer\OnlineExamStudentAnswerInterface;
use App\Repositories\StudentOnlineExamStatus\StudentOnlineExamStatusInterface;
use App\Repositories\ClassSchool\ClassSchoolInterface;
use App\Repositories\Subject\SubjectInterface;
use App\Repositories\Medium\MediumInterface;
use App\Repositories\Grades\GradesInterface;
use App\Repositories\PromoteStudent\PromoteStudentInterface;
use App\Repositories\Timetable\TimetableInterface;
use App\Repositories\SchoolSetting\SchoolSettingInterface;
use App\Models\TransportationPayment;
use App\Models\RoutePickupPoint;
use App\Models\ClassSection;
use App\Models\StaffAttendance;
use App\Models\Leave;
use App\Models\LeaveMaster;
use App\Models\StaffSalary;
use App\Services\FeaturesService;
use App\Services\ResponseService;
use App\Services\CachingService;
use Illuminate\Support\Facades\Auth;
use App\Repositories\Holiday\HolidayInterface;
use App\Services\BootstrapTableService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Carbon\Carbon;
use \App\Repositories\ExamResult\ExamResultInterface;
use App\Services\GeneralService;
use Illuminate\Support\Facades\DB;
use App\Models\Vehicle;
use Throwable;

class ReportsController extends Controller
{
    private ClassSectionInterface $classSection;
    private FormFieldsInterface $formFields;
    private SessionYearInterface $sessionYear;
    private StudentInterface $student;
    private FeesInterface $fees;
    private FeesPaidInterface $feesPaid;
    private UserInterface $user;
    private AttendanceInterface $attendance;
    private CachingService $cache;
    private HolidayInterface $holiday;
    private ExamResultInterface $examResult;
    private ExamInterface $exam;
    private OnlineExamInterface $onlineExam;
    private OnlineExamQuestionChoiceInterface $onlineExamQuestionChoice;
    private OnlineExamQuestionInterface $onlineExamQuestion;
    private OnlineExamQuestionOptionInterface $onlineExamQuestionOption;
    private OnlineExamStudentAnswerInterface $onlineExamStudentAnswer;
    private StudentOnlineExamStatusInterface $studentOnlineExamStatus;
    private ClassSchoolInterface $class;
    private SubjectInterface $subject;
    private MediumInterface $mediums;
    private GradesInterface $grade;
    private GeneralService $generalService;
    private StudentInterface $students;
    private PromoteStudentInterface $promoteStudent;
    private ExpenseCategoryInterface $expenseCategory;
    private ExpenseInterface $expense;
    private TimetableInterface $timetable;
    private SchoolSettingInterface $schoolSettings;

    public function __construct(
        ClassSectionInterface $classSection,
        FormFieldsInterface $formFields,
        SessionYearInterface $sessionYear,
        StudentInterface $student,
        FeesInterface $fees,
        FeesPaidInterface $feesPaid,
        UserInterface $user,
        AttendanceInterface $attendance,
        CachingService $cachingService,
        HolidayInterface $holiday,
        ExamResultInterface $examResult,
        ExamInterface $exam,
        OnlineExamInterface $onlineExam,
        OnlineExamQuestionChoiceInterface $onlineExamQuestionChoice,
        OnlineExamQuestionInterface $onlineExamQuestion,
        OnlineExamQuestionOptionInterface $onlineExamQuestionOption,
        OnlineExamStudentAnswerInterface $onlineExamStudentAnswer,
        StudentOnlineExamStatusInterface $studentOnlineExamStatus,
        ClassSchoolInterface $class,
        SubjectInterface $subject,
        MediumInterface $mediums,
        GradesInterface $grade,
        GeneralService $generalService,
        StudentInterface $students,
        PromoteStudentInterface $promoteStudent,
        ExpenseCategoryInterface $expenseCategory,
        ExpenseInterface $expense,
        TimetableInterface $timetable,
        SchoolSettingInterface $schoolSettings
    ) {
        $this->student = $student;
        $this->user = $user;
        $this->classSection = $classSection;
        $this->formFields = $formFields;
        $this->sessionYear = $sessionYear;
        $this->fees = $fees;
        $this->feesPaid = $feesPaid;
        $this->attendance = $attendance;
        $this->cache = $cachingService;
        $this->holiday = $holiday;
        $this->examResult = $examResult;
        $this->exam = $exam;
        $this->onlineExam = $onlineExam;
        $this->onlineExamQuestionChoice = $onlineExamQuestionChoice;
        $this->onlineExamQuestion = $onlineExamQuestion;
        $this->onlineExamQuestionOption = $onlineExamQuestionOption;
        $this->onlineExamStudentAnswer = $onlineExamStudentAnswer;
        $this->studentOnlineExamStatus = $studentOnlineExamStatus;
        $this->students = $students;
        $this->promoteStudent = $promoteStudent;

        $this->class = $class;
        $this->subject = $subject;
        $this->mediums = $mediums;
        $this->grade = $grade;
        $this->generalService = $generalService;
        $this->expenseCategory = $expenseCategory;
        $this->expense = $expense;
        $this->timetable = $timetable;
        $this->schoolSettings = $schoolSettings;
    }

    public function student_reports()
    {
        ResponseService::noPermissionThenRedirect('reports-student');
        // ResponseService::noFeatureThenRedirect('Reports Management');
        // ResponseService::noPermissionThenRedirect('student-list');
        $class_sections = $this->classSection->all(['*'], ['class', 'class.stream', 'section', 'medium']);

        if (Auth::user()->school_id) {
            $extraFields = $this->formFields->defaultModel()->where('user_type', 1)->orderBy('rank')->get();
        } else {
            $extraFields = $this->formFields->defaultModel()->orderBy('rank')->get();
        }

        $sessionYears = $this->sessionYear->all();
        $features = FeaturesService::getFeatures();

        return view('reports.student.student-reports', compact('class_sections', 'extraFields', 'sessionYears', 'features'));
    }

    public function student_reports_show(Request $request)
    {
        ResponseService::noPermissionThenRedirect('reports-student');

        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'ASC');
        $search = request('search');
        $classId = request('class_id');
        $sessionYearID = request('session_year_id');

        // Get promoted students if both filters are applied
        $promotedMap = collect();
        if ($classId && $sessionYearID) {
            $promotedMap = $this->promoteStudent->builder()->where('class_section_id', $classId)
                ->where('session_year_id', $sessionYearID)
                ->get(['student_id', 'class_section_id', 'session_year_id'])
                ->keyBy('student_id');
        }

        // Main student query
        $sql = $this->student->builder()
            ->where(function ($query) {
                $query->where('application_type', 'offline')
                    ->orWhere(function ($q) {
                        $q->where('application_type', 'online')
                            ->where('application_status', 1);
                    });
            })
            ->with([
                'user.extra_student_details.form_field',
                'guardian',
                'class_section.class.stream',
                'class_section.section',
                'class_section.medium'
            ])
            ->where(function ($query) use ($search) {
                $query->when($search, function ($query) use ($search) {
                    $query->where(function ($query) use ($search) {
                        $query->where('user_id', 'LIKE', "%$search%")
                            ->orWhere('class_section_id', 'LIKE', "%$search%")
                            ->orWhere('admission_no', 'LIKE', "%$search%")
                            ->orWhere('roll_number', 'LIKE', "%$search%")
                            ->orWhere('admission_date', 'LIKE', date('Y-m-d', strtotime("%$search%")))
                            ->orWhereHas('user', function ($q) use ($search) {
                                $q->where('first_name', 'LIKE', "%$search%")
                                    ->orWhere('last_name', 'LIKE', "%$search%")
                                    ->orWhere('email', 'LIKE', "%$search%")
                                    ->orWhere('dob', 'LIKE', "%$search%")
                                    ->orWhereRaw("concat(first_name,' ',last_name) LIKE ?", ["%{$search}%"]);
                            })->orWhereHas('guardian', function ($q) use ($search) {
                                $q->where('first_name', 'LIKE', "%$search%")
                                    ->orWhere('last_name', 'LIKE', "%$search%")
                                    ->orWhere('email', 'LIKE', "%$search%")
                                    ->orWhere('dob', 'LIKE', "%$search%")
                                    ->orWhereRaw("concat(first_name,' ',last_name) LIKE ?", ["%{$search}%"]);
                            });
                    });
                });
            })
            ->when($classId, function ($query) use ($classId, $promotedMap) {
                $query->where(function ($q) use ($classId, $promotedMap) {
                    $q->where('class_section_id', $classId);
                    if ($promotedMap->isNotEmpty()) {
                        $q->orWhereIn('user_id', $promotedMap->keys());
                    }
                });
            })
            ->when($sessionYearID, function ($query) use ($sessionYearID, $promotedMap) {
                $query->where(function ($q) use ($sessionYearID, $promotedMap) {
                    $q->where('session_year_id', $sessionYearID);
                    if ($promotedMap->isNotEmpty()) {
                        $q->orWhereIn('user_id', $promotedMap->keys());
                    }
                });
            });

        $total = $sql->count();

        if (!empty($request->class_id)) {
            $sql = $sql->orderBy('roll_number', 'ASC');
        } else {
            $sql = $sql->orderBy($sort, $order);
        }
        if ($offset >= $total && $total > 0) {
            $lastPage = floor(($total - 1) / $limit) * $limit; // calculate last page offset
            $offset = $lastPage;
        }
        $sql->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = 1;

        foreach ($res as $row) {
            $userId = $row->user_id;

            // Override class_section_id and session_year_id from promotion if available
            if ($promotedMap->has($userId)) {
                $promoted = $promotedMap->get($userId);
                $row->class_section_id = $promoted->class_section_id;
                $row->session_year_id = $promoted->session_year_id;

                $classSection = $this->classSection->builder()->with('class.stream', 'section', 'medium')
                    ->find($promoted->class_section_id);

                // ⚠️ Replace loaded relation
                $row->setRelation('class_section', $classSection);
            }

            $operate = BootstrapTableService::viewButton(
                route('reports.student.student-view-reports', [$row->user->id, request('session_year_id')]),
                [],
                [
                    'title' => trans('View Student Details'),
                    'target' => ''
                ]
            );

            $student_gender = $row->user->gender;
            $guardian_gender = $row->guardian->gender ?? '';

            $row->user->gender = trans(strtolower($row->user->gender));
            $row->guardian->gender = trans(strtolower($row->guardian->gender ?? ''));

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['eng_student_gender'] = $student_gender;
            $tempRow['eng_guardian_gender'] = $guardian_gender;
            $tempRow['extra_fields'] = $row->user->extra_student_details;

            foreach ($row->user->extra_student_details as $field) {
                $data = '';
                if ($field->form_field->type == 'checkbox') {
                    $data = json_decode($field->data);
                } elseif ($field->form_field->type == 'file') {
                    $data = '<a href="' . Storage::url($field->data) . '" target="_blank">DOC</a>';
                } elseif ($field->form_field->type == 'dropdown') {
                    $data = $field->data ?? '';
                } else {
                    $data = $field->data;
                }

                $tempRow[$field->form_field->name] = $data;
            }

            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }



    public function student_view_reports($id, $session_year_id)
    {
        ResponseService::noPermissionThenRedirect('reports-student');
        $schoolSettings = $this->cache->getSchoolSettings();
        // Check if the student has a promotion for the requested session_year_id
        $promoted = \App\Models\PromoteStudent::where('student_id', $id)
            ->where('session_year_id', $session_year_id)
            ->first();

        if ($promoted) {
            // Load the promoted class_section with relations
            $classSection = $this->classSection->builder()->with('class.stream', 'section', 'medium')
                ->find($promoted->class_section_id);

            // Load the promoted session year
            $promotedSessionYear = $this->sessionYear->builder()->select('id', 'name')
                ->find($promoted->session_year_id);

            // Load the student with basic relations (excluding original session_year and class_section)
            $student = $this->student->builder()->where('user_id', $id)
                ->with([
                    'user',
                    'guardian',
                ])
                ->first();

            // Override class_section_id and session_year_id
            $student->class_section_id = $promoted->class_section_id;
            $student->session_year_id = $promoted->session_year_id;

            // Override the relations
            $student->setRelation('class_section', $classSection);
            $student->setRelation('session_year', $promotedSessionYear);

        } else {
            // No promotion: load full relations normally
            $student = $this->student->builder()->where('user_id', $id)
                ->with([
                    'user',
                    'guardian',
                    'class_section.class.stream',
                    'class_section.section',
                    'class_section.medium',
                    'session_year:id,name'
                ])
                ->first();
        }

        $transportation = null;

        $transportationPayments = TransportationPayment::with(['transportationFee', 'paymentTransaction', 'pickupPoint', 'routeVehicle', 'shift'])
            ->where('user_id', $id)
            ->where('status', 'paid')
            ->orderBy('id', 'desc')
            ->first();

        if ($transportationPayments) {
            $routePickupPoint = null;

            if ($transportationPayments->pickupPoint && $transportationPayments->routeVehicle?->route) {
                $routePickupPoint = RoutePickupPoint::where('pickup_point_id', $transportationPayments->pickupPoint->id)
                    ->where('route_id', $transportationPayments->routeVehicle->route->id)
                    ->first();
            }

            $transportation = [
                'plan' => [
                    'plan_status' => ($transportationPayments->expiry_date && $transportationPayments->expiry_date > date('Y-m-d')) ? 'Active' : 'Expired',
                    'vehicle_assignment' => ($transportationPayments->routeVehicle) ? 'Assigned' : 'Pending',
                    'expiry_date' => $transportationPayments->expiry_date,
                    'paid_amount' => format_money($transportationPayments->amount),
                    'payment_mode' => $transportationPayments->paymentTransaction->payment_gateway ?? null,
                ],
                'shift' => [
                    'name' => $transportationPayments->shift->name ?? null,
                    'start_time' => Carbon::parse($transportationPayments->shift->start_time)->format($schoolSettings['time_format']) ?? null,
                    'end_time' => Carbon::parse($transportationPayments->shift->end_time)->format($schoolSettings['time_format']) ?? null,
                ],
                'fee' => [
                    'duration' => $transportationPayments->transportationFee->duration ?? null,
                    'amount' => format_money($transportationPayments->transportationFee->fee_amount ?? null),
                ],
                'route' => [
                    'route_name' => $transportationPayments->routeVehicle->route->name ?? null,
                    'pickup_point_name' => $transportationPayments->pickupPoint->name ?? null,
                    'pickup_time' => $routePickupPoint?->pickup_time
                        ? Carbon::parse($routePickupPoint->pickup_time)->format($schoolSettings['time_format'])
                        : null,
                    'drop_time' => $routePickupPoint?->drop_time
                        ? Carbon::parse($routePickupPoint->drop_time)->format($schoolSettings['time_format'])
                        : null,
                ],
                'vehicle' => [
                    'name' => $transportationPayments->routeVehicle->vehicle->name ?? null,
                    'number' => $transportationPayments->routeVehicle->vehicle->vehicle_number ?? null,
                    'capacity' => $transportationPayments->routeVehicle->vehicle->capacity ?? null,
                    'driver' => $transportationPayments->routeVehicle->driver ?? null,
                    'helper' => $transportationPayments->routeVehicle->helper ?? null
                ],
            ];
        }

        // Get all fees for this student
        $studentFees = $this->getStudentFees($id, $session_year_id);

        // Get all session years for exam tab
        $sessionYears = $this->sessionYear->all();
        $sessionYear = $this->cache->getDefaultSessionYear();

        return view('reports.student.student-view-reports', compact('student', 'studentFees', 'sessionYears', 'sessionYear', 'session_year_id', 'transportation'));
    }



    public function getStudentFees($student_id, $session_year_id)
    {
        ResponseService::noPermissionThenRedirect('reports-student');
        // Check if promoted for the session year, else fallback to original
        $promoted = null;
        if ($session_year_id) {
            $promoted = $this->promoteStudent->builder()->where('student_id', $student_id)
                ->where('session_year_id', $session_year_id)
                ->first();
        }

        if ($promoted) {
            // Get promoted class_section
            $classSectionId = $promoted->class_section_id;
        } else {
            // Load student to get original class_section
            $student = $this->student->builder()
                ->where('user_id', $student_id)
                ->with('class_section:id,class_id')
                ->first();

            if (!$student) {
                return collect([]);
            }

            $classSectionId = $student->class_section_id;
        }

        // Get the class_id from class_section
        $classSection = $this->classSection->builder()->find($classSectionId);
        if (!$classSection) {
            return collect([]);
        }
        $classId = $classSection->class_id;

        // Now get all fees assigned to this class
        $allClassFees = $this->fees->builder()
            ->where('class_id', $classId)
            ->with([
                'fees_class_type.fees_type',
                'installments:id,name,due_date,due_charges,fees_id'
            ])
            ->get();

        // Get paid fees for student (session_year filter can be added if relevant)
        $paidFees = $this->feesPaid->builder()
            ->where('student_id', $student_id)
            ->with([
                'fees.fees_class_type.fees_type',
                'compulsory_fee.installment_fee:id,name',
                'optional_fee' => function ($q) {
                    $q->with([
                        'fees_class_type' => function ($q) {
                            $q->select('id', 'fees_type_id')->with('fees_type:id,name');
                        }
                    ]);
                }
            ])
            ->get();

        $paidFeesIds = $paidFees->pluck('fees_id')->toArray();

        $paidFees->transform(function ($paidFee) {
            $paidFee->status = $paidFee->is_fully_paid ? 'paid' : 'partial';
            return $paidFee;
        });

        $unpaidFees = $allClassFees->filter(function ($fee) use ($paidFeesIds) {
            return !in_array($fee->id, $paidFeesIds);
        })->map(function ($fee) use ($student_id) {
            $virtualRecord = new \stdClass();
            $virtualRecord->id = null;
            $virtualRecord->fees_id = $fee->id;
            $virtualRecord->student_id = $student_id;
            $virtualRecord->is_fully_paid = 0;
            $virtualRecord->is_used_installment = 0;
            $virtualRecord->amount = $fee->total_compulsory_fees;
            $virtualRecord->date = null;
            $virtualRecord->fees = $fee;
            $virtualRecord->compulsory_fee = [];
            $virtualRecord->optional_fee = [];
            $virtualRecord->status = 'unpaid';

            if (isset($fee->due_date)) {
                try {
                    $today = \Carbon\Carbon::now()->startOfDay();
                    $dueDate = \Carbon\Carbon::createFromFormat('d-m-Y', $fee->due_date)->startOfDay();
                    if ($dueDate->lt($today)) {
                        $virtualRecord->status = 'overdue';
                    }
                } catch (\Exception $e) {
                    // ignore parse errors, keep status as unpaid
                }
            }
            return $virtualRecord;
        });

        $allFees = $paidFees->concat($unpaidFees);

        return $allFees->sortBy(function ($fee) {
            return match ($fee->status) {
                'overdue' => 1,
                'unpaid' => 2,
                'partial' => 3,
                'paid' => 4,
                default => 5,
            };
        });
    }

    public function getStudentAttendanceReport(Request $request)
    {
        ResponseService::noPermissionThenRedirect('reports-student');
        // Validate request parameters
        $request->validate([
            'month' => 'required|numeric|between:1,12',
            'student_id' => 'required|exists:users,id',
            'session_year_id' => 'required',
        ]);

        // Get current session year
        $sessionYear = $this->cache->getDefaultSessionYear();

        // Get student information including class section
        $student = $this->student->builder()
            ->where('user_id', $request->student_id)
            ->with('class_section')
            ->first();

        if (!$student) {
            return response()->json([
                'success' => false,
                'message' => 'Student not found'
            ], 404);
        }
        // Create a Carbon date for the first day of the month
        $startDate = Carbon::createFromDate($sessionYear->start_date, $request->month, 1)->startOfMonth();
        $endDate = Carbon::createFromDate($sessionYear->end_date, $request->month, 1)->endOfMonth();

        // Get attendance records for this student in the specified month
        $attendanceRecords = $this->attendance->builder()
            ->where('student_id', $request->student_id)
            ->where('class_section_id', $student->class_section_id)
            ->where('session_year_id', $request->session_year_id)
            ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->get();

        // handle holiday attendance
        // $holidayAttendance = $this->holiday->builder()
        //     ->where('date', '>=', $startDate->format('Y-m-d'))
        //     ->where('date', '<=', $endDate->format('Y-m-d'))
        // ->get();

        $holidayAttendance = [];


        // Count present, absent and holiday days
        $presentCount = $attendanceRecords->where('type', 1)->count();
        $absentCount = $attendanceRecords->where('type', 0)->count();
        $holidayCount = $attendanceRecords->where('type', 3)->count();

        // Calculate attendance percentage
        $totalDays = $presentCount + $absentCount;
        $attendancePercentage = $totalDays > 0 ? round(($presentCount / $totalDays) * 100) : 0;

        // Prepare the response data
        $responseData = [
            'success' => true,
            'attendance' => $attendanceRecords,
            'holiday' => $holidayAttendance,
            'summary' => [
                'present_count' => $presentCount,
                'absent_count' => $absentCount,
                'holiday_count' => $holidayCount,
                'attendance_percentage' => $attendancePercentage,
                'total_days' => $totalDays
            ]
        ];

        return response()->json($responseData);
    }

    public function getStudentExamReport(Request $request)
    {
        ResponseService::noPermissionThenRedirect('reports-student');
        // Validate request parameters
        $request->validate([
            'student_id' => 'required|exists:users,id',
            'session_year_id' => 'nullable|exists:session_years,id'
        ]);

        // Get current session year if not provided
        $sessionYearId = $request->session_year_id ?? $this->cache->getDefaultSessionYear()->id;

        // Get student information including class section
        $student = $this->student->builder()
            ->where('user_id', $request->student_id)
            ->with(['class_section.class', 'class_section.section', 'user'])
            ->first();

        if (!$student) {
            return response()->json([
                'success' => false,
                'message' => 'Student not found'
            ], 404);
        }

        // Get offline exam results
        $offlineExamResults = $this->getOfflineExamResults($request->student_id, $sessionYearId);

        // Get online exam results
        $onlineExamResults = $this->getOnlineExamResults($request->student_id, $sessionYearId);

        // Get all available exams for this class section
        $availableExams = $this->exam->builder()
            ->with('timetable.class_subject', 'timetable.exam_marks.user.student')
            ->where('publish', 1)
            ->where('session_year_id', $sessionYearId)
            ->get();

        // Check if there are any exams available
        if ($availableExams->isEmpty() && empty($offlineExamResults) && empty($onlineExamResults)) {
            return response()->json([
                'success' => true,
                'message' => 'No exams available for this student',
                'student' => [
                    'id' => $student->user_id,
                    'name' => $student->user->full_name,
                    'admission_no' => $student->admission_no,
                    'roll_number' => $student->roll_number,
                    'class' => $student->class_section->class->name ?? 'N/A',
                    'section' => $student->class_section->section->name ?? 'N/A'
                ],
                'exams' => []
            ]);
        }

        // Process offline exam results
        $offlineExams = collect($offlineExamResults)->map(function ($result) use ($student) {
            // Make sure result is an object
            if (!is_object($result)) {
                return null;
            }

            try {
                // Get subjects for this exam
                $subjects = $this->getExamSubjectsWithMarks($result->exam_id, $student->user_id);

                // Get rank in class
                $classRank = $this->getStudentExamRank($result->exam_id, $student->class_section_id, $student->user_id);

                // Determine division based on percentage
                $division = null;
                if ($result->percentage >= 75) {
                    $division = 'First';
                } elseif ($result->percentage >= 60) {
                    $division = 'Second';
                } elseif ($result->percentage >= 33) {
                    $division = 'Third';
                }

                return [
                    'id' => $result->exam_id,
                    'name' => $result->exam->name,
                    'description' => $result->exam->description,
                    'published' => (bool) $result->exam->publish,
                    'subjects' => $subjects,
                    'exam_type' => 'Offline Exam',
                    'summary' => [
                        'max_marks' => $result->total_marks,
                        'obtained_marks' => $result->obtained_marks,
                        'percentage' => $result->percentage,
                        'result' => $result->status ? 'Pass' : 'Fail',
                        'division' => $division,
                        'rank' => $classRank,
                    ]
                ];
            } catch (\Exception $e) {
                \Log::error('Error processing exam result: ' . $e->getMessage());
                return null;
            }
        })->filter()->values(); // Remove any null values and reindex

        // Process online exam results
        $onlineExams = collect($onlineExamResults)->map(function ($result) {
            return [
                'id' => $result['id'] ?? null,
                'name' => $result['exam_title'] ?? 'Online Exam',
                'subject_name' => $result['subject_name'] ?? null,
                'total_marks' => $result['total_marks'] ?? 0,
                'exam_total_marks' => $result['exam_total_marks'] ?? 0,
                'total_obtained_marks' => $result['total_obtained_marks'] ?? 0,
                'percentage' => $result['percentage'] ?? 0,
                'status' => $result['status'] ?? 'Not Available',
                'exam_type' => 'Online Exam',
                'subject_type' => $result['subjects'][0]['type'] ?? null,
            ];
        })->values();

        // Add missing exams with not attempted status
        $attemptedExamIds = $offlineExams->pluck('id')->toArray();
        $missingExams = $availableExams->filter(function ($exam) use ($attemptedExamIds) {
            return !in_array($exam->id, $attemptedExamIds);
        })->map(function ($exam) use ($student) {
            // Get subjects for this exam even though it wasn't attempted
            $subjects = $this->getExamSubjectsWithMarks($exam->id, $student->user_id);

            if (!$subjects) {
                return [];
            }

            return [
                'id' => $exam->id,
                'name' => $exam->name,
                'description' => $exam->description,
                'published' => true,
                'subjects' => $subjects,
                'exam_type' => 'Offline Exam',
                'summary' => [
                    'max_marks' => $subjects->sum('max_marks') ?? 0,
                    'obtained_marks' => 0,
                    'percentage' => 0,
                    'result' => 'Not Attempted',
                    'division' => null,
                    'rank' => 'N/A',
                    'pdf_url' => null,
                ]
            ];
        })->values();

        // Combine all exam results
        $allExams = $offlineExams->concat($missingExams)->concat($onlineExams);

        // Prepare response
        $responseData = [
            'success' => true,
            'student' => [
                'id' => $student->user_id,
                'name' => $student->user->full_name,
                'admission_no' => $student->admission_no,
                'roll_number' => $student->roll_number,
                'class' => $student->class_section->class->name ?? 'N/A',
                'section' => $student->class_section->section->name ?? 'N/A',
                'photo' => $this->formatImageUrl($student->user->image)
            ],
            'exams' => $allExams->sortByDesc('id')->values()
        ];

        return response()->json($responseData);
    }

    // get online exam results
    private function getOnlineExamResults($studentId, $sessionYearId)
    {
        ResponseService::noPermissionThenRedirect('reports-student');
        // Get Online Exam Data Where Logged in Student have attempted data and Relation Data with Question Choice , Student's answer with user submitted question with question and its option
        $sql = $this->studentOnlineExamStatus->builder()
            ->with([
                'online_exam',
                'student_data',
                'online_exam.question_choice',
                'online_exam.class_subject.subject' // Include subject details
            ])
            ->where(['status' => 2])
            ->where('student_id', $studentId); // Make sure we're getting only this student's data

        $res = $sql->get();
        $rows = array();

        foreach ($res as $student_attempt) {
            // Create a new array for each result
            $examRow = array();

            $examRow['id'] = $student_attempt->online_exam_id; // Add exam ID
            $examRow['exam_title'] = $student_attempt->online_exam->title;
            $examRow['name'] = $student_attempt->online_exam->title; // Consistent naming with offline exams
            $examRow['description'] = $student_attempt->online_exam->description ?? '';
            $examRow['subject_name'] = $student_attempt->online_exam->class_subject->subject->name;
            $examRow['subject_type'] = $student_attempt->online_exam->class_subject->subject->type ?? 'General';
            $examRow['subject_code'] = $student_attempt->online_exam->class_subject->subject->code ?? '';
            $examRow['exam_total_marks'] = $student_attempt->online_exam->total_marks;

            $exam_submitted_question_ids = $this->onlineExamStudentAnswer->builder()
                ->where([
                    'student_id' => $student_attempt->student_id,
                    'online_exam_id' => $student_attempt->online_exam_id
                ])
                ->pluck('question_id');

            $question_ids = $this->onlineExamQuestionChoice->builder()
                ->whereIn('id', $exam_submitted_question_ids)
                ->pluck('question_id');

            $exam_attempted_answers = $this->onlineExamStudentAnswer->builder()
                ->where([
                    'student_id' => $student_attempt->student_id,
                    'online_exam_id' => $student_attempt->online_exam_id
                ])
                ->pluck('option_id');

            //removes the question id of the question if one of the answer of particular question is wrong
            foreach ($question_ids as $question_id) {
                $check_questions_answers_exists = $this->onlineExamQuestionOption->builder()
                    ->where(['question_id' => $question_id, 'is_answer' => 1])
                    ->whereNotIn('id', $exam_attempted_answers)
                    ->count();

                if ($check_questions_answers_exists) {
                    unset($question_ids[array_search($question_id, $question_ids->toArray())]);
                }
            }

            $exam_correct_answers_question_id = $this->onlineExamQuestionOption->builder()
                ->where(['is_answer' => 1])
                ->whereIn('id', $exam_attempted_answers)
                ->whereIn('question_id', $question_ids)
                ->pluck('question_id');

            // get the data of only attempted data
            $total_obtained_marks = $this->onlineExamQuestionChoice->builder()
                ->select(DB::raw("sum(marks)"))
                ->where('online_exam_id', $student_attempt->online_exam_id)
                ->whereIn('question_id', $exam_correct_answers_question_id)
                ->first();

            $total_obtained_marks = $total_obtained_marks['sum(marks)'] ?? 0;

            $total_marks = $this->onlineExamQuestionChoice->builder()
                ->select(DB::raw("sum(marks)"))
                ->where('online_exam_id', $student_attempt->online_exam_id)
                ->first();

            $total_marks = $total_marks['sum(marks)'] ?? 1; // Avoid division by zero

            $examRow['total_obtained_marks'] = $total_obtained_marks;
            $examRow['total_marks'] = $total_marks;
            $examRow['percentage'] = ($total_marks > 0) ? ($total_obtained_marks / $total_marks * 100) : 0;
            $examRow['status'] = $total_obtained_marks >= $student_attempt->online_exam->passing_marks ? 'Pass' : 'Fail';
            $examRow['exam_type'] = "Online Exam";
            $examRow['created_at'] = $student_attempt->created_at; // Add date taken

            // Create a subjects array similar to offline exams for consistent display
            $examRow['subjects'] = [
                [
                    'id' => $student_attempt->online_exam->class_subject->subject->id ?? 0,
                    'name' => $student_attempt->online_exam->class_subject->subject->name ?? 'General',
                    'code' => $student_attempt->online_exam->class_subject->subject->code ?? '',
                    'type' => $student_attempt->online_exam->class_subject->subject->type ?? 'General',
                    'max_marks' => $total_marks,
                    'min_marks' => $student_attempt->online_exam->passing_marks ?? ($total_marks * 0.33),
                    'obtained_marks' => $total_obtained_marks,
                    'grade' => null,
                    'is_pass' => $total_obtained_marks >= $student_attempt->online_exam->passing_marks
                ]
            ];

            // Create a summary consistent with offline exams
            $examRow['summary'] = [
                'max_marks' => $total_marks,
                'obtained_marks' => $total_obtained_marks,
                'percentage' => ($total_marks > 0) ? ($total_obtained_marks / $total_marks * 100) : 0,
                'result' => $total_obtained_marks >= $student_attempt->online_exam->passing_marks ? 'Pass' : 'Fail',
                'division' => $this->getDivisionByPercentage(($total_marks > 0) ? ($total_obtained_marks / $total_marks * 100) : 0),
                'rank' => 'N/A'
            ];

            $rows[] = $examRow;
        }
        return $rows;
    }

    // get offline exam results
    private function getOfflineExamResults($studentId, $sessionYearId)
    {
        ResponseService::noPermissionThenRedirect('reports-student');
        // Get exam results for this student directly from exam_results table
        $examResults = $this->examResult->builder()
            ->with(['exam:id,name,description,publish', 'user:id,first_name,last_name,image'])
            ->where('student_id', $studentId)
            ->where('session_year_id', $sessionYearId)
            ->get();

        return $examResults;
    }
    /**
     * Get subjects with marks for a specific exam and student
     * 
     * @param int $examId
     * @param int $studentId
     * @return array
     */
    private function getExamSubjectsWithMarks($examId, $studentId)
    {
        try {
            // Get student information first to have access to class_section_id
            $student = $this->student->builder()
                ->where('user_id', $studentId)
                ->select('class_section_id')
                ->first();

            if (!$student) {
                return [];
            }

            // First attempt: Get exam marks with subject information
            $examMarks = DB::table('exam_marks')
                ->join('exam_timetables', 'exam_timetables.id', '=', 'exam_marks.exam_timetable_id')
                ->join('class_subjects', 'class_subjects.id', '=', 'exam_timetables.class_subject_id')
                ->join('subjects', 'subjects.id', '=', 'class_subjects.subject_id')
                ->where('exam_timetables.exam_id', $examId)
                ->where('exam_marks.student_id', $studentId)
                ->select(
                    'subjects.id as subject_id',
                    'subjects.name as subject_name',
                    'subjects.type as subject_type',
                    'subjects.code as subject_code',
                    'exam_timetables.total_marks as max_marks',
                    'exam_timetables.passing_marks as min_marks',
                    'exam_marks.obtained_marks',
                    'exam_marks.grade',
                    'exam_marks.passing_status as is_pass'
                )
                ->get();

            // If no subjects are found, try another approach
            if ($examMarks->isEmpty()) {
                // Second attempt: Get subjects from exam timetables
                $examMarks = DB::table('exam_timetables')
                    ->join('class_subjects', 'class_subjects.id', '=', 'exam_timetables.class_subject_id')
                    ->join('subjects', 'subjects.id', '=', 'class_subjects.subject_id')
                    ->where('exam_timetables.exam_id', $examId)
                    ->where(function ($query) use ($student) {
                        // Either match by class_section_id or class_id
                        $query->where('class_subjects.class_section_id', $student->class_section_id)
                            ->orWhereExists(function ($subquery) use ($student) {
                            $subquery->select(DB::raw(1))
                                ->from('class_sections')
                                ->join('classes', 'classes.id', '=', 'class_sections.class_id')
                                ->whereRaw('class_sections.id = ?', [$student->class_section_id])
                                ->whereRaw('classes.id = class_subjects.class_id');
                        });
                    })
                    ->select(
                        'subjects.id as subject_id',
                        'subjects.name as subject_name',
                        'subjects.type as subject_type',
                        'subjects.code as subject_code',
                        'exam_timetables.total_marks as max_marks',
                        'exam_timetables.passing_marks as min_marks',
                        DB::raw('0 as obtained_marks'),
                        DB::raw('NULL as grade'),
                        DB::raw('0 as is_pass')
                    )
                    ->get();
            }

            // If still no subjects, try a third approach with just subjects from the class
            if ($examMarks->isEmpty()) {
                // Third attempt: Get subjects from class_subjects directly
                $examMarks = DB::table('class_subjects')
                    ->join('subjects', 'subjects.id', '=', 'class_subjects.subject_id')
                    ->where('class_subjects.class_section_id', $student->class_section_id)
                    ->select(
                        'subjects.id as subject_id',
                        'subjects.name as subject_name',
                        'subjects.type as subject_type',
                        'subjects.code as subject_code',
                        DB::raw('100 as max_marks'), // Default value
                        DB::raw('33 as min_marks'),  // Default value
                        DB::raw('0 as obtained_marks'),
                        DB::raw('NULL as grade'),
                        DB::raw('0 as is_pass')
                    )
                    ->get();
            }

            // If still no subjects, create some dummy data based on the exam results
            if ($examMarks->isEmpty()) {
                // Get exam result to at least show summary
                $examResult = DB::table('exam_results')
                    ->where('exam_id', $examId)
                    ->where('student_id', $studentId)
                    ->first();

                if ($examResult) {
                    // Create a single dummy subject as placeholder
                    $examMarks = collect([
                        (object) [
                            'subject_id' => 0,
                            'subject_name' => 'Overall Result',
                            'subject_type' => 'General',
                            'subject_code' => 'ALL',
                            'max_marks' => $examResult->total_marks,
                            'min_marks' => $examResult->total_marks * 0.33, // Assuming 33% passing
                            'obtained_marks' => $examResult->obtained_marks,
                            'grade' => null,
                            'is_pass' => $examResult->percentage >= 33
                        ]
                    ]);
                }
            }

            if ($examMarks->isEmpty()) {
                return [];
            }

            // Format and return the results
            return $examMarks->map(function ($mark) {
                return [
                    'id' => $mark->subject_id,
                    'name' => $mark->subject_name,
                    'code' => $mark->subject_code ?? '',
                    'type' => $mark->subject_type,
                    'max_marks' => $mark->max_marks,
                    'min_marks' => $mark->min_marks,
                    'obtained_marks' => $mark->obtained_marks,
                    'grade' => $mark->grade,
                    'is_pass' => (bool) $mark->is_pass
                ];
            })->toArray();
        } catch (\Exception $e) {
            // Log the error and return empty array to prevent breaking the response
            \Log::error('Error fetching exam subject marks: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            return [];
        }
    }

    /**
     * Get student's rank in the class for a specific exam
     * 
     * @param int $examId
     * @param int $classSectionId
     * @param int $studentId
     * @return string Rank (e.g. "1", "2", "N/A")
     */
    private function getStudentExamRank($examId, $classSectionId, $studentId)
    {
        // Get all students' total marks for this exam in this class
        $results = $this->examResult->builder()
            ->select(
                'student_id',
                DB::raw('SUM(obtained_marks) as total_marks')
            )
            ->where('exam_id', $examId)
            ->where('class_section_id', $classSectionId)
            ->where('school_id', Auth::user()->school_id)
            ->groupBy('student_id')
            ->orderByDesc('total_marks')
            ->get();

        if ($results->isEmpty()) {
            return 'N/A';
        }

        // Find the student's position
        $studentIndex = $results->search(function ($result) use ($studentId) {
            return $result->student_id == $studentId;
        });

        if ($studentIndex === false) {
            return 'N/A';
        }

        // Return position (1-based index)
        return ($studentIndex + 1);
    }

    /**
     * Format image URL correctly
     * 
     * @param string|null $imagePath
     * @return string
     */
    private function formatImageUrl($imagePath)
    {
        if (empty($imagePath)) {
            return url('images/default-user.png');
        }

        // Check if the image already contains a URL
        if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
            return $imagePath;
        }

        // Check if it's already prefixed with storage
        if (strpos($imagePath, 'storage/') === 0) {
            return url($imagePath);
        }

        // Standard path, prepend storage
        return url('storage/' . $imagePath);
    }

    /**
     * Get the division based on percentage
     * 
     * @param float $percentage
     * @return string|null
     */
    private function getDivisionByPercentage($percentage)
    {
        if ($percentage >= 75) {
            return 'First';
        } elseif ($percentage >= 60) {
            return 'Second';
        } elseif ($percentage >= 33) {
            return 'Third';
        }

        return null;
    }

    // get exam reports
    public function exam_reports()
    {
        ResponseService::noPermissionThenRedirect('reports-exam');
        $classes = $this->class->all(['*'], ['stream', 'medium', 'stream']);
        $subjects = $this->subject->builder()->orderBy('id', 'DESC')->get();
        $mediums = $this->mediums->builder()->pluck('name', 'id');

        $sessionYears = $this->sessionYear->all();

        $exams = $this->exam->builder()->where('publish', 1)->get();
        $classSections = $this->classSection->builder()->with('class', 'section', 'medium')->get();

        // total exam count
        $totalExamCount = $exams->count();

        return view('reports.exam.exam-reports', compact('classes', 'subjects', 'mediums', 'sessionYears', 'exams', 'classSections', 'totalExamCount'));
    }

    // get exam reports show
    public function exam_reports_show(Request $request)
    {
        // ResponseService::noFeatureThenRedirect('Reports Management');
        ResponseService::noPermissionThenRedirect('reports-exam');
        $class_sections = $this->classSection->all(['*'], ['class', 'class.stream', 'section', 'medium']);
        return view('reports.exam.exam-reports-show', compact('class_sections'));
    }

    // get exam reports view
    public function exam_view_reports($id)
    {
        // ResponseService::noFeatureThenRedirect('Reports Management');
        ResponseService::noPermissionThenRedirect('reports-exam');
        return view('reports.exam.exam-view-reports', compact('id'));
    }

    // get yearly result show
    public function yearlyResultShow(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Exam Management');
        ResponseService::noPermissionThenSendJson('exam-result');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');

        $sessionYears = request('session_year_id');
        $class_section_id = request('class_section_id');

        $sql = $this->students->builder()
            ->has('exam_result')
            ->with([
                'user:id,first_name,last_name,school_id',
                'user.exam_marks' => function ($q) {
                    $q->with(['timetable', 'subject']);
                }
            ])
            ->whereHas('exam_result.exam', function ($q) {
                $q->where('publish', 1);
            })
            ->whereHas('exam_result', function ($q) use ($sessionYears) {
                $q->where('session_year_id', $sessionYears);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->whereHas('user', function ($q) use ($search) {
                        $q->whereRaw("concat(first_name,' ',last_name) LIKE ?", ["%{$search}%"]);
                    });
                });
            })
            ->withSum('exam_result', 'obtained_marks')
            ->withSum('exam_result', 'total_marks');


        if ($class_section_id) {
            $sql = $sql->whereHas('exam_result', function ($q) use ($class_section_id) {
                $q->where('class_section_id', $class_section_id);
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
        $grades = $this->grade->builder()->orderBy('starting_range', 'ASC')->get();
        foreach ($res as $row) {
            // Calculate total marks across all exams
            $totalMarks = $row->exam_result_sum_total_marks;
            $obtainedMarks = $row->exam_result_sum_obtained_marks;

            // Calculate percentage
            $percentage = $totalMarks > 0 ? ($obtainedMarks / $totalMarks) * 100 : 0;

            // Calculate grade
            $grade = '';
            $grade = $this->generalService->getGradeByPercentage($percentage, $grades);

            $operate = '';
            if (Auth::user()->can('exam-result-edit')) {
                $operate .= BootstrapTableService::button(
                    'fa fa-file-pdf-o',
                    url('reports/exam/rank-wise-result/' . $row->user_id),
                    ['btn-gradient-info', 'btn-xs', 'btn-rounded', 'btn-icon'],
                    ['title' => __('view_result'), 'target' => '_blank']
                );
            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['total_marks'] = number_format($totalMarks, 2);
            $tempRow['obtained_marks'] = number_format($obtainedMarks, 2);
            $tempRow['percentage'] = number_format($percentage, 2);
            $tempRow['grade'] = $grade;
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function yearlyExamResultPdf($student_id)
    {
        ResponseService::noFeatureThenRedirect('Exam Management');
        ResponseService::noPermissionThenRedirect('exam-result');
        try {
            // get school settings
            $settings = $this->cache->getSchoolSettings();

            $schoolName = $settings['school_name'];
            $schoolLogo = $settings['horizontal_logo'];

            // get exams
            $exams = $this->exam->builder()
                ->with([
                    'timetable' => function ($q) {
                        $q->with('exam_marks');
                    }
                ])
                ->where('publish', 1)
                ->get();

            $results = $this->examResult->builder()->with([
                'exam',
                'session_year',
                'user' => function ($q) use ($student_id) {
                    $q->with([
                        'student' => function ($q) {
                            $q->with([
                                'guardian',
                                'class_section.class.stream',
                                'class_section.section',
                                'class_section.medium'
                            ]);
                        },
                        'exam_marks' => function ($q) use ($student_id) {
                            $q->whereHas('timetable', function ($q) use ($student_id) {
                                $q->where('student_id', $student_id);
                            })
                                ->with([
                                    'class_subject' => function ($q) {
                                        $q->withTrashed()->with('subject:id,name,type');
                                    },
                                    'timetable'
                                ]);
                        }
                    ]);
                }
            ])
                ->where('student_id', $student_id)
                ->select('exam_results.*')
                ->get();

            // Convert the results to a collection
            $results = collect($results);

            // Add rank calculation to each item in the collection
            $results = $results->map(function ($result) {
                $rank = DB::table('exam_results as er2')
                    ->where('er2.class_section_id', $result->class_section_id)
                    ->where('er2.obtained_marks', '>', $result->obtained_marks)
                    ->where('er2.exam_id', $result->exam_id)
                    ->where('er2.status', 1)
                    ->distinct('er2.obtained_marks')
                    ->count() + 1;

                $result->rank = $rank;
                return $result;
            });

            // Filter the collection based on student ID
            $result = $results->where('student_id', $student_id)->first();



            // $result->rank = $rank;

            // ====================================================================
            if (!$result) {
                return redirect()->back()->with('error', trans('no_records_found'));
            }

            $grades = $this->grade->builder()->orderBy('starting_range', 'ASC')->get();

            // get student attendance count
            $studentAttendanceCount = $this->attendance->builder()->where('student_id', $student_id)->where('type', 1)->where('session_year_id', $result->session_year_id)->count();

            $attendanceTotal = $this->attendance->builder()->where('student_id', $student_id)->where('type', 1)->where('session_year_id', $result->session_year_id)->count();


            return view('reports.exam.yearly-exam-result-pdf', compact('settings', 'result', 'grades', 'exams', 'studentAttendanceCount', 'attendanceTotal'));

        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function yearlyResultStatistics(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Exam Management');
        ResponseService::noPermissionThenSendJson('exam-result');
        $sessionYearId = $request->get('session_year_id');
        $classSectionId = $request->get('class_section_id');

        try {
            // Base query for exam results
            $query = $this->examResult->builder()
                ->where('session_year_id', $sessionYearId);

            // Filter by class section if provided
            if ($classSectionId) {
                $query->where('class_section_id', $classSectionId);
            }

            // Get all results for calculations
            $results = $query->get();

            // Calculate statistics
            $totalStudents = $results->count();
            $totalPass = $results->where('status', 1)->count();
            $totalFail = $results->where('status', 0)->count();
            $passPercentage = $totalStudents > 0 ? round(($totalPass / $totalStudents) * 100, 2) : 0;

            return response()->json([
                'success' => true,
                'total_students' => $totalStudents,
                'total_pass' => $totalPass,
                'total_fail' => $totalFail,
                'pass_percentage' => $passPercentage
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching yearly result statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching statistics',
                'total_students' => 0,
                'total_pass' => 0,
                'total_fail' => 0,
                'pass_percentage' => 0
            ]);
        }
    }

    public function subjectWiseResultShow(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Exam Management');
        ResponseService::noPermissionThenSendJson('exam-result');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');

        $sessionYearId = request('session_year_id');
        $classSectionId = request('class_section_id');
        $examId = request('exam_id');

        if (!$sessionYearId) {
            return response()->json([
                'total' => 0,
                'rows' => []
            ]);
        }

        // 2. Subquery: total obtained marks per student, with class_id and class_section_id
        $marksSub = DB::table('students')
            ->join('exam_results', function ($join) use ($sessionYearId, $examId) {
                $join->on('students.user_id', '=', 'exam_results.student_id')
                    ->where('exam_results.session_year_id', $sessionYearId);
                if ($examId) {
                    $join->where('exam_results.exam_id', $examId);
                }
            })
            ->join('class_sections', 'students.class_section_id', '=', 'class_sections.id')
            ->select(
                'students.user_id',
                'students.class_section_id',
                'class_sections.class_id',
                DB::raw('SUM(exam_results.obtained_marks) as total_obtained_marks')
            )
            ->groupBy('students.user_id', 'students.class_section_id', 'class_sections.class_id');

        // Fetch and sort the data for ranking
        $rankedData = DB::table(DB::raw("({$marksSub->toSql()}) as ranked"))
            ->mergeBindings($marksSub)
            ->orderBy('class_id')
            ->orderByDesc('total_obtained_marks')
            ->get();

        // Calculate ranks in PHP
        $classRanks = [];
        $sectionRanks = [];
        $lastClassMarks = [];
        $lastSectionMarks = [];
        $classRankValue = [];
        $sectionRankValue = [];

        foreach ($rankedData as $row) {
            // Class rank
            if (!isset($classRanks[$row->class_id])) {
                $classRanks[$row->class_id] = 1;
                $lastClassMarks[$row->class_id] = $row->total_obtained_marks;
                $classRankValue[$row->class_id] = 1;
            } else {
                if ($lastClassMarks[$row->class_id] != $row->total_obtained_marks) {
                    $classRankValue[$row->class_id]++;
                }
                $lastClassMarks[$row->class_id] = $row->total_obtained_marks;
            }
            $row->class_rank = $classRankValue[$row->class_id];

            // Section rank
            if (!isset($sectionRanks[$row->class_section_id])) {
                $sectionRanks[$row->class_section_id] = 1;
                $lastSectionMarks[$row->class_section_id] = $row->total_obtained_marks;
                $sectionRankValue[$row->class_section_id] = 1;
            } else {
                if ($lastSectionMarks[$row->class_section_id] != $row->total_obtained_marks) {
                    $sectionRankValue[$row->class_section_id]++;
                }
                $lastSectionMarks[$row->class_section_id] = $row->total_obtained_marks;
            }
            $row->section_rank = $sectionRankValue[$row->class_section_id];
        }

        // Now $rankedData contains class_rank and section_rank for each user_id
        $ranks = collect($rankedData)->keyBy('user_id');

        // 5. Main query: fetch students with marks (withSum as before)
        $sql = $this->students->builder()
            ->whereHas('exam_result.exam', function ($q) {
                $q->where('publish', 1);
            })
            ->with([
                'user:id,first_name,last_name,school_id',
                'user.exam_marks' => function ($q) {
                    $q->with([
                        'timetable.class_subject.subject:id,name,type,code',
                        'class_subject.subject:id,name,type,code'
                    ]);
                }
            ])
            ->whereHas('exam_result', function ($q) use ($sessionYearId) {
                $q->where('session_year_id', $sessionYearId);
            })
            ->when($classSectionId, function ($q) use ($classSectionId) {
                $q->whereHas('exam_result', function ($q) use ($classSectionId) {
                    $q->where('class_section_id', $classSectionId);
                });
            })
            ->when($examId, function ($q) use ($examId) {
                $q->whereHas('exam_result', function ($q) use ($examId) {
                    $q->where('exam_id', $examId);
                });
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->whereHas('user', function ($q) use ($search) {
                        $q->whereRaw("concat(first_name,' ',last_name) LIKE ?", ["%{$search}%"]);
                    });
                });
            });

        if ($examId) {
            $sql = $sql->withSum([
                'exam_result' => function ($q) use ($examId) {
                    $q->where('exam_id', $examId);
                }
            ], 'obtained_marks')
                ->withSum([
                    'exam_result' => function ($q) use ($examId) {
                        $q->where('exam_id', $examId);
                    }
                ], 'total_marks');
        } else {
            $sql = $sql->withSum('exam_result', 'obtained_marks')
                ->withSum('exam_result', 'total_marks');
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
        $grades = $this->grade->builder()->orderBy('starting_range', 'ASC')->get();

        foreach ($res as $row) {
            $totalMarks = $row->exam_result_sum_total_marks;
            $obtainedMarks = $row->exam_result_sum_obtained_marks;
            $percentage = $totalMarks > 0 ? ($obtainedMarks / $totalMarks) * 100 : 0;
            $grade = $this->generalService->getGradeByPercentage($percentage, $grades);

            // Lookup ranks
            $classRank = $ranks[$row->user_id]->class_rank ?? null;
            $sectionRank = $ranks[$row->user_id]->section_rank ?? null;

            $operate = '';
            if (Auth::user()->can('exam-result-edit')) {
                $operate .= BootstrapTableService::button(
                    'fa fa-file-pdf-o',
                    url('reports/exam/rank-wise-result/' . $row->user_id),
                    ['btn-gradient-info', 'btn-xs', 'btn-rounded', 'btn-icon'],
                    ['title' => __('view_result'), 'target' => '_blank']
                );
            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['total_marks'] = number_format($totalMarks, 2);
            $tempRow['obtained_marks'] = number_format($obtainedMarks, 2);
            $tempRow['percentage'] = number_format($percentage, 2);
            $tempRow['grade'] = $grade;
            $tempRow['class_rank'] = $classRank;
            $tempRow['section_rank'] = $sectionRank;
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function subjectWiseResultStatistics(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Exam Management');
        ResponseService::noPermissionThenSendJson('exam-result');
        $sessionYearId = $request->get('session_year_id');
        $classSectionId = $request->get('class_section_id');
        $subjectId = $request->get('subject_id');

        try {
            // Base query to get all subjects for the class section
            $query = DB::table('class_subjects')
                ->join('subjects', 'subjects.id', '=', 'class_subjects.subject_id')
                ->join('exam_timetables', 'exam_timetables.class_subject_id', '=', 'class_subjects.id')
                ->join('exam_marks', 'exam_marks.exam_timetable_id', '=', 'exam_timetables.id')
                ->join('exam_results', 'exam_results.student_id', '=', 'exam_marks.student_id')
                ->where('exam_results.session_year_id', $sessionYearId);

            // Apply class section filter if provided
            if ($classSectionId) {
                $query->where('class_subjects.class_section_id', $classSectionId);
            }

            // Apply subject filter if provided
            if ($subjectId) {
                $query->where('subjects.id', $subjectId);
            }

            // Get total unique subjects
            $totalSubjects = $query->distinct('subjects.id')->count('subjects.id');

            if ($totalSubjects == 0) {
                return response()->json([
                    'success' => true,
                    'total_subjects' => 0,
                    'subjects_passed' => 0,
                    'subjects_failed' => 0,
                    'pass_percentage' => 0
                ]);
            }

            // Get subject results with passing criteria
            $subjectResults = $query->select(
                'subjects.id as subject_id',
                'subjects.name as subject_name',
                'exam_marks.obtained_marks',
                'exam_timetables.passing_marks',
                'exam_timetables.total_marks'
            )->get();

            // Group results by subject
            $subjectStats = collect($subjectResults)->groupBy('subject_id')->map(function ($attempts) {
                $totalAttempts = $attempts->count();
                $passedAttempts = $attempts->filter(function ($attempt) {
                    return $attempt->obtained_marks >= $attempt->passing_marks;
                })->count();

                return [
                    'total_attempts' => $totalAttempts,
                    'passed_attempts' => $passedAttempts,
                    'is_pass' => ($passedAttempts / $totalAttempts) >= 0.33 // Consider subject passed if 33% or more students passed
                ];
            });

            // Calculate overall statistics
            $subjectsPassed = $subjectStats->filter(function ($stat) {
                return $stat['is_pass'];
            })->count();

            $subjectsFailed = $totalSubjects - $subjectsPassed;

            // Calculate pass percentage - ensure it cannot exceed 100%
            $passPercentage = min(100, ($totalSubjects > 0) ? round(($subjectsPassed / $totalSubjects) * 100, 2) : 0);

            return response()->json([
                'success' => true,
                'total_subjects' => $totalSubjects,
                'subjects_passed' => $subjectsPassed,
                'subjects_failed' => $subjectsFailed,
                'pass_percentage' => $passPercentage
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching subject wise result statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching subject wise result statistics',
                'total_subjects' => 0,
                'subjects_passed' => 0,
                'subjects_failed' => 0,
                'pass_percentage' => 0
            ]);
        }
    }

    public function subjectWiseResultPdf($student_id)
    {
        ResponseService::noFeatureThenRedirect('Exam Management');
        ResponseService::noPermissionThenRedirect('exam-result');
        try {
            // get school settings
            $settings = $this->cache->getSchoolSettings();

            $schoolName = $settings['school_name'];
            $schoolLogo = $settings['horizontal_logo'];

            // get exams
            $exams = $this->exam->builder()
                ->with([
                    'timetable' => function ($q) {
                        $q->with('exam_marks');
                    }
                ])
                ->where('publish', 1)
                ->get();

            $results = $this->examResult->builder()->with([
                'exam',
                'session_year',
                'user' => function ($q) use ($student_id) {
                    $q->with([
                        'student' => function ($q) {
                            $q->with([
                                'guardian',
                                'class_section.class.stream',
                                'class_section.section',
                                'class_section.medium'
                            ]);
                        },
                        'exam_marks' => function ($q) use ($student_id) {
                            $q->whereHas('timetable', function ($q) use ($student_id) {
                                $q->where('student_id', $student_id);
                            })
                                ->with([
                                    'class_subject' => function ($q) {
                                        $q->withTrashed()->with('subject:id,name,type');
                                    },
                                    'timetable'
                                ]);
                        }
                    ]);
                }
            ])
                ->where('student_id', $student_id)
                ->select('exam_results.*')
                ->get();

            // Convert the results to a collection
            $results = collect($results);

            // Add rank calculation to each item in the collection
            $results = $results->map(function ($result) {
                $rank = DB::table('exam_results as er2')
                    ->where('er2.class_section_id', $result->class_section_id)
                    ->where('er2.obtained_marks', '>', $result->obtained_marks)
                    ->where('er2.exam_id', $result->exam_id)
                    ->where('er2.status', 1)
                    ->distinct('er2.obtained_marks')
                    ->count() + 1;

                $result->rank = $rank;
                return $result;
            });

            // Filter the collection based on student ID
            $result = $results->where('student_id', $student_id)->first();

            // ====================================================================
            if (!$result) {
                return redirect()->back()->with('error', trans('no_records_found'));
            }

            $grades = $this->grade->builder()->orderBy('starting_range', 'ASC')->get();

            // get student attendance count
            $studentAttendanceCount = $this->attendance->builder()->where('student_id', $student_id)->where('type', 1)->where('session_year_id', $result->session_year_id)->count();

            $attendanceTotal = $this->attendance->builder()->where('student_id', $student_id)->where('type', 1)->where('session_year_id', $result->session_year_id)->count();

            // dd($exams->toArray());
            return view('reports.exam.subject-wise-exam-result-pdf', compact('settings', 'result', 'grades', 'exams', 'studentAttendanceCount', 'attendanceTotal'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function rankWiseResultShow(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Exam Management');
        ResponseService::noPermissionThenSendJson('exam-result');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'percentage');
        $order = request('order', 'DESC');
        $search = request('search');

        $sessionYearId = request('session_year_id');
        $classSectionId = request('class_section_id');
        $subjectId = request('subject_id');

        if (!$sessionYearId) {
            return response()->json([
                'total' => 0,
                'rows' => []
            ]);
        }

        // 1. Build the base query for all students (with rank)
        $baseQuery = $this->examResult->builder()
            ->whereHas('exam', function ($q) {
                $q->where('publish', 1);
            })
            ->where('session_year_id', $sessionYearId)
            ->when($classSectionId, function ($q) use ($classSectionId) {
                $q->where('class_section_id', $classSectionId);
            })
            ->when($subjectId, function ($q) use ($subjectId) {
                $q->whereHas('user.exam_marks', function ($q) use ($subjectId) {
                    $q->whereHas('class_subject', function ($q) use ($subjectId) {
                        $q->where('subject_id', $subjectId);
                    });
                });
            })
            ->select([
                'exam_results.*',
                DB::raw("RANK() OVER (ORDER BY obtained_marks DESC) AS rank")
            ])->with('user');

        // 2. Use the base query as a subquery
        $rankedSub = DB::table(DB::raw("({$baseQuery->toSql()}) as ranked"))
            ->mergeBindings($baseQuery->getQuery());

        // 3. Apply search and pagination on the outer query
        if ($search) {
            $rankedSub->where(function ($q) use ($search) {
                $q->where('id', 'LIKE', "%$search%")
                    ->orWhere('total_marks', 'LIKE', "%$search%")
                    ->orWhere('grade', 'LIKE', "%$search%")
                    ->orWhere('obtained_marks', 'LIKE', "%$search%")
                    ->orWhere('percentage', 'LIKE', "%$search%")
                    ->orWhere('rank', 'LIKE', "%$search%")
                    ->orWhereExists(function ($sub) use ($search) {
                        $sub->select(DB::raw(1))
                            ->from('users')
                            ->whereRaw('users.id = ranked.student_id')
                            ->where(function ($q) use ($search) {
                                $q->where('users.first_name', 'LIKE', "%$search%")
                                    ->orWhere('users.last_name', 'LIKE', "%$search%");
                            });
                    });
            });
        }

        $total = $rankedSub->count();
        if ($offset >= $total && $total > 0) {
            $lastPage = floor(($total - 1) / $limit) * $limit; // calculate last page offset
            $offset = $lastPage;
        }
        $results = $rankedSub
            ->orderBy($sort, $order)
            ->skip($offset)
            ->take($limit)
            ->get();

        // Hydrate Eloquent models for relationships
        $examResultIds = $results->pluck('id')->toArray();
        $examResults = $this->examResult->builder()
            ->with([
                'user:id,first_name,last_name,school_id',
                'user.exam_marks' => function ($q) use ($subjectId) {
                    $q->with([
                        'timetable.class_subject.subject:id,name,type,code',
                        'class_subject.subject:id,name,type,code'
                    ]);
                    if ($subjectId) {
                        $q->whereHas('class_subject', function ($q) use ($subjectId) {
                            $q->where('subject_id', $subjectId);
                        });
                    }
                }
            ])
            ->whereIn('id', $examResultIds)
            ->get()
            ->keyBy('id');

        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;
        $grades = $this->grade->builder()->orderBy('starting_range', 'ASC')->get();

        foreach ($results as $row) {
            $eloRow = $examResults[$row->id] ?? null;
            if (!$eloRow)
                continue;
            // Calculate marks and percentage based on subject filter
            if ($subjectId) {
                $examMarks = collect($eloRow->user->exam_marks)->filter(function ($mark) use ($subjectId) {
                    return $mark->class_subject->subject_id == $subjectId;
                });
                $totalMarks = $examMarks->sum(function ($mark) {
                    return $mark->timetable->total_marks;
                });
                $obtainedMarks = $examMarks->sum('obtained_marks');
                $percentage = $totalMarks > 0 ? ($obtainedMarks / $totalMarks) * 100 : 0;
            } else {
                $totalMarks = $eloRow->total_marks;
                $obtainedMarks = $eloRow->obtained_marks;
                $percentage = $eloRow->percentage;
            }
            // Calculate grade
            $grade = $this->generalService->getGradeByPercentage($percentage, $grades);
            // Add PDF view button if user has permission
            $operate = '';
            if (Auth::user()->can('exam-result-edit')) {
                $operate .= BootstrapTableService::button(
                    'fa fa-file-pdf-o',
                    url('reports/exam/rank-wise-result/' . $eloRow->student_id),
                    ['btn-gradient-info', 'btn-xs', 'btn-rounded', 'btn-icon'],
                    ['title' => __('view_result'), 'target' => '_blank']
                );
            }
            $tempRow = $eloRow->toArray();
            $tempRow['no'] = $no++;
            $tempRow['total_marks'] = number_format($totalMarks, 2);
            $tempRow['obtained_marks'] = number_format($obtainedMarks, 2);
            $tempRow['percentage'] = number_format($percentage, 2);
            $tempRow['grade'] = $grade;
            $tempRow['rank'] = $row->rank ?? '-';
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function rankWiseResultStatistics(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Exam Management');
        ResponseService::noPermissionThenSendJson('exam-result');
        $sessionYearId = $request->get('session_year_id');
        $classSectionId = $request->get('class_section_id');
        $subjectId = $request->get('subject_id');

        try {
            // Base query for exam marks
            $query = DB::table('exam_marks')
                ->join('exam_timetables', 'exam_timetables.id', '=', 'exam_marks.exam_timetable_id')
                ->join('class_subjects', 'class_subjects.id', '=', 'exam_timetables.class_subject_id')
                ->join('exam_results', 'exam_results.student_id', '=', 'exam_marks.student_id')
                ->where('exam_results.session_year_id', $sessionYearId);

            // Apply class section filter if provided
            if ($classSectionId) {
                $query->where('exam_results.class_section_id', $classSectionId);
            }

            // Apply subject filter if provided
            if ($subjectId) {
                $query->where('class_subjects.subject_id', $subjectId);
            }

            // Get total unique students
            $totalStudents = $query->distinct('exam_marks.student_id')->count('exam_marks.student_id');

            if ($totalStudents == 0) {
                return response()->json([
                    'success' => true,
                    'total_students' => 0,
                    'total_pass' => 0,
                    'total_fail' => 0,
                    'pass_percentage' => 0
                ]);
            }

            // Get passing marks and total marks from timetable
            $results = $query->select(
                'exam_marks.student_id',
                'exam_marks.obtained_marks',
                'exam_timetables.passing_marks',
                'exam_timetables.total_marks'
            )->get();

            // Group results by student for subject-specific calculations
            $studentResults = collect($results)->groupBy('student_id')->map(function ($marks) {
                $totalMarks = $marks->sum('total_marks');
                $obtainedMarks = $marks->sum('obtained_marks');
                $passingMarks = $marks->sum('passing_marks');

                return [
                    'total_marks' => $totalMarks,
                    'obtained_marks' => $obtainedMarks,
                    'passing_marks' => $passingMarks,
                    'is_pass' => $obtainedMarks >= $passingMarks
                ];
            });

            $totalPass = $studentResults->where('is_pass', true)->count();
            $totalFail = $studentResults->where('is_pass', false)->count();

            // Calculate pass percentage - ensure it cannot exceed 100%
            $passPercentage = min(100, ($totalStudents > 0 ? round(($totalPass / $totalStudents) * 100, 2) : 0));

            return response()->json([
                'success' => true,
                'total_students' => $totalStudents,
                'total_pass' => $totalPass,
                'total_fail' => $totalFail,
                'pass_percentage' => $passPercentage
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching rank wise result statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching rank wise result statistics',
                'total_students' => 0,
                'total_pass' => 0,
                'total_fail' => 0,
                'pass_percentage' => 0
            ]);
        }
    }

    public function rankWiseResultPdf($student_id)
    {
        ResponseService::noFeatureThenRedirect('Exam Management');
        ResponseService::noPermissionThenRedirect('exam-result');
        try {
            // get school settings
            $settings = $this->cache->getSchoolSettings();

            $schoolName = $settings['school_name'];
            $schoolLogo = $settings['horizontal_logo'];

            // get exams
            $exams = $this->exam->builder()
                ->with([
                    'timetable' => function ($q) {
                        $q->with('exam_marks');
                    }
                ])
                ->where('publish', 1)
                ->get();

            $results = $this->examResult->builder()
                ->with([
                    'exam',
                    'session_year',
                    'class_section.class.stream',
                    'class_section.section',
                    'class_section.medium',

                    'user' => function ($q) use ($student_id) {
                        $q->with([
                            'student.guardian',
                            'exam_marks' => function ($q) use ($student_id) {
                                $q->whereHas('timetable', function ($q) use ($student_id) {
                                    $q->where('student_id', $student_id);
                                })
                                    ->with([
                                        'class_subject' => function ($q) {
                                            $q->withTrashed()->with('subject:id,name,type');
                                        },
                                        'timetable'
                                    ])
                                    ->withSum('timetable', 'total_marks');
                            }
                        ]);
                    }
                ])
                ->where('student_id', $student_id)
                ->select('exam_results.*')
                ->get();
            // return $results->sum('total_marks');
            // Convert the results to a collection
            $results = collect($results);

            // Add rank calculation to each item in the collection
            $results = $results->map(function ($result) {
                $rank = DB::table('exam_results as er2')
                    ->where('er2.class_section_id', $result->class_section_id)
                    ->where('er2.obtained_marks', '>', $result->obtained_marks)
                    ->where('er2.exam_id', $result->exam_id)
                    ->where('er2.status', 1)
                    ->distinct('er2.obtained_marks')
                    ->count() + 1;

                $result->rank = $rank;
                return $result;
            });

            // Filter the collection based on student ID
            $result = $results->where('student_id', $student_id)->first();

            // ====================================================================
            if (!$result) {
                return redirect()->back()->with('error', trans('no_records_found'));
            }

            $grades = $this->grade->builder()->orderBy('starting_range', 'ASC')->get();

            // get student attendance count
            $studentAttendanceCount = $this->attendance->builder()->where('student_id', $student_id)->where('type', 1)->where('session_year_id', $result->session_year_id)->count();

            $attendanceTotal = $this->attendance->builder()->where('student_id', $student_id)->where('session_year_id', $result->session_year_id)->count();


            return view('reports.exam.rank-wise-exam-result-pdf', compact('settings', 'result', 'grades', 'exams', 'studentAttendanceCount', 'attendanceTotal', 'results'));
        } catch (\Exception $e) {
            return $e;
            return redirect()->back()->with('error', $e->getMessage());
        }

        return response()->json($results);

    }

    public function expenseReport()
    {
        ResponseService::noAnyPermissionThenRedirect(['reports-expense']);

        $expenseCategory = $this->expenseCategory->builder()->pluck('name', 'id')->toArray();
        $sessionYear = $this->sessionYear->builder()->pluck('name', 'id');
        $current_session_year = app(CachingService::class)->getDefaultSessionYear();
        $vehicles = Vehicle::where('status', 1)->get(['name', 'id', 'vehicle_number']);
        $months = sessionYearWiseMonth();
        return view('reports.expense.index', compact('expenseCategory', 'sessionYear', 'current_session_year', 'vehicles', 'months'));
    }

    public function expenseReportShow()
    {
        ResponseService::noPermissionThenRedirect('transportationexpense-list');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'date');
        $order = request('order', 'DESC');
        $search = request('search');
        $category_id = request('category_id');
        $vehicle_id = request('vehicle_id');
        $month = request('month');

        $sql = $this->expense->builder()->with('category', 'vehicle', 'created_by')->where(function ($query) use ($search) {
            $query->when($search, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'LIKE', "%$search%")->orWhere('ref_no', 'LIKE', "%$search%")->orWhere('amount', 'LIKE', "%$search%")->orWhere('date', 'LIKE', "%$search%")->orWhere('description', 'LIKE', "%$search%")->orWhereHas('category', function ($q) use ($search) {
                        $q->Where('name', 'LIKE', "%$search%");
                    });
                });
            });
        });

        if ($category_id) {
            if ($category_id != 'salary' && $category_id != 'transportation') {
                $sql->where('category_id', $category_id)->whereNull('staff_id');
            } else if ($category_id == 'transportation') {
                $sql->whereNotNull('vehicle_id');
            } else {
                $sql->whereNotNull('staff_id');

            }
        }

        if ($vehicle_id) {
            $sql->where('vehicle_id', $vehicle_id);
        }

        if ($month) {
            $sql->whereMonth('date', $month);
        }

        $total = $sql->get()->count();
        if ($offset >= $total && $total > 0) {
            $lastPage = floor(($total - 1) / $limit) * $limit; // calculate last page offset
            $offset = $lastPage;
        }
        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();
        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = $offset + 1;

        foreach ($res as $row) {
            $operate = '';
            if (!$row->month) {
                $operate .= BootstrapTableService::editButton(route('transportation-expense.update', $row->id));
                $operate .= BootstrapTableService::deleteButton(route('expense.destroy', $row->id));
            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['amount'] = $row->amount;
            if (isset($row->vehicle->name) && isset($row->vehicle->vehicle_number)) {
                $tempRow['vehicle'] = $row->vehicle->name . " (" . $row->vehicle->vehicle_number . ")";

                $fileUrl = $row->file ?? null;
                $fileExtension = '';
                if (!empty($fileUrl)) {
                    $fileExtension = strtolower(pathinfo($fileUrl, PATHINFO_EXTENSION));
                }
                $previewHtml = '';
                if ($fileExtension) {
                    if (in_array($fileExtension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'])) {
                        $previewHtml = '
                    <a href="' . $fileUrl . '" target="_blank" class="btn btn-sm btn-outline-info w-100 mt-2">
                        View Image
                    </a>';
                    } elseif ($fileExtension === 'pdf') {
                        $previewHtml = '
                    <a href="' . $fileUrl . '" target="_blank" class="btn btn-sm btn-outline-info w-100 mt-2">
                        View PDF
                    </a>';
                    } else {
                        $previewHtml = '<span class="text-danger">Unsupported file type</span>';
                    }
                }
                $tempRow['file'] = $previewHtml;
            }
            $tempRow['date'] = date("d-m-Y", strtotime($row->date));
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function teacher_reports()
    {
        ResponseService::noPermissionThenRedirect('reports-teacher');

        $class_sections = $this->classSection->all(['*'], ['class', 'class.stream', 'section', 'medium']);

        if (Auth::user()->school_id) {
            $extraFields = $this->formFields->defaultModel()->where('user_type', 2)->orderBy('rank')->get();
        } else {
            $extraFields = $this->formFields->defaultModel()->orderBy('rank')->get();
        }

        $sessionYears = $this->sessionYear->all();

        return view('reports.teacher.teacher-reports', compact('class_sections', 'extraFields', 'sessionYears'));
    }

    public function teacher_reports_show(Request $request)
    {
        ResponseService::noPermissionThenRedirect('reports-teacher');

        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'ASC');
        $search = request('search');

        // Main student query
        $sql = $this->user->builder()
            ->role('Teacher')
            ->with('staff', 'staff.staffSalary', 'extra_student_details.form_field')
            ->where(function ($query) use ($search) {

                $query->when($search, function ($query) use ($search) {
                    $query->where('id', 'LIKE', "%{$search}%")
                        ->orWhere('first_name', 'LIKE', "%{$search}%")
                        ->orWhere('last_name', 'LIKE', "%{$search}%")
                        ->orWhere(DB::raw("CONCAT(first_name,' ',last_name)"), 'LIKE', "%{$search}%")  // FIXED
                        ->orWhere('gender', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%")
                        ->orWhere('current_address', 'LIKE', "%{$search}%")
                        ->orWhere('permanent_address', 'LIKE', "%{$search}%");
                });
                // staff conditions in SAME group, not separate
                $query->orWhereHas('staff', function ($q) use ($search) {
                    $q->where('staffs.qualification', 'LIKE', "%{$search}%");
                });
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
            $userId = $row->user_id;

            $operate = BootstrapTableService::viewButton(
                route('reports.teacher.teacher-view-reports', [$row->id]),
                [],
                [
                    'title' => trans('View Teacher Details'),
                    'target' => ''
                ]
            );

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['dob_org'] = $row->getRawOriginal('dob');
            $tempRow['joining_date_org'] = $row->staff->getRawOriginal('joining_date');
            $tempRow['extra_fields'] = $row->extra_student_details;

            foreach ($row->extra_student_details as $field) {
                $data = '';
                if ($field->form_field->type == 'checkbox') {
                    $data = json_decode($field->data);
                } elseif ($field->form_field->type == 'file') {
                    $data = '<a href="' . Storage::url($field->data) . '" target="_blank">DOC</a>';
                } elseif ($field->form_field->type == 'dropdown') {
                    $data = $field->data ?? '';
                } else {
                    $data = $field->data;
                }

                $tempRow[$field->form_field->name] = $data;
            }
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function teacher_view_reports($id)
    {
        ResponseService::noPermissionThenRedirect('reports-teacher');
        $schoolSettings = $this->cache->getSchoolSettings();
        $teacher = $this->user->builder()
            ->where('id', $id)
            ->with([
                'staff',
                'staff.staffSalary',
                'extra_student_details.form_field',
                'session_year:id,name',

                'staff.subjects:id,class_section_id,teacher_id,subject_id',
                'staff.subjects.subject:id,name,type',

                'staff.class_teacher:id,class_section_id,teacher_id',
            ])
            ->first();

        if (!$teacher || !$teacher->staff) {
            return back()->with('error', 'Teacher not found');
        }

        $classSectionIds = collect([
            ...$teacher->staff->subjects->pluck('class_section_id')->toArray(),
            ...$teacher->staff->class_teacher->pluck('class_section_id')->toArray(),
        ])
            ->unique()
            ->values();

        $classSections = ClassSection::whereIn('id', $classSectionIds)
            ->with([
                'class',
                'class.stream',
                'section',
                'medium',
            ])
            ->get()
            ->keyBy('id');
        $teacher->staff->subjects->each(function ($item) use ($classSections) {
            $item->setRelation(
                'class_section',
                $classSections[$item->class_section_id] ?? null
            );
        });
        $teacher->staff->class_teacher->each(function ($item) use ($classSections) {
            $item->setRelation(
                'class_section',
                $classSections[$item->class_section_id] ?? null
            );
        });

        $transportation = null;

        $transportationPayments = TransportationPayment::with(['transportationFee', 'paymentTransaction', 'pickupPoint', 'routeVehicle', 'shift'])
            ->where('user_id', $id)
            ->where('status', 'paid')
            ->orderBy('id', 'desc')
            ->first();

        if ($transportationPayments) {
            $routePickupPoint = null;

            if ($transportationPayments->pickupPoint && $transportationPayments->routeVehicle?->route) {
                $routePickupPoint = RoutePickupPoint::where('pickup_point_id', $transportationPayments->pickupPoint->id)
                    ->where('route_id', $transportationPayments->routeVehicle->route->id)
                    ->first();
            }

            $transportation = [
                'plan' => [
                    'plan_status' => ($transportationPayments->expiry_date && $transportationPayments->expiry_date > date('Y-m-d')) ? 'Active' : 'Expired',
                    'vehicle_assignment' => ($transportationPayments->routeVehicle) ? 'Assigned' : 'Pending',
                    'expiry_date' => $transportationPayments->expiry_date,
                    'paid_amount' => format_money($transportationPayments->amount),
                    'payment_mode' => $transportationPayments->paymentTransaction->payment_gateway ?? null,
                ],
                'shift' => [
                    'name' => isset($transportationPayments->shift->name) ? $transportationPayments->shift->name : null,
                    'start_time' => isset($transportationPayments->shift->start_time) ? Carbon::parse($transportationPayments->shift->start_time)->format($schoolSettings['time_format']) : null,
                    'end_time' => isset($transportationPayments->shift->end_time) ? Carbon::parse($transportationPayments->shift->end_time)->format($schoolSettings['time_format']) : null,
                ],
                'fee' => [
                    'duration' => optional($transportationPayments->transportationFee)->duration ?? null,
                    'amount' => format_money(optional($transportationPayments->transportationFee)->fee_amount ?? null),
                ],
                'route' => [
                    'route_name' => $transportationPayments->routeVehicle->route->name ?? null,
                    'pickup_point_name' => $transportationPayments->pickupPoint->name ?? null,
                    'pickup_time' => $routePickupPoint?->pickup_time
                        ? Carbon::parse($routePickupPoint->pickup_time)->format($schoolSettings['time_format'])
                        : null,
                    'drop_time' => $routePickupPoint?->drop_time
                        ? Carbon::parse($routePickupPoint->drop_time)->format($schoolSettings['time_format'])
                        : null,
                ],
                'vehicle' => [
                    'name' => $transportationPayments->routeVehicle->vehicle->name ?? null,
                    'number' => $transportationPayments->routeVehicle->vehicle->vehicle_number ?? null,
                    'capacity' => $transportationPayments->routeVehicle->vehicle->capacity ?? null,
                    'driver' => $transportationPayments->routeVehicle->driver ?? null,
                    'helper' => $transportationPayments->routeVehicle->helper ?? null
                ],
            ];
        }
        $teacherID = $teacher->id;
        $timetables = $this->timetable->builder()->whereHas('subject_teacher', function ($q) use ($teacherID) {
            $q->where('teacher_id', $teacherID);
        })->with('subject:id,name,bg_color', 'class_section.class', 'class_section.section', 'class_section.medium')->get();

        // Get Timetable Settings Data
        $timetableSettingsData = $this->schoolSettings->getBulkData([
            'timetable_start_time',
            'timetable_end_time',
            'timetable_duration'
        ]);

        $salaries = StaffSalary::where('staff_id', $teacher->staff->id)
            ->with('payrollSetting')
            ->get();

        $basic_salary = $teacher->staff->salary;

        $total_allowance = 0;
        $total_deduction = 0;

        $allowance_details = [];
        $deduction_details = [];

        foreach ($salaries as $salary) {

            $setting = $salary->payrollSetting;
            if (!$setting)
                continue;

            // Calculate value (amount or percentage)
            $value = $salary->amount ?? 0;

            if ($setting->percentage) {
                $value = ($basic_salary * $setting->percentage) / 100;
            }

            if ($setting->type === 'allowance') {

                $total_allowance += $value;

                $allowance_details[] = [
                    'name' => $setting->name,
                    'amount' => $value,
                    'type' => 'Allowance',
                ];

            } elseif ($setting->type === 'deduction') {

                $total_deduction += $value;

                $deduction_details[] = [
                    'name' => $setting->name,
                    'amount' => $value,
                    'type' => 'Deduction',
                ];
            }
        }

        $net_salary = $basic_salary + $total_allowance - $total_deduction;

        $salary_structure = [
            'basic_salary' => $basic_salary,
            'total_allowance' => $total_allowance,
            'total_deduction' => $total_deduction,
            'net_salary' => $net_salary,

            'allowances' => $allowance_details,
            'deductions' => $deduction_details,
        ];

        $leaves = Leave::where('user_id', $teacher->id)
            ->where('status', 1)
            ->whereYear('from_date', date('Y'))
            ->with('leave_detail')
            ->get();

        // Get all session years for exam tab
        $sessionYears = $this->sessionYear->all();
        $sessionYear = $this->cache->getDefaultSessionYear();

        return view('reports.teacher.teacher-view-reports', compact('teacher', 'transportation', 'timetables', 'timetableSettingsData', 'salary_structure', 'sessionYear'));
    }

    public function getTeacherAttendanceReport(Request $request)
    {
        ResponseService::noPermissionThenRedirect('reports-teacher');
        // Validate request parameters
        $request->validate([
            'month' => 'required|numeric|between:1,12',
            'teacher_id' => 'required|exists:users,id',
        ]);

        // Get current session year
        $sessionYear = $this->cache->getDefaultSessionYear();
        $schoolSettings = $this->cache->getSchoolSettings();
        $year = date('Y');
        // Create a Carbon date for the first day of the month
        $startDate = Carbon::createFromDate($year, $request->month, 1)->startOfMonth();
        $endDate = Carbon::createFromDate($year, $request->month, 1)->endOfMonth();

        // Get attendance records for this student in the specified month
        $attendanceRecords = StaffAttendance::where('staff_id', $request->teacher_id)
            ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->get();

        $leaves = Leave::where('user_id', $request->teacher_id)->with('leave_detail')
            ->where('status', 1)
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('from_date', [$startDate, $endDate])
                    ->orWhereBetween('to_date', [$startDate, $endDate])
                    ->orWhere(function ($sub) use ($startDate, $endDate) {
                        $sub->where('from_date', '<=', $startDate)
                            ->where('to_date', '>=', $endDate);
                    });
            })
            ->whereHas('leave_detail', function ($q) {
                $q->where('type', 'Full');
            })
            ->get();
        // Convert leaves to a Y-m-d array for fast lookup
        $leaveDays = [];
        $finalFormat = $schoolSettings['date_format'] . ' ' . $schoolSettings['time_format'];
        foreach ($leaves as $leave) {
            // $from = Carbon::createFromFormat($finalFormat, $leave->from_date)->startOfDay();
            // $to = Carbon::createFromFormat($finalFormat, $leave->to_date)->startOfDay();

            // $period = $from->daysUntil($to->copy()->addDay());

            foreach ($leave->leave_detail as $leaveDetail) {
                $leaveDays[$leaveDetail->date] = true;
            }
        }

        // handle holiday attendance
        $holidayAttendance = $this->holiday->builder()
            ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->get()
            ->map(function ($h) {
                return Carbon::parse($h->dmyFormat)->format('Y-m-d');
            });

        $leaveMaster = LeaveMaster::where('session_year_id', $sessionYear->id)->first();
        $holiday_days = $leaveMaster && $leaveMaster->holiday
            ? explode(',', $leaveMaster->holiday)
            : [];
        if ($leaveMaster) {
            $period = Carbon::parse($startDate)->daysUntil(Carbon::parse($endDate)->addDay());

            foreach ($period as $day) {
                if (in_array($day->format('l'), $holiday_days)) {
                    $holidayAttendance->push($day->format('Y-m-d'));
                }
            }
        }


        // Count present, absent and holiday days
        $presentCount = $attendanceRecords->where('type', 1)->count();
        $absentCount = $attendanceRecords->where('type', 0)->count();
        $holidayCount = $attendanceRecords->where('type', 3)->count();
        $holidayCount += $holidayAttendance->count();
        $halfCount = $attendanceRecords->where('type', 4)->count();
        $halfCount += $attendanceRecords->where('type', 5)->count();

        // Calculate attendance percentage
        $totalDays = $presentCount + $absentCount;
        $attendancePercentage = $totalDays > 0 ? round(($presentCount / $totalDays) * 100) : 0;

        // Prepare the response data
        $responseData = [
            'success' => true,
            'attendance' => $attendanceRecords,
            'leaves' => $leaveDays,
            'holiday' => $holidayAttendance,
            'summary' => [
                'present_count' => $presentCount,
                'absent_count' => $absentCount,
                'holiday_count' => $holidayCount,
                'half_count' => $halfCount,
                'attendance_percentage' => $attendancePercentage,
                'total_days' => $totalDays
            ]
        ];

        return response()->json($responseData);
    }

    public function teacherLeaves(Request $request)
    {
        ResponseService::noPermissionThenRedirect('reports-teacher');
        $teacherId = $request->teacher_id;
        $month = (int) $request->month;
        $year = (int) $request->year;

        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = Carbon::create($year, $month, 1)->endOfMonth();

        // STEP 1 — Get leaves overlapping the month
        $leaves = Leave::where('user_id', $teacherId)
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('from_date', [$startDate, $endDate])
                    ->orWhereBetween('to_date', [$startDate, $endDate])
                    ->orWhere(function ($q2) use ($startDate, $endDate) {
                        $q2->where('from_date', '<=', $startDate)
                            ->where('to_date', '>=', $endDate);
                    });
            })
            ->with(['leave_detail']) // load leave type if needed
            ->orderBy('from_date', 'ASC')
            ->get();

        // STEP 2 — Transform for frontend
        $final = [];

        foreach ($leaves as $leave) {

            // Filter leave_detail rows belonging to selected month
            $details = $leave->leave_detail
                ->filter(
                    fn($d) =>
                    Carbon::parse($d->date)->month == $month &&
                    Carbon::parse($d->date)->year == $year
                )
                ->map(fn($d) => [
                    'date' => $d->date,
                    'date_formatted' => Carbon::parse($d->date)->format('d M Y'),
                    'type' => $d->type,    // Full, First Half, Second Half
                ])
                ->values();

            foreach ($details as $day) {
                $final[] = [
                    'leave_id' => $leave->id,
                    'status' => $leave->status,
                    'date' => $day['date'],
                    'date_formatted' => $day['date_formatted'],
                    'type' => $day['type'],
                    'reason' => $leave->reason,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'total' => count($final),
            'leaves' => $final,
        ]);
    }
}
