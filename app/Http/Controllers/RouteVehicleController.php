<?php

namespace App\Http\Controllers;

use App\Models\LeaveMaster;
use Illuminate\Http\Request;
use App\Repositories\RouteVehicle\RouteVehicleRepositoryInterface;
use App\Repositories\Transportation\VehicleRepositoryInterface;
use App\Repositories\Shift\ShiftInterface;
use App\Services\CachingService;
use App\Repositories\User\UserInterface;
use App\Models\Route;
use App\Models\User;
use App\Models\TransportationPayment;
use App\Models\TransportationAttendance;
use App\Models\RouteVehicleHistory;
use App\Models\Holiday;
use App\Models\TripReports;
use App\Models\Students;
use Carbon\Carbon;
use Throwable;
use Illuminate\Validation\Rule;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RouteVehicleController extends Controller
{
    private RouteVehicleRepositoryInterface $routeVehicle;
    private VehicleRepositoryInterface $vehicle;
    private UserInterface $user;
    private ShiftInterface $shift;
    private CachingService $cache;

    public function __construct(RouteVehicleRepositoryInterface $routeVehicle, VehicleRepositoryInterface $vehicle, UserInterface $user, ShiftInterface $shift, CachingService $cache)
    {
        $this->routeVehicle = $routeVehicle;
        $this->vehicle = $vehicle;
        $this->user = $user;
        $this->shift = $shift;
        $this->cache = $cache;
    }

    public function index()
    {
        ResponseService::noAnyPermissionThenSendJson(['RouteVehicle-list']);
        $routeVehicles = $this->routeVehicle->all();
        $routes = Route::with('shift')->where('status', 1)->get();
        $vehicles = $this->vehicle->builder()->where('status', 1)->get();
        $shifts = $this->shift->all();
        $drivers = $this->user->builder()
            ->where(function ($query) {
                $query->whereHas('roles', function ($q) {
                    $q->where('custom_role', 0);
                })->WhereHas('roles', function ($q) {
                    $q->where('name', 'Driver');
                });
            })
            ->with('staff', 'roles', 'support_school.school')->get();
        $helpers = $this->user->builder()
            ->where(function ($query) {
                $query->whereHas('roles', function ($q) {
                    $q->where('custom_role', 0);
                })->WhereHas('roles', function ($q) {
                    $q->where('name', 'Helper');
                });
            })
            ->with('staff', 'roles', 'support_school.school')->get();
        return view('route-vehicle.index', compact('routeVehicles', 'vehicles', 'drivers', 'helpers', 'routes', 'shifts'));
    }

    public function store(Request $request)
    {
        ResponseService::noAnyPermissionThenSendJson(['RouteVehicle-create']);

        $validator = Validator::make(
            $request->all(),
            [
                'route_id' => ['required', 'exists:routes,id'],
                'vehicle_id' => ['required', 'exists:vehicles,id'],
                'driver_id' => [
                    'required',
                    'exists:users,id',

                ],
                'helper_id' => [
                    'required',
                    'exists:users,id',

                ],

                'pickup_trip_start_time' => ['required', 'date_format:H:i', 'before:pickup_trip_end_time'],
                'pickup_trip_end_time' => ['required', 'date_format:H:i', 'after:pickup_trip_start_time'],
                'drop_trip_start_time' => ['required', 'date_format:H:i', 'before:drop_trip_end_time'],
                'drop_trip_end_time' => ['required', 'date_format:H:i', 'after:drop_trip_start_time'],
            ],
            [
                'pickup_trip_start_time.before' => 'The pickup trip start time must be before the pickup trip end time.',
                'pickup_trip_end_time.after' => 'The pickup trip end time must be after the pickup trip start time.',
                'drop_trip_start_time.before' => 'The drop trip start time must be before the drop trip end time.',
                'drop_trip_end_time.after' => 'The drop trip end time must be after the drop trip start time.',
            ]
        );

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();
            $user = auth()->user();
            $sessionYear = $this->cache->getDefaultSessionYear();

            $today = Carbon::today()->toDateString();
            $schoolSettings = $this->cache->getSchoolSettings();

            // Get the route to fetch its shift_id
            $route = Route::with('pickupPoints')->findOrFail($request->route_id);
            $earliestPickup = $route->pickupPoints->min(fn($p) => $p->pivot->pickup_time);
            $latestPickup = $route->pickupPoints->max(fn($p) => $p->pivot->pickup_time);
            $earliestDrop = $route->pickupPoints->min(fn($p) => $p->pivot->drop_time);
            $latestDrop = $route->pickupPoints->max(fn($p) => $p->pivot->drop_time);

            if (Carbon::parse($request->pickup_trip_start_time) >= Carbon::parse($earliestPickup)) {
                ResponseService::validationError(
                    "Pickup trip start time must be less than " . Carbon::parse($earliestPickup)->format($schoolSettings['time_format']) . ". <br> Because Route's earliest pickup point time is " . Carbon::parse($earliestPickup)->format($schoolSettings['time_format'])
                );
            }

            if (Carbon::parse($request->pickup_trip_end_time) <= Carbon::parse($latestPickup)) {
                ResponseService::validationError(
                    "Pickup trip end time must be greater than " . Carbon::parse($latestPickup)->format($schoolSettings['time_format']) . ". <br> Because Route's latest pickup point time is " . Carbon::parse($latestPickup)->format($schoolSettings['time_format'])
                );
            }

            if (Carbon::parse($request->drop_trip_start_time) >= Carbon::parse($earliestDrop)) {
                ResponseService::validationError(
                    "Drop trip start time must be less than " . Carbon::parse($earliestDrop)->format($schoolSettings['time_format']) . ". <br> Because Route's earliest drop point time is " . Carbon::parse($earliestDrop)->format($schoolSettings['time_format'])
                );
            }

            if (Carbon::parse($request->drop_trip_end_time) <= Carbon::parse($latestDrop)) {
                ResponseService::validationError(
                    "Drop trip end time must be greater than " . Carbon::parse($latestDrop)->format($schoolSettings['time_format']) . ". <br> Because Route's latest drop point time is " . Carbon::parse($latestDrop)->format($schoolSettings['time_format'])
                );
            }

            $data = [
                'route_id' => $request->route_id,
                'vehicle_id' => $request->vehicle_id,
                'driver_id' => $request->driver_id ?? null,
                'helper_id' => $request->helper_id ?? null,
                'status' => $request->status ?? 1,
                'pickup_start_time' => $request->pickup_trip_start_time,
                'pickup_end_time' => $request->pickup_trip_end_time,
                'drop_start_time' => $request->drop_trip_start_time,
                'drop_end_time' => $request->drop_trip_end_time,
            ];

            $this->routeVehicle->create($data);


            DB::commit();
            ResponseService::successResponse('Vehicle Route created successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, "RouteVehicleController -> store");
            ResponseService::errorResponse();
        }
    }

    public function show()
    {
        ResponseService::noAnyPermissionThenSendJson(['RouteVehicle-list']);

        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'desc');
        $search = request('search');
        $showDeleted = request('show_deleted');

        $sql = $this->routeVehicle->builder()
            ->with(['vehicle', 'driver', 'helper', 'route.shift']) // preload relationships
            ->when(!empty($showDeleted), function ($query) {
                $query->onlyTrashed();
            });

        if (!empty($search)) {
            $sql->where(function ($q) use ($search) {
                $q->whereHas('vehicle', function ($v) use ($search) {
                    $v->where('name', 'LIKE', "%$search%");
                });

                $q->whereHas('route', function ($r) use ($search) {
                    $r->where('name', 'LIKE', "%$search%");
                })->whereHas('route.shift', function ($s) use ($search) {
                    $s->where('name', 'LIKE', "%$search%");
                });


                $q->orWhereHas('driver', function ($d) use ($search) {
                    $d->where('first_name', 'LIKE', "%$search%")
                        ->orWhere('last_name', 'LIKE', "%$search%")
                        ->orWhereRaw("concat(first_name,' ',last_name) LIKE ?", ["%{$search}%"]);
                });

                $q->orWhereHas('helper', function ($h) use ($search) {
                    $h->where('first_name', 'LIKE', "%$search%")
                        ->orWhere('last_name', 'LIKE', "%$search%")
                        ->orWhereRaw("concat(first_name,' ',last_name) LIKE ?", ["%{$search}%"]);
                });
            });
        }

        $total = $sql->count();
        if ($offset >= $total && $total > 0) {
            $lastPage = floor(($total - 1) / $limit) * $limit; // calculate last page offset
            $offset = $lastPage;
        }
        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = $offset + 1;

        $baseUrl = url('/');
        $baseUrlWithoutScheme = preg_replace("(^https?://)", "", $baseUrl);
        $baseUrlWithoutScheme = str_replace("www.", "", $baseUrlWithoutScheme);

        foreach ($res as $row) {
            $operate = '';
            if ($showDeleted) {
                $operate .= BootstrapTableService::menuRestoreButton('restore', route('route-vehicle.restore', $row->id));
                $operate .= BootstrapTableService::menuTrashButton('delete', route('route-vehicle.trash', $row->id));
            } else {
                $operate .= BootstrapTableService::menuButton('view', route('route-vehicle.routeVehicle-reports', $row->id));
                $operate .= BootstrapTableService::menuEditButton('edit', route('route-vehicle.update', $row->id));
                $operate .= BootstrapTableService::menuDeleteButton('delete', route('route-vehicle.destroy', $row->id));
            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['status'] = $row->status;
            $tempRow['operate'] = BootstrapTableService::menuItem($operate);
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function update(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson(['RouteVehicle-edit']);

        $validator = Validator::make($request->all(), [
            'edit_route_id' => ['required', 'exists:routes,id'],
            'edit_vehicle_id' => ['required', 'exists:vehicles,id'],
            'edit_driver_id' => [
                'nullable',
                'exists:users,id',
            ],
            'edit_helper_id' => [
                'nullable',
                'exists:users,id',
            ],

            'edit_pickup_trip_start_time' => ['required', 'date_format:H:i'],
            'edit_pickup_trip_end_time' => ['required', 'date_format:H:i'],
            'edit_drop_trip_start_time' => ['required', 'date_format:H:i'],
            'edit_drop_trip_end_time' => ['required', 'date_format:H:i'],
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();
            $user = auth()->user();
            $sessionYear = $this->cache->getDefaultSessionYear();

            $today = Carbon::today()->toDateString();
            $schoolSettings = $this->cache->getSchoolSettings();
            $transportationPaymentsCount = TransportationPayment::where('route_vehicle_id', $id)->count();
            if ($transportationPaymentsCount > 0) {
                $validator = Validator::make($request->all(), [
                    'edit_driver_id' => [
                        'required',
                        'exists:users,id',
                    ],
                    'edit_helper_id' => [
                        'required',
                        'exists:users,id',
                    ],
                ]);

                if ($validator->fails()) {
                    ResponseService::validationError($validator->errors()->first());
                }
            }

            // Get the route to fetch its shift_id
            $route = Route::with('pickupPoints')->findOrFail($request->edit_route_id);
            $earliestPickup = $route->pickupPoints->min(fn($p) => $p->pivot->pickup_time);
            $latestPickup = $route->pickupPoints->max(fn($p) => $p->pivot->pickup_time);
            $earliestDrop = $route->pickupPoints->min(fn($p) => $p->pivot->drop_time);
            $latestDrop = $route->pickupPoints->max(fn($p) => $p->pivot->drop_time);

            if (Carbon::parse($request->edit_pickup_trip_start_time) >= Carbon::parse($earliestPickup)) {
                ResponseService::validationError(
                    "Pickup trip start time must be less than " . Carbon::parse($earliestPickup)->format($schoolSettings['time_format']) . " <br> Because Route's earliest pickup point time is " . Carbon::parse($earliestPickup)->format($schoolSettings['time_format'])
                );
            }

            if (Carbon::parse($request->edit_pickup_trip_end_time) <= Carbon::parse($latestPickup)) {
                ResponseService::validationError(
                    "Pickup trip end time must be greater than " . Carbon::parse($latestPickup)->format($schoolSettings['time_format']) . " <br> Because Route's latest pickup point time is " . Carbon::parse($latestPickup)->format($schoolSettings['time_format'])
                );
            }

            if (Carbon::parse($request->edit_drop_trip_start_time) >= Carbon::parse($earliestDrop)) {
                ResponseService::validationError(
                    "Drop trip start time must be less than " . Carbon::parse($earliestDrop)->format($schoolSettings['time_format']) . " <br> Because Route's earliest drop point time is " . Carbon::parse($earliestDrop)->format($schoolSettings['time_format'])
                );
            }

            if (Carbon::parse($request->edit_drop_trip_end_time) <= Carbon::parse($latestDrop)) {
                ResponseService::validationError(
                    "Drop trip end time must be greater than " . Carbon::parse($latestDrop)->format($schoolSettings['time_format']) . " <br> Because Route's latest drop point time is " . Carbon::parse($latestDrop)->format($schoolSettings['time_format'])
                );
            }

            $data = [
                'route_id' => $request->edit_route_id,
                'vehicle_id' => $request->edit_vehicle_id,
                'driver_id' => $request->edit_driver_id ?? null,
                'helper_id' => $request->edit_helper_id ?? null,
                'pickup_start_time' => $request->edit_pickup_trip_start_time,
                'pickup_end_time' => $request->edit_pickup_trip_end_time,
                'drop_start_time' => $request->edit_drop_trip_start_time,
                'drop_end_time' => $request->edit_drop_trip_end_time,
            ];

            $routeVehicle = $this->routeVehicle->builder()->find($id);

            if ($routeVehicle) {
                if ($routeVehicle->driver_id != $data['driver_id'] || $routeVehicle->helper_id != $data['helper_id']) {

                    $users = TransportationPayment::where('route_vehicle_id', $id)
                        ->where('expiry_date', '>', $today)
                        ->where('status', 'paid')
                        ->pluck('user_id')
                        ->toArray();

                    // Load all students from the list
                    $students = Students::whereIn('user_id', $users)
                        ->with('user')
                        ->get(['id', 'user_id', 'guardian_id']);

                    $studentUserIds = $students->pluck('user_id')->toArray();

                    $allPayloads = [];

                    // Message assignment
                    if ($routeVehicle->driver_id != $data['driver_id']) {
                        $title = "Driver Changed";
                        $body = "Your vehicle driver has been changed.";
                    } else {
                        $title = "Helper Changed";
                        $body = "Your vehicle helper has been changed.";
                    }

                    $type = "Transportation";

                    // 1️⃣ STUDENTS + GUARDIANS (both get child_id)
                    foreach ($students as $student) {

                        $childId = $student->id;
                        $childName = trim(($student->user->full_name ?? '')) ?: "Student #{$childId}";

                        $finalBody = $body;

                        $customData = ['child_id' => $childId, "guardian_id" => $student->guardian_id];

                        // Guardians receive notification
                        $recipientGuardian = [$student->guardian_id];
                        $guardianPayloads = buildPayloads($recipientGuardian, $title, $finalBody, $type, $customData);
                        $allPayloads = array_merge($allPayloads, $guardianPayloads);

                        $customData = ['user_id' => $student->user_id];
                        // Student also receives notification
                        $recipientStudent = [$student->user_id];
                        $studentPayloads = buildPayloads($recipientStudent, $title, $finalBody, $type, $customData);
                        $allPayloads = array_merge($allPayloads, $studentPayloads);
                    }

                    // 2️⃣ STAFF – NO child ID
                    $staffUserIds = array_diff($users, $studentUserIds);

                    foreach ($staffUserIds as $staffId) {
                        $recipient = [$staffId];
                        $payloads = buildPayloads($recipient, $title, $body, $type, ["user_id" => $staffId]);
                        $allPayloads = array_merge($allPayloads, $payloads);
                    }

                    sendBulk($allPayloads);
                }
            }

            // Call repository update
            $this->routeVehicle->update($id, $data);

            DB::commit();
            ResponseService::successResponse('Vehicle Route updated successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, 'RouteVehicleController -> update');
            ResponseService::errorResponse();
        }
    }
    public function destroy($id)
    {
        ResponseService::noPermissionThenSendJson(['RouteVehicle-delete']);

        try {
            DB::beginTransaction();

            // Find the vehicle
            $routeVehicle = $this->routeVehicle->findById($id);

            if (!$routeVehicle) {
                ResponseService::errorResponse('Vehicle not found.');
            }

            $transportationPaymentsCount = TransportationPayment::where('route_vehicle_id', $id)->count();
            if ($transportationPaymentsCount > 0) {
                ResponseService::errorResponse('Cannot delete this Vehicle Route because it is associated with existing Transportation Payments.');
            }

            $this->routeVehicle->builder()
                ->where('id', $id)
                ->update(['status' => 0]);
            // Soft delete vehicle
            $routeVehicle->delete();

            DB::commit();
            ResponseService::successResponse('Vehicle Route deleted successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, "RouteVehicleController -> destroy method");
            ResponseService::errorResponse();
        }
    }

    public function restore(int $id)
    {
        ResponseService::noPermissionThenSendJson(['RouteVehicle-delete']);
        try {
            // Restore soft-deleted vehicle
            $this->routeVehicle->findOnlyTrashedById($id)->restore();
            $this->routeVehicle->builder()
                ->where('id', $id)
                ->update(['status' => 1]);

            ResponseService::successResponse("Vehicle Route restored successfully");
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "RouteVehicleController -> restore");
            ResponseService::errorResponse();
        }
    }
    public function trash($id)
    {
        ResponseService::noPermissionThenSendJson(['RouteVehicle-delete']);

        try {
            $transportationPaymentsCount = TransportationPayment::where('route_vehicle_id', $id)->count();
            if ($transportationPaymentsCount > 0) {
                ResponseService::errorResponse('Cannot delete this Vehicle Route because it is associated with existing Transportation Payments.');
            }
            $vehicle = $this->routeVehicle->builder()->withTrashed()->where('id', $id)->firstOrFail();

            $vehicle->forceDelete();

            ResponseService::successResponse("Vehicle Route deleted permanently");
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "RouteVehicleController -> trash", 'cannot_delete_because_data_is_associated_with_other_data');
            ResponseService::errorResponse();
        }
    }

    public function routeVehicleReports($id)
    {
        ResponseService::noAnyPermissionThenRedirect(['RouteVehicle-list']);
        $routeVehicles = $this->routeVehicle->builder()->with('route', 'route.routePickupPoints', 'route.routePickupPoints.pickupPoint', 'vehicle', 'driver', 'helper', 'shift')->where('id', $id)->first();
        $pickupPoints = $routeVehicles->route->routePickupPoints ?? [];
        $sessionYear = $this->cache->getDefaultSessionYear();
        $session_year_id = $sessionYear->id;
        $students = User::whereIn('id', function ($query) use ($id) {
            $query->select('user_id')
                ->from('transportation_payments')
                ->where('route_vehicle_id', $id)
                ->where('expiry_date', '>', Carbon::now()->toDateString());
        })
            ->whereHas('roles', function ($q) {
                $q->where('name', 'Student');
            })
            ->pluck('id');

        $staffs = User::whereIn('id', function ($query) use ($id) {
            $query->select('user_id')
                ->from('transportation_payments')
                ->where('route_vehicle_id', $id)
                ->where('expiry_date', '>', Carbon::now()->toDateString());
        })
            ->whereHas('roles', function ($q) {
                $q->where('custom_role', 1);
            })
            ->pluck('id');

        $teachers = User::whereIn('id', function ($query) use ($id) {
            $query->select('user_id')
                ->from('transportation_payments')
                ->where('route_vehicle_id', $id)
                ->where('expiry_date', '>', Carbon::now()->toDateString());
        })
            ->whereHas('roles', function ($q) {
                $q->where('custom_role', 0)
                    ->where('name', 'Teacher');
            })
            ->pluck('id');

        $staffs = $staffs->merge($teachers);
        return view('route-vehicle.reports.routeVehicle-view-reports', compact('routeVehicles', 'pickupPoints', 'students', 'staffs', 'session_year_id'));
    }

    public function getUserTransportationAttendanceReport(Request $request)
    {
        // Validate request parameters
        $request->validate([
            'month' => 'required|numeric|between:1,12',
            'user_id' => 'nullable|array',
            'user_id.*' => 'exists:users,id',
            'year' => 'required',
            'mode' => 'required|in:pickup,drop',
        ]);

        if (empty($request->user_id)) {
            return response()->json([
                'success' => true,
                'message' => 'No user selected'
            ]);
        }

        if ($request->mode == 'pickup') {
            $attendanceType = 0; // Pickup attendance type
        } else {
            $attendanceType = 1; // Drop attendance type
        }
        $sessionYear = $this->cache->getDefaultSessionYear();
        $schoolSettings = $this->cache->getSchoolSettings();
        // Get student information including class section

        // Create a Carbon date for the first day of the month
        $startDate = Carbon::createFromDate($request->year, $request->month, 1)->startOfMonth();
        $endDate = Carbon::createFromDate($request->year, $request->month, 1)->endOfMonth();

        $users = User::whereIn('id', $request->user_id)->get(['id', 'first_name', 'last_name', 'image']);

        // Get attendance records for this student in the specified month
        $attendanceRecords = TransportationAttendance::with('user')->whereIn('user_id', $request->user_id)
            ->where('pickup_drop', $attendanceType)
            ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->get();

        // Handle holiday attendance
        $holidayAttendance = Holiday::where('date', '>=', $startDate->format('Y-m-d'))
            ->where('date', '<=', $endDate->format('Y-m-d'))
            ->get()
            ->map(function ($h) use ($schoolSettings) {
                return [
                    'date' => Carbon::createFromFormat($schoolSettings['date_format'], $h->date)->format('Y-m-d'),
                    'title' => $h->title,
                ];
            });

        $leaveMaster = LeaveMaster::where('session_year_id', $sessionYear->id)->first();
        $holiday_days = $leaveMaster && $leaveMaster->holiday
            ? explode(',', $leaveMaster->holiday)
            : [];
        if ($leaveMaster) {
            $period = Carbon::parse($startDate)->daysUntil(Carbon::parse($endDate)->addDay());

            foreach ($period as $day) {
                if (in_array($day->format('l'), $holiday_days)) {
                    $holidayAttendance->push(['date' => $day->format('Y-m-d'), "title" => "Weekly Holiday"]);
                }
            }
        }
        // Prepare the response data
        $responseData = [
            'success' => true,
            'users' => $users,
            'attendance' => $attendanceRecords,
            'holiday' => $holidayAttendance,
        ];

        return response()->json($responseData);
    }

    public function tripDetailsReport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'route_vehicle_id' => 'required|integer|exists:route_vehicles,id',
            'date' => 'nullable|date',
            'type' => 'nullable|in:pickup,drop',
        ], [
            'route_vehicle_id.required' => 'Route vehicle ID is required.',
            'route_vehicle_id.exists' => 'Selected route vehicle does not exist.',
            'date.required' => 'Date is required.',
            'type.in' => 'Invalid trip type.',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $date = $request->date
                ? Carbon::parse($request->date)->format('Y-m-d')
                : Carbon::now()->format('Y-m-d');

            $routeVehicle = $this->routeVehicle->builder()
                ->with(['vehicle', 'route.routePickupPoints.pickupPoint'])
                ->findOrFail($request->route_vehicle_id);

            $histories = RouteVehicleHistory::where('route_id', $routeVehicle->route_id)
                ->where('vehicle_id', $routeVehicle->vehicle_id)
                ->where('driver_id', $routeVehicle->driver_id)
                ->where('helper_id', $routeVehicle->helper_id)
                ->whereDate('date', $date)
                ->get();

            $attendance = TransportationAttendance::whereIn('trip_id', $histories->pluck('id'))->get()->groupBy('trip_id');

            $fmt = fn($time) => $time ? date('h:i A', strtotime($time)) : null;

            $buildTripForType = function ($type) use ($routeVehicle, $histories, $attendance, $fmt) {
                $trip = $histories->where('type', $type)->first();
                $routePickupPoints = collect($routeVehicle->route->routePickupPoints);

                // sort according to type
                $routePickupPoints = $type === 'pickup'
                    ? $routePickupPoints->sortBy(fn($p) => $p->pickup_time)->values()
                    : $routePickupPoints->sortBy(fn($p) => $p->drop_time)->values();

                // if trip exists
                if ($trip) {
                    $tripAttendance = $attendance->get($trip->id, collect());
                    $status = ucfirst($trip->status ?? 'Upcoming');

                    // if trip completed, show only attended pickup points
                    if (strtolower($trip->status) === 'completed') {
                        $attendedIds = $tripAttendance->pluck('pickup_point_id')->unique();
                        $routePickupPoints = $routePickupPoints->whereIn('pickup_point_id', $attendedIds)->values();
                    }

                    // For drop trip, use same pickup points as pickup trip
                    if ($type === 'drop') {
                        $pickupTrip = $histories->where('type', 'pickup')->where('status', 'completed')->first();
                        if ($pickupTrip) {
                            $pickupAttIds = $attendance->get($pickupTrip->id, collect())
                                ->pluck('pickup_point_id')->unique();
                            $routePickupPoints = $routePickupPoints->whereIn('pickup_point_id', $pickupAttIds)->values();
                        }
                    }

                    $pickupPoints = $routePickupPoints->map(function ($rp) use ($tripAttendance, $fmt) {
                        $att = $tripAttendance->where('pickup_point_id', $rp->pickup_point_id)->first();
                        return [
                            'pickup_point_name' => optional($rp->pickupPoint)->name,
                            'pickup_time' => $fmt($rp->pickup_time),
                            'drop_time' => $fmt($rp->drop_time),
                            'actual_time' => $att ? $fmt($att->created_at) : 'Pending',
                        ];
                    });

                    $startStop = [
                        'pickup_point_name' => 'School',
                        'pickup_time' => $fmt($trip->start_time ?? null),
                        'drop_time' => $fmt($trip->start_time ?? null),
                        'actual_time' => $fmt($trip->actual_start_time ?? null) ?? 'Pending',
                    ];

                    $endStop = [
                        'pickup_point_name' => 'School',
                        'pickup_time' => $fmt($trip->end_time ?? null),
                        'drop_time' => $fmt($trip->end_time ?? null),
                        'actual_time' => $fmt($trip->actual_end_time ?? null) ?? 'Pending',
                    ];

                    // For pickup: school start first, then stops, then school end.
                    // For drop: school start first, then stops, then school end.
                    $pickupPoints = collect([$startStop])
                        ->merge($pickupPoints)
                        ->push($endStop)
                        ->values();

                    return [
                        'type' => $type,
                        'status' => $status,
                        'pickup_points' => $pickupPoints,
                    ];
                }

                // upcoming case (no trip started yet)
                $pickupPoints = $routePickupPoints->map(function ($rp) use ($fmt) {
                    return [
                        'pickup_point_name' => optional($rp->pickupPoint)->name,
                        'pickup_time' => $fmt($rp->pickup_time),
                        'drop_time' => $fmt($rp->drop_time),
                        'actual_time' => 'Pending',
                    ];
                });

                $pickupPoints = collect([
                    [
                        'pickup_point_name' => 'School',
                        'pickup_time' => $fmt($routeVehicle->pickup_start_time ?? null),
                        'drop_time' => $fmt($routeVehicle->drop_start_time ?? null),
                        'actual_time' => 'Pending',
                    ],
                    ...$pickupPoints,
                    [
                        'pickup_point_name' => 'School',
                        'pickup_time' => $fmt($routeVehicle->pickup_end_time ?? null),
                        'drop_time' => $fmt($routeVehicle->drop_end_time ?? null),
                        'actual_time' => 'Pending',
                    ],
                ]);

                return [
                    'type' => $type,
                    'status' => 'Upcoming',
                    'pickup_points' => $pickupPoints,
                ];
            };

            $pickupTrip = $buildTripForType('pickup');
            $dropTrip = $buildTripForType('drop');

            $selectedTrip = ($request->type === 'drop') ? $dropTrip : $pickupTrip;

            $bulkData = [
                'trip_info' => [
                    'type' => ucfirst($selectedTrip['type'] ?? '-'),
                    'status' => $selectedTrip['status'] ?? '-',
                ],
                'rows' => collect($selectedTrip['pickup_points'])->map(function ($p) use ($selectedTrip) {
                    return [
                        'name' => $p['pickup_point_name'] ?? '-',
                        'scheduled_time' => $selectedTrip['type'] === 'pickup'
                            ? ($p['pickup_time'] ?? '-')
                            : ($p['drop_time'] ?? '-'),
                        'actual_time' => $p['actual_time'] ?? '-',
                    ];
                })->values(),
            ];

            $bulkData['total'] = $bulkData['rows']->count();

            return response()->json($bulkData);

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }

    public function getTripReports(Request $request)
    {
        ResponseService::noAnyPermissionThenSendJson(['RouteVehicle-list']);
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'desc');
        $id = request('id');
        $search = request('search');

        $schoolSettings = $this->cache->getSchoolSettings();
        $sql = TripReports::whereHas('routeVehicleHistory.route.routeVehicle', function ($q) use ($id) {
            $q->where('id', $id);
        });

        if (!empty($search)) {
            $sql->where(function ($q) use ($search) {
                $q->whereHas('routeVehicleHistory.route', function ($r) use ($search) {
                    $r->where('name', 'LIKE', "%$search%");
                });

                $q->whereHas('pickupPoint', function ($r) use ($search) {
                    $r->where('name', 'LIKE', "%$search%");
                });

                $q->orWhereHas('creator', function ($d) use ($search) {
                    $d->where('first_name', 'LIKE', "%$search%")
                        ->orWhere('last_name', 'LIKE', "%$search%")
                        ->orWhereRaw("concat(first_name,' ',last_name) LIKE ?", ["%{$search}%"])
                        ->orWhereHas('roles', function ($r) use ($search) {
                            $r->where('name', 'LIKE', "%{$search}%");
                        });
                });

                $q->orWhere('title', 'LIKE', "%$search%")
                    ->orWhere('description', 'LIKE', "%$search%");

                if (strtolower($search) === 'pickup') {
                    $q->orWhereHas('routeVehicleHistory', function ($tr) use ($search) {
                        $tr->where('type', 'pickup');
                    });
                } elseif (strtolower($search) === 'drop') {
                    $q->orWhereHas('routeVehicleHistory', function ($tr) use ($search) {
                        $tr->where('type', 'drop');
                    });
                }
            });
        }

        $total = $sql->count();
        if ($offset >= $total && $total > 0) {
            $lastPage = floor(($total - 1) / $limit) * $limit; // calculate last page offset
            $offset = $lastPage;
        }
        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];

        foreach ($res as $row) {
            if ($row->creator->role == 'Driver' || $row->creator->role == 'Helper') {
                $pickupPointName = $row->pickupPoint->name ?? 'School';
            } else {
                $pickupPointName = optional($row->pickupPoint)->name;
            }
            $rows[] = [
                'id' => $row->id,
                'route' => optional($row->routeVehicleHistory->route)->name,
                'trip_type' => ucfirst($row->routeVehicleHistory->type),
                'pickup_point' => $pickupPointName,
                'description' => $row->description,
                'created_by' => $row->creator,
                'date' => Carbon::parse($row->created_at->toDateString())->format($schoolSettings['date_format']),
            ];
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);

    }
}
