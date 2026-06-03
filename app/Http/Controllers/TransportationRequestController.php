<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TransportationPayment;
use App\Models\Vehicle;
use App\Models\Shift;
use App\Models\PickupPoint;
use App\Models\RouteVehicle;
use App\Models\TransportationFee;
use App\Models\PaymentTransaction;
use App\Models\StaffSalary;
use App\Models\Staff;
use App\Models\Students;
use App\Models\PayrollSetting;
use App\Repositories\User\UserInterface;
use App\Services\ResponseService;
use App\Services\BootstrapTableService;
use Throwable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Auth;
use App\Services\CachingService;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Repositories\ClassSection\ClassSectionInterface;

class TransportationRequestController extends Controller
{
    private UserInterface $user;
    private CachingService $cache;
    private ClassSectionInterface $classSection;
    public function __construct(UserInterface $user, CachingService $cache, ClassSectionInterface $classSection, )
    {
        $this->user = $user;
        $this->cache = $cache;
        $this->classSection = $classSection;
    }
    public function index()
    {
        ResponseService::noAnyPermissionThenSendJson(['transportationRequests-list']);
        $transportationRequests = TransportationPayment::with(['user', 'pickupPoint', 'shift'])
            ->where('status', 'paid')
            ->get();

        return view('transportation-request.index', compact('transportationRequests'));
    }

    public function show()
    {
        ResponseService::noPermissionThenRedirect('transportationRequests-list');
        $today = now();
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'desc');
        $search = request('search');
        $showDeleted = request('show_deleted');
        $pickupPointId = request('pickup_point_id');
        $shiftId = request('shift_id');
        $includeAmount = request('include_amount');

        $sql = TransportationPayment::with([
            'user',
            'pickupPoint',
            'shift',
            'pickupPoint.routePickupPoints.route',
            'pickupPoint.routePickupPoints.route.routeVehicle',
            'pickupPoint.routePickupPoints.route.routeVehicle.vehicle',
            'transportationFee'
        ])->where('expiry_date', '>', $today)
            ->where('status', 'paid')->when(!empty($showDeleted), function ($query) {
                $query->whereNotNull('route_vehicle_id');
            })->when(empty($showDeleted), function ($query) {
                $query->whereNull('route_vehicle_id');
            })->when(!empty($pickupPointId), function ($query) use ($pickupPointId) {
                $query->where('pickup_point_id', $pickupPointId);
            })->when(!empty($shiftId), function ($query) use ($shiftId) {
                $query->where('shift_id', $shiftId);
            });

        if (!empty($search)) {
            $sql->where(function ($q) use ($search) {
                $q->orWhereHas('user', function ($d) use ($search) {
                    $d->where('first_name', 'LIKE', "%$search%")
                        ->orWhere('last_name', 'LIKE', "%$search%")
                        ->orWhere('email', 'LIKE', "%$search%")
                        ->orWhereRaw("concat(first_name,' ',last_name) LIKE ?", ["%{$search}%"]);
                });
                $q->orWhereHas('pickupPoint', function ($d) use ($search) {
                    $d->where('name', 'LIKE', "%$search%");
                });
                $q->orWhereHas('user.roles', function ($d) use ($search) {
                    if ($search == "Staff") {
                        $d->whereNot('name', "Student");
                        $d->whereNot('name', "Teacher");
                    } else {
                        $d->where('name', 'LIKE', "%$search%");
                    }
                });
            });
        }

        if (request('include_amount') !== null && request('include_amount') !== '') {
            $includeAmount = (int) $includeAmount;
            if ($includeAmount == 2) {
                $sql->whereNull('include_amount');
                $sql->where(function ($q) use ($search) {
                    $q->WhereHas('user.roles', function ($d) use ($search) {
                        $d->whereNot('name', "Student");
                    });
                });
            } else {
                $sql->where('include_amount', $includeAmount);
            }
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
        $no = $offset + 1;

        $baseUrl = url('/');
        $baseUrlWithoutScheme = preg_replace("(^https?://)", "", $baseUrl);
        $baseUrlWithoutScheme = str_replace("www.", "", $baseUrlWithoutScheme);

        foreach ($res as $row) {
            $operate = BootstrapTableService::editButton(route('transportation-requests.update', $row->id));
            if (!$row->user->hasrole('Student')) {
                $operate .= BootstrapTableService::button(
                    'fa fa-times',
                    route('transportation-requests.cancel', $row->id),
                    ['btn', 'btn-xs', 'btn-gradient-danger', 'btn-rounded', 'btn-icon', 'cancel-service'],
                    ['data-id' => $row->id, 'title' => trans('end_transportation_service')]
                );
            } else {
                $operate .= BootstrapTableService::button('fa fa-file-pdf-o', route('transportation-requests.fee-receipt', $row->id), ['btn', 'btn-xs', 'btn-gradient-info', 'btn-rounded', 'btn-icon', 'generate-paid-fees-pdf'], ['target' => "_blank", 'data-id' => $row->id, 'title' => trans('generate_pdf') . ' ' . trans('fees')]);
            }

            if ($row->user->hasrole('Student')) {
                $role = "Student";
            } elseif ($row->user->hasrole('Teacher')) {
                $role = "Teacher";
            } else {
                $role = 'Staff';
            }

            if (!$row->user->hasrole('Student')) {
                if ($row->include_amount == '0') {
                    $includeAmount = 'No';
                } elseif ($row->include_amount == '1') {
                    $includeAmount = 'Yes';
                } elseif ($row->include_amount == null) {
                    $includeAmount = 'Not Specified';
                }
            } else {
                $includeAmount = '';
            }

            $tempRow = $row->toArray();
            $tempRow['role'] = $role;
            $tempRow['no'] = $no++;
            $tempRow['include_amount'] = $includeAmount;
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function update(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson(['transportationRequests-edit']);

        $validator = Validator::make($request->all(), [
            'edit_route_id' => 'required|numeric|exists:route_vehicles,id',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();
            $requestData = [
                'route_vehicle_id' => $request->edit_route_id,
                'include_amount' => $request->include_amount,
            ];

            $transportationPayment = TransportationPayment::find($id);

            $sessionYear = $this->cache->getDefaultSessionYear();
            $today = now();
            $existingPayment = TransportationPayment::where('user_id', $transportationPayment->user_id)
                ->whereNotNull('route_vehicle_id')
                ->whereNot('id', $transportationPayment->id)
                ->where('session_year_id', $sessionYear->id)
                ->where('expiry_date', '>', $today)
                ->where('status', 'paid')
                ->first();

            if ($existingPayment) {
                ResponseService::errorResponse('This user already has an active paid record in the current session year.');
            }

            $routes = RouteVehicle::with('vehicle')->where('id', $request->edit_route_id)->first();

            $assignedCounts = TransportationPayment::selectRaw('route_vehicle_id, COUNT(*) as assigned_students')
                ->where('route_vehicle_id', $request->edit_route_id)
                ->where('status', 'paid')
                ->groupBy('route_vehicle_id')->first();

            if (!empty($routes) && !empty($assignedCounts) && ((int) $routes->vehicle->capacity - (int) $assignedCounts->assigned_students) == 0) {
                ResponseService::errorResponse("No seats left in this vehicle");
            }

            if (!$transportationPayment) {
                return redirect()->back()->with('error', 'Transportation payment not found.');
            }

            $dividedAmount = 0;
            if ($request->include_amount !== null && $request->include_amount == 1) {
                // Create StaffSalary record
                $startDate = $transportationPayment->created_at;

                // Calculate number of distinct months covered
                $months = $startDate->diffInMonths($transportationPayment->expiry_date) + 1;

                // Divide amount equally per month
                $dividedAmount = $months > 0
                    ? round(($transportationPayment->amount ?? 0) / $months, 2)
                    : ($transportationPayment->amount ?? 0);



                // StaffSalary::updateOrCreate(
                //     [
                //         'staff_id' => $staffId->id ?? null,
                //         'payroll_setting_id' => $payrollSetting->id ?? null,
                //     ],
                //     [
                //         'amount' => $dividedAmount ?? 0,
                //         'expiry_date' => $transportationPayment->expiry_date ?? null,
                //     ]
                // );
                // } elseif ($request->include_amount !== null && $request->include_amount == 0) {

                //     // StaffSalary::updateOrCreate(
                //     //     [
                //     //         'staff_id' => $staffId->id ?? null,
                //     //         'payroll_setting_id' => $payrollSetting->id ?? null,
                //     //     ],
                //     //     [
                //     //         'amount' => 0,
                //     //         'expiry_date' => $transportationPayment->expiry_date ?? null
                //     //     ]
                //     // );
                //     $requestData['included_amount'] = 0;
            }
            $requestData['included_amount'] = $dividedAmount;

            // Update attributes
            $transportationPayment->update($requestData);

            // $payrollSetting = PayrollSetting::where('name', 'Transportation Deduction')->first();
            // $staffId = Staff::where('user_id', $transportationPayment->user_id)->first();


            $title = "Transportation assigned";
            $body = "Your transportation has been assigned successfully.";
            $type = 'Transportation';

            $studentPayloads = [];
            $guardianPayloads = [];
            $staffPayloads = [];

            // Fetch user to determine if student or staff
            $userId = $transportationPayment->user_id;

            $student = Students::where('user_id', $userId)->first();

            if ($student) {

                // Student
                $studentPayloads = array_merge(
                    $studentPayloads,
                    buildPayloads([$userId], $title, $body, $type, ['user_id' => $userId])
                );

                // Guardian (if exists)
                if (!empty($student->guardian_id)) {
                    $guardianPayloads = array_merge(
                        $guardianPayloads,
                        buildPayloads([$student->guardian_id], $title, $body, $type, [
                            'guardian_id' => $student->guardian_id,
                            'child_id' => $student->id,
                            'user_id' => $student->user_id
                        ])
                    );
                }

            } else {
                // Staff user
                $staffPayloads = array_merge(
                    $staffPayloads,
                    buildPayloads([$userId], $title, $body, $type, ['user_id' => $userId])
                );
            }

            // Send notifications
            $studentGuardianPayloads = array_merge($studentPayloads, $guardianPayloads);

            if (!empty($studentGuardianPayloads)) {
                sendBulk($studentGuardianPayloads);
            }

            if (!empty($staffPayloads)) {
                sendBulk($staffPayloads);
            }

            DB::commit();
            ResponseService::successResponse('Data updated successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, 'TransportationRequestController -> update');
            ResponseService::errorResponse();
        }
    }

    public function cancelTransportationService($id)
    {
        ResponseService::noPermissionThenSendJson(['transportationRequests-edit']);

        try {
            DB::beginTransaction();
            $today = now();
            $transportationPayment = TransportationPayment::find($id);

            if (!$transportationPayment) {
                return redirect()->back()->with('error', 'Transportation payment not found.');
            }


            // Update attributes
            $transportationPayment->update(['expiry_date' => $today->format('Y-m-d')]);


            // $payrollSetting = PayrollSetting::where('name', 'Transportation Deduction')->first();
            // $staffId = Staff::where('user_id', $transportationPayment->user_id)->first();

            // StaffSalary::where('staff_id', $staffId->id ?? null)
            //     ->where('payroll_setting_id', $payrollSetting->id ?? null)
            //     ->delete();


            DB::commit();
            ResponseService::successResponse('Service cancelled successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, 'TransportationRequestController -> cancelTransportationService');
            ResponseService::errorResponse();
        }
    }

    public function getVehicleRoutes($pickupPointId)
    {
        ResponseService::noPermissionThenSendJson(['transportationRequests-list']);
        $validator = Validator::make(['pickup_point_id' => $pickupPointId], [
            'pickup_point_id' => 'required|numeric|exists:pickup_points,id',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $today = now();
            $routes = RouteVehicle::with('vehicle', 'route.shift')
                ->whereHas('route.routePickupPoints', function ($query) use ($pickupPointId) {
                    $query->where('pickup_point_id', $pickupPointId);
                });
            $routes = $routes->get();

            $assignedCounts = TransportationPayment::selectRaw('route_vehicle_id, COUNT(*) as assigned_students')
                ->whereNotNull('route_vehicle_id')
                ->where('status', 'paid')
                ->where('expiry_date', '>', $today)
                ->groupBy('route_vehicle_id')
                ->pluck('assigned_students', 'route_vehicle_id');

            $fees = TransportationFee::where('pickup_point_id', $pickupPointId)->get();

            return response()->json([
                'success' => true,
                'data' => $routes,
                'assignedCounts' => $assignedCounts,
                'fees' => $fees,
            ]);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'TransportationRequestController -> getVehicleRoutes');
            return ResponseService::errorResponse();
        }
    }

    public function changeStatusBulk(Request $request)
    {
        ResponseService::noPermissionThenRedirect('transportationRequests-edit');

        $validator = Validator::make($request->all(), [
            'vehicle_route' => 'required|numeric|exists:route_vehicles,id',
            'ids' => 'required|string',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();

            $today = now();
            $paymentIds = json_decode($request->ids, true);

            // 1️⃣ Fetch user IDs for selected payments
            $users = TransportationPayment::whereIn('id', $paymentIds)
                ->pluck('user_id', 'id');  // payment_id => user_id

            $userIds = $users->values()->unique();

            // 2️⃣ Find users who already have ANY assigned payment
            $usersAlreadyAssigned = TransportationPayment::whereIn('user_id', $userIds)
                ->whereNotNull('route_vehicle_id')
                ->where('expiry_date', '>', $today)
                ->pluck('user_id')
                ->unique();

            // 3️⃣ Filter out payments belonging to these users
            $filteredPaymentIds = $users->filter(function ($userId, $paymentId) use ($usersAlreadyAssigned) {
                return !$usersAlreadyAssigned->contains($userId);
            })->keys();

            $notificationUserIds = TransportationPayment::whereIn('id', $filteredPaymentIds)
                ->pluck('user_id')
                ->unique()
                ->toArray();

            // Get student records for these users
            $students = Students::whereIn('user_id', $notificationUserIds)
                ->get(['id', 'user_id', 'guardian_id']);

            // Index by user_id for O(1) lookup
            $studentsByUserId = $students->keyBy('user_id');

            $title = "Transportation assigned";
            $body = "Your transportation has been assigned successfully.";
            $type = 'Transportation';

            $studentPayloads = [];
            $guardianPayloads = [];
            $staffPayloads = [];

            foreach ($notificationUserIds as $userId) {

                if ($studentsByUserId->has($userId)) {
                    // User is a student
                    $student = $studentsByUserId->get($userId);
                    $childId = $student->id;          // student_id
                    $guardianId = $student->guardian_id;

                    // Notification to Student
                    $studentPayload = buildPayloads(
                        [$userId],
                        $title,
                        $body,
                        $type,
                        ['user_id' => $userId]
                    );
                    $studentPayloads = array_merge($studentPayloads, $studentPayload);

                    // Notification to Guardian (only if exists)
                    if (!empty($guardianId)) {
                        $guardianPayload = buildPayloads(
                            [$guardianId],
                            $title,
                            $body,
                            $type,
                            ['guardian_id' => $guardianId, 'child_id' => $childId, 'user_id' => $userId]
                        );
                        $guardianPayloads = array_merge($guardianPayloads, $guardianPayload);
                    }

                } else {
                    // User is NOT a student (teacher/staff)
                    $staffPayload = buildPayloads(
                        [$userId],
                        $title,
                        $body,
                        $type,
                        ['user_id' => $userId]   // No child_id
                    );
                    $staffPayloads = array_merge($staffPayloads, $staffPayload);
                }
            }

            // Send notifications in bulk
            $studentAndGuardianPayloads = array_merge($studentPayloads, $guardianPayloads);


            $skippedUsersCount = $usersAlreadyAssigned->count();
            $skippedPaymentsCount = count($paymentIds) - $filteredPaymentIds->count();

            // 4️⃣ Update only remaining payments
            $updated = TransportationPayment::whereIn('id', $filteredPaymentIds)
                ->update(['route_vehicle_id' => $request->vehicle_route]);

            DB::commit();

            if (!empty($studentAndGuardianPayloads)) {
                sendBulk($studentAndGuardianPayloads);
            }

            if (!empty($staffPayloads)) {
                sendBulk($staffPayloads);
            }

            $message = "{$updated} updated successfully";
            if ($skippedUsersCount > 0) {
                $message .= ", {$skippedPaymentsCount} skipped ({$skippedUsersCount} users already assigned)";
            }

            ResponseService::successResponse($message);

        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }


    public function offlineEntry()
    {
        ResponseService::noAnyPermissionThenRedirect(['transportationRequests-create']);

        $class_sections = $this->classSection->all(['*'], ['class', 'class.stream', 'section', 'medium']);
        $pickupPoints = pickupPoint::where('status', 1)->get();
        $shifts = Shift::where('status', 1)->get();


        return view('transportation-request.offline_entry', compact('pickupPoints', 'class_sections', 'shifts'));

    }

    public function getStudents($id)
    {
        ResponseService::noPermissionThenRedirect('transportationRequests-create');
        try {
            $students = $this->user->builder()
                ->role('Student')
                ->select('id', 'first_name', 'last_name')
                ->with([
                    'student' => function ($query) {
                        $query->select('id', 'class_section_id', 'user_id', 'guardian_id')
                            ->with([
                                'class_section' => function ($query) {
                                    $query->select('id', 'class_id', 'section_id', 'medium_id')
                                        ->with('class:id,name', 'section:id,name', 'medium:id,name');
                                }
                            ]);
                    }
                ])
                ->whereHas('student', function ($q) use ($id) {
                    $q->where('class_section_id', $id);
                })
                ->get();

            return response()->json([
                'success' => true,
                'data' => $students,
            ]);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'TransportationRequestController -> getStudents');
            return ResponseService::errorResponse();
        }

    }
    public function getTeachers()
    {
        ResponseService::noPermissionThenRedirect('transportationRequests-create');
        try {
            $teachers = $this->user->builder()->role('Teacher')->select('*')->get();

            return response()->json([
                'success' => true,
                'data' => $teachers,
            ]);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'TransportationRequestController -> getTeacher');
            return ResponseService::errorResponse();
        }

    }
    public function getStaff()
    {
        ResponseService::noPermissionThenRedirect('transportationRequests-create');
        try {
            $staff = $this->user->builder()->select('id', 'first_name', 'last_name', 'image')->has('staff')->with('roles', 'support_school.school:id,name')->whereHas('roles', function ($q) {
                $q->where('custom_role', 1)->whereNot('name', 'Teacher');
            })->get();

            return response()->json([
                'success' => true,
                'data' => $staff,
            ]);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'TransportationRequestController -> getTeacher');
            return ResponseService::errorResponse();
        }

    }

    public function offlineEntryStore(Request $request)
    {
        ResponseService::noPermissionThenRedirect('transportationRequests-create');

        $validator = Validator::make(
            $request->all(),
            [
                'user_id' => 'required|numeric|exists:users,id',
                // Remove shift_id from validation, as we will get it from routes table
                // 'shift_id' => 'nullable|numeric|exists:shifts,id',
                'pickup_point_id' => 'required|numeric|exists:pickup_points,id',
                'fee_id' => 'nullable|numeric|exists:transportation_fees,id',
                'route_vehicle_id' => 'required|numeric|exists:route_vehicles,id',
                'amount' => 'nullable|numeric',
                'mode' => 'nullable|in:1,2',
                'cheque_no' => 'required_if:mode,2',
                'include_amount' => 'nullable|numeric|in:0,1',
            ],
            [
                'user_id.required' => 'Please select a user.',
                'user_id.exists' => 'Selected user does not exist.',
                // 'shift_id.exists' => 'Selected shift does not exist.',
                'pickup_point_id.required' => 'Please select a pickup point.',
                'pickup_point_id.exists' => 'Selected pickup point does not exist.',
                'fee_id.exists' => 'Selected fee does not exist.',
                'route_vehicle_id.required' => 'Please select a vehicle route.',
                'route_vehicle_id.exists' => 'Selected route vehicle does not exist.',
                'mode.in' => 'Invalid payment mode selected.',
                'cheque_no.required_if' => 'Cheque number is required when payment mode is Cheque.',
                'include_amount.in' => 'Include amount must be either 0 or 1.',
            ]
        );

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();
            $sessionYear = $this->cache->getDefaultSessionYear();
            $today = now();
            $existingPayment = TransportationPayment::where('user_id', $request->user_id)
                ->whereNotNull('route_vehicle_id')
                ->where('session_year_id', $sessionYear->id)
                ->where('expiry_date', '>', $today)
                ->where('status', 'paid')
                ->first();

            if ($existingPayment) {
                ResponseService::errorResponse('This user already has an active paid record in the current session year.');
            }

            // Get the RouteVehicle and its related Route (to get shift_id from routes table)
            $routeVehicle = RouteVehicle::with(['vehicle', 'route'])->where('id', $request->route_vehicle_id)->first();

            // Get shift_id from the related route
            $shiftId = $routeVehicle && $routeVehicle->route ? $routeVehicle->route->shift_id : null;

            $assignedCounts = TransportationPayment::selectRaw('route_vehicle_id, COUNT(*) as assigned_students')
                ->where('route_vehicle_id', $request->route_vehicle_id)
                ->where('status', 'paid')
                ->where('expiry_date', '>', $today)
                ->groupBy('route_vehicle_id')->first();

            if (!empty($assignedCounts)) {
                if (!empty($routeVehicle) && ((int) $routeVehicle->vehicle->capacity - (int) $assignedCounts->assigned_students) == 0) {
                    ResponseService::errorResponse("No seats left in this vehicle");
                }
            }

            if ($request->fee_id) {
                $transportationFee = TransportationFee::where('id', $request->fee_id)->first();
                $expiryDate = null;
                if ($transportationFee) {
                    if (!empty($transportationFee->duration)) {
                        $expiryDate = now()->addDays($transportationFee->duration);
                    }
                }

                $mode = (int) $request->mode;
                $paymentTransactionData = [
                    'user_id' => $request->user_id,
                    'amount' => $request->amount,
                    'payment_gateway' => $mode === 1
                        ? 'cash'
                        : ($mode === 2 ? 'cheque' : null),
                    'order_id' => $request->cheque_no,
                    'payment_status' => 'succeed',
                    'type' => 'transportation_fee',
                    'school_id' => Auth::user()->school_id
                ];

                $paymentTransaction = PaymentTransaction::create($paymentTransactionData);
            } else {
                $expiryDate = $sessionYear->end_date;
            }

            $transportationPaymentData = [
                'route_vehicle_id' => $request->route_vehicle_id,
                'shift_id' => $shiftId, // Use shift_id from routes table
                'pickup_point_id' => $request->pickup_point_id,
                'user_id' => $request->user_id,
                'payment_transaction_id' => $paymentTransaction->id ?? null,
                'transportation_fee_id' => $request->fee_id ?? null,
                'amount' => $request->amount ?? 0,
                'paid_at' => now(),
                'session_year_id' => $sessionYear->id,
                'status' => 'paid',
                'expiry_date' => $expiryDate ?? null,
                'include_amount' => $request->include_amount ?? null,
            ];

            $dividedAmount = 0;
            if ($request->include_amount !== null && $request->include_amount == 1) {
                // Create StaffSalary record
                $startDate = now();

                // Calculate number of distinct months covered
                $months = $startDate->diffInMonths($expiryDate) + 1;
             
                // Divide amount equally per month
                $dividedAmount = $months > 0
                    ? round(($request->amount ?? 0) / $months, 2)
                    : ($request->amount ?? 0);
            }
            $transportationPaymentData['included_amount'] = $dividedAmount;

            TransportationPayment::create($transportationPaymentData);

            $title = "Transportation assigned";
            $body = "Your transportation has been assigned successfully.";
            $type = 'Transportation';

            $studentData = Students::where('user_id', $request->user_id)
                ->pluck('guardian_id', 'id')
                ->toArray();

            if (!empty($studentData)) {

                // Student exists → get child_id and guardian_id
                $childId = array_key_first($studentData);
                $guardianId = $studentData[$childId];

                // Notification to Student
                $studentPayload = buildPayloads(
                    [$request->user_id],
                    $title,
                    $body,
                    $type,
                    ['user_id' => $request->user_id]
                );

                // Notification to Guardian
                $guardianPayload = buildPayloads(
                    [$guardianId],
                    $title,
                    $body,
                    $type,
                    ['guardian_id' => $guardianId, 'child_id' => $childId, 'user_id' => $request->user_id]
                );

                // Send both
                sendBulk(array_merge($studentPayload, $guardianPayload));

            } else {

                // User is NOT a student (teacher/staff)
                $staffPayload = buildPayloads(
                    [$request->user_id],
                    $title,
                    $body,
                    $type,
                    ["user_id" => $request->user_id]   // No child_id
                );

                sendBulk($staffPayload);
            }

            DB::commit();
            ResponseService::successResponse('Data stored successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, 'TransportationRequestController -> offlineEntryStore');
            ResponseService::errorResponse();
        }
    }

    public function feeReceipt($id)
    {

        ResponseService::noAnyPermissionThenRedirect(['transportationRequests-receipt']);

        try {

            $TransportationPayment = TransportationPayment::where('status', 'paid')->with('pickupPoint', 'transportationFee', 'paymentTransaction')->where('id', $id)->first();

            $student = $this->user->builder()->role('Student')->select('id', 'first_name', 'last_name')
                ->with([
                    'student' => function ($query) {
                        $query->select('id', 'class_section_id', 'user_id', 'guardian_id', 'admission_no')->with([
                            'class_section' => function ($query) {
                                $query->select('id', 'class_id', 'section_id', 'medium_id')->with('class:id,name', 'section:id,name', 'medium:id,name');
                            }
                        ]);
                    }
                ])->where('id', $TransportationPayment->user_id)->first();

            $school = $this->cache->getSchoolSettings();

            $data = explode("storage/", $school['horizontal_logo'] ?? '');
            $school['horizontal_logo'] = end($data);

            if ($school['horizontal_logo'] == null) {
                $systemSettings = $this->cache->getSystemSettings();
                $data = explode("storage/", $systemSettings['horizontal_logo'] ?? '');
                $school['horizontal_logo'] = end($data);
            }

            $pdf = Pdf::loadView('transportation-request.fee_receipt', compact('school', 'TransportationPayment', 'student'));
            return $pdf->stream('transportation-fees-receipt.pdf');

        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'TransportationRequestController -> feeReceipt');
            ResponseService::errorResponse();
        }
    }
}
