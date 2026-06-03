<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Repositories\School\SchoolInterface;
use App\Repositories\Staff\StaffInterface;
use App\Repositories\SessionYear\SessionYearInterface;
use App\Repositories\StaffSupportSchool\StaffSupportSchoolInterface;
use App\Repositories\Subscription\SubscriptionInterface;
use App\Repositories\User\UserInterface;
use App\Services\BootstrapTableService;
use App\Services\SessionYearsTrackingsService;
use App\Services\CachingService;
use App\Services\FeaturesService;
use App\Services\ResponseService;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use GuzzleHttp\RetryMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use PDF;
use Throwable;
use App\Exports\StaffDataExport;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\StaffImport;
use App\Repositories\ExtraFormField\ExtraFormFieldsInterface;
use App\Repositories\FormField\FormFieldsInterface;
use Illuminate\Validation\ValidationException;
use App\Repositories\PayrollSetting\PayrollSettingInterface;
use App\Repositories\StaffSalary\StaffSalaryInterface;
use App\Services\UserService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;


class DriverHelperController extends Controller
{

    private UserInterface $user;
    private StaffInterface $staff;
    private SchoolInterface $school;
    private StaffSupportSchoolInterface $staffSupportSchool;
    private FeaturesService $features;
    private SubscriptionInterface $subscription;
    private CachingService $cache;
    private SubscriptionService $subscriptionService;
    private PayrollSettingInterface $payrollSetting;
    private StaffSalaryInterface $staffSalary;
    private FormFieldsInterface $formFields;
    private ExtraFormFieldsInterface $extraFormFields;
    private SessionYearInterface $sessionYear;
    private SessionYearsTrackingsService $sessionYearsTrackingsService;

    public function __construct(UserInterface $user, StaffInterface $staff, SchoolInterface $school, StaffSupportSchoolInterface $staffSupportSchool, FeaturesService $features, SubscriptionInterface $subscription, CachingService $cache, SubscriptionService $subscriptionService, PayrollSettingInterface $payrollSetting, StaffSalaryInterface $staffSalary, FormFieldsInterface $formFields, ExtraFormFieldsInterface $extraFormFields, SessionYearInterface $sessionYear, SessionYearsTrackingsService $sessionYearsTrackingsService)
    {
        $this->user = $user;
        $this->staff = $staff;
        $this->school = $school;
        $this->staffSupportSchool = $staffSupportSchool;
        $this->features = $features;
        $this->subscription = $subscription;
        $this->cache = $cache;
        $this->subscriptionService = $subscriptionService;
        $this->payrollSetting = $payrollSetting;
        $this->staffSalary = $staffSalary;
        $this->formFields = $formFields;
        $this->extraFormFields = $extraFormFields;
        $this->sessionYear = $sessionYear;
        $this->sessionYearsTrackingsService = $sessionYearsTrackingsService;
    }

    public function index()
    {
        ResponseService::noFeatureThenRedirect('Staff Management');
        ResponseService::noPermissionThenRedirect('driver-helper-list');
        $roles = Role::where('custom_role', 0)->whereIn('name', ['Driver', 'Helper'])->get();
        $schools = array();
        if (!Auth::user()->school_id) {
            $schools = $this->school->active()->pluck('name', 'id');
        }
        $features = $this->features->getFeatures();
        $schoolSettings = $this->cache->getSchoolSettings();
        // $features = array();

        $allowances = [];
        $deductions = [];

        $sessionYears = [];
        $extraFields = [];

        if (Auth::user()->school_id) {
            $allowances = $this->payrollSetting->builder()->where('type', 'allowance')->get();
            $deductions = $this->payrollSetting->builder()->where('type', 'deduction')->get();
            $extraFields = $this->formFields->defaultModel()->where('user_type', 2)->orderBy('rank')->get();
            $sessionYears = $this->sessionYear->all();
        } else {
            $extraFields = $this->formFields->defaultModel()->orderBy('rank')->get();
        }


        return response(view('driver-helper.index', compact('roles', 'schools', 'features', 'allowances', 'deductions', 'extraFields', 'sessionYears', 'schoolSettings')));
    }

    public function store(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Staff Management');
        ResponseService::noPermissionThenSendJson('driver-helper-create');

        try {
            $validator = Validator::make(
                $request->all(),
                [
                    'first_name' => 'required',
                    'last_name' => 'required',
                    'mobile' => 'required|digits_between:6,15',
                    'email' => 'required|email|max:255|regex:/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/|unique:users,email',
                    'role_id' => 'required|numeric',
                    'status' => 'nullable|in:0,1',
                    'dob' => 'required',
                    'image' => 'nullable|image|max:5120',
                    'license' => 'nullable|file|mimetypes:image/jpeg,image/png,application/pdf|max:5120',
                ],
                [
                    'license.mimetypes' => 'The license must be a file of type: image or pdf.'
                ]
            );
            $validator->sometimes('license', 'required|file|max:5120', function ($input) {
                $driverRoleId = Role::where('name', 'Driver')->value('id');
                return $input->role_id == $driverRoleId;
            });
            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }
            DB::beginTransaction();

            // Check free trial package
            if (Auth::user()->school_id) {
                $today_date = Carbon::now()->format('Y-m-d');
                $subscription = $this->subscription->builder()->doesntHave('subscription_bill')->whereDate('start_date', '<=', $today_date)->where('end_date', '>=', $today_date)->whereHas('package', function ($q) {
                    $q->where('is_trial', 1);
                })->first();

                if ($subscription) {
                    $systemSettings = $this->cache->getSystemSettings();
                    $staff = $this->user->builder()->role('Teacher')->withTrashed()->orWhereHas('roles', function ($q) {
                        $q->where('custom_role', 1)->whereNot('name', 'Teacher');
                    })->whereNotNull('school_id')->Owner()->count();
                    if ($staff >= $systemSettings['staff_limit']) {
                        $message = "The free trial allows only " . $systemSettings['staff_limit'] . " staff.";
                        ResponseService::errorResponse($message);
                    }
                }
            }

            // If prepaid plan check student limit
            $subscription = $this->subscriptionService->active_subscription(Auth::user()->school_id);
            if ($subscription && $subscription->package_type == 0) {
                $status = $this->subscriptionService->check_user_limit($subscription, "Staffs");

                if (!$status) {
                    ResponseService::errorResponse('You reach out limits');
                }
            }

            $role = Role::findOrFail($request->role_id);

            /*If Super admin creates the staff then make it active by default*/
            if (!empty(Auth::user()->school_id)) {
                $data = array(
                    ...$request->except('school_id'),
                    'password' => Hash::make($request->mobile),
                    'image' => $request->file('image'),
                    'status' => $request->status ?? 0,
                    'deleted_at' => $request->status == 1 ? null : '1970-01-01 01:00:00',
                    'two_factor_enabled' => 0,
                    'two_factor_secret' => null,
                    'two_factor_expires_at' => null,
                );
            } else {
                /*If School Admin creates the Staff then active/inactive staff based on status*/
                $data = array(
                    ...$request->except('school_id'),
                    'password' => Hash::make($request->mobile),
                    'image' => $request->file('image'),
                    'status' => 1,
                    'two_factor_enabled' => 0,
                    'two_factor_secret' => null,
                    'two_factor_expires_at' => null,
                );
            }


            $user = $this->user->create($data);


            // Store Extra Details
            $extraDetails = array();

            if (isset($request->extra_fields) && is_array($request->extra_fields)) {
                foreach ($request->extra_fields as $fields) {
                    $data = null;
                    if (isset($fields['data'])) {
                        $data = (is_array($fields['data']) ? json_encode($fields['data'], JSON_THROW_ON_ERROR) : $fields['data']);
                    }
                    $extraDetails[] = array(
                        'user_id' => $user->id,
                        'form_field_id' => $fields['form_field_id'],
                        'data' => $data,
                    );
                }
            }

            if (!empty($extraDetails)) {
                $this->extraFormFields->createBulk($extraDetails);
            }

            $user->assignRole($role);
            if ($user->school_id) {
                $leave_permission = [
                    'leave-list',
                    'leave-create',
                    'leave-edit',
                    'leave-delete',
                ];
                $user->givePermissionTo($leave_permission);
            }
            $license = null;
            if ($request->hasFile('license')) {
                $license = $request->file('license');
            }
            
            if ($request->joining_date) {
                $joining_date = date('Y-m-d', strtotime($request->joining_date));
            } else {
                $joining_date = null;
            }

            if (Auth::user() && Auth::user()->school_id) {
                $staff = $this->staff->create([
                    'user_id' => $user->id,
                    'qualification' => null,
                    'salary' => $request->salary ?? 0,
                    'joining_date' => $joining_date,
                    'join_session_year_id' => $request->session_year_id,
                    'leave_session_year_id' => null,
                    'license' => $license
                ]);
            } else {
                $staff = $this->staff->create([
                    'user_id' => $user->id,
                    'qualification' => null,
                    'salary' => $request->salary ?? 0,
                    'joining_date' => $joining_date,
                    'license' => $license
                ]);
            }


            if ($request->school_id) {
                $data = array();
                foreach ($request->school_id as $school) {
                    $data[] = [
                        'user_id' => $user->id,
                        'school_id' => $school
                    ];
                }
                $this->staffSupportSchool->upsert($data, ['user_id', 'school_id'], ['user_id', 'school_id']);
            }

            $allowance_data = array();
            $allowance_status = 0;
            foreach ($request->allowance ?? [] as $allowance) {
                if ($allowance['id']) {
                    $allowance_status = 1;
                    $allowance_data[] = [
                        'staff_id' => $staff->id,
                        'payroll_setting_id' => $allowance['id'],
                        'amount' => $allowance['amount'] ?? null,
                        'percentage' => $allowance['percentage'] ?? null
                    ];
                }
            }
            if ($allowance_status) {
                $this->staffSalary->upsert($allowance_data, ['staff_id', 'payroll_setting_id'], ['amount', 'percentage']);
            }

            $deduction_data = array();
            $deduction_status = 0;
            foreach ($request->deduction ?? [] as $deduction) {

                if ($deduction['id']) {
                    $deduction_status = 1;
                    $deduction_data[] = [
                        'staff_id' => $staff->id,
                        'payroll_setting_id' => $deduction['id'],
                        'amount' => $deduction['amount'] ?? null,
                        'percentage' => $deduction['percentage'] ?? null
                    ];
                }
            }
            if ($deduction_status) {
                $this->staffSalary->upsert($deduction_data, ['staff_id', 'payroll_setting_id'], ['amount', 'percentage']);
            }

            if (Auth::user() && Auth::user()->school_id) {
                $sessionYear = $this->cache->getDefaultSessionYear();
                $this->sessionYearsTrackingsService->storeSessionYearsTracking('App\Models\Staff', $staff->id, Auth::user()->id, $sessionYear->id, Auth::user()->school_id, null);
            }

            DB::commit();

            if ($user->school_id) {
                $sendEmail = app(UserService::class);
                $sendEmail->sendStaffRegistrationEmail($user, $user->mobile);
            }

            ResponseService::successResponse('Data Stored Successfully');

        } catch (Throwable $e) {
            if (Str::contains($e->getMessage(), ['Failed', 'Mail', 'Mailer', 'MailManager'])) {
                DB::commit();
                ResponseService::warningResponse("Staff Registered successfully. But Email not sent.");
            } else {
                DB::rollback();
                ResponseService::logErrorResponse($e);
                ResponseService::errorResponse();
            }

        }
    }

    public function show(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Staff Management');
        ResponseService::noPermissionThenRedirect('driver-helper-list');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $session_year_id = request('session_year_id');

        $sql = $this->user->builder()
            ->where(function ($query) {
                $query->whereHas('roles', function ($q) {
                    $q->where('custom_role', 0);
                })->WhereHas('roles', function ($q) {
                    $q->whereIn('name', ['Driver', 'Helper']);
                });
            })
            ->with('staff', 'roles', 'support_school.school');

        if ($session_year_id) {
            $sql->whereHas('staff', function ($q) use ($session_year_id) {
                $q->where('join_session_year_id', $session_year_id);
            });
        }

        if ($request->show_deactive == 1) {
            $sql = $sql->where('status', 0)->withTrashed();
        } else {
            $sql = $sql->where('status', 1);
        }

        if (!empty($_GET['search'])) {
            $search = $_GET['search'];
            $sql->where(function ($q) use ($search) {
                $q->where('id', 'LIKE', "%$search%")
                    ->orwhere('first_name', 'LIKE', "%$search%")
                    ->orwhere('last_name', 'LIKE', "%$search%")
                    ->orwhere('email', 'LIKE', "%$search%")
                    ->orwhere('mobile', 'LIKE', "%$search%")
                    ->orWhereRaw("concat(first_name,' ',last_name) LIKE ?", ["%{$search}%"]);
            })->Owner();
        }

        $total = $sql->count();

        if ($sort === 'full_name') {
            $sql = $sql->orderByRaw("CONCAT(first_name, ' ', last_name) $order");
        } else {
            $sql = $sql->orderBy($sort, $order);
        }
        if ($offset >= $total && $total > 0) {
            $lastPage = floor(($total - 1) / $limit) * $limit; // calculate last page offset
            $offset = $lastPage;
        }
        $res = $sql->get();

        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;

        foreach ($res as $row) {
            if ($request->show_deactive == 1) {
                //Show Restore and Hard Delete Buttons
                $operate = BootstrapTableService::button('fa fa-check', route('driver-helper.restore', $row->id), ['activate-staff', 'btn-gradient-success'], ['title' => __('active')]);
                $operate .= BootstrapTableService::trashButton(route('driver-helper.trash', $row->id));
            } else {
                //Show Edit and Soft Delete Buttons
                $operate = BootstrapTableService::editButton(route('driver-helper.update', $row->id));
                if (app(FeaturesService::class)->hasFeature('Expense Management')) {
                    $operate .= BootstrapTableService::button('fa fa-eye', route('staff.payroll-structure', $row->id), ['btn-gradient-warning'], ['title' => __('salary_structure')]);
                }
                $operate .= BootstrapTableService::button('fa fa-exclamation-triangle', route('staff.destroy', $row->id), ['deactivate-staff', 'btn-gradient-info'], ['title' => __('inactive')]);



            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['dob_org'] = $row->getRawOriginal('dob');
            $tempRow['support_school_id'] = $row->support_school->pluck('school_id');
            $tempRow['operate'] = $operate;
            $tempRow['roles_name'] = $row->roles->pluck('name');
            if (Auth::user()->school_id) {
                $tempRow['extra_fields'] = $row->extra_user_datas;
                foreach ($row->extra_user_datas as $key => $field) {
                    $data = '';
                    if ($field->form_field->type == 'checkbox') {
                        $data = json_decode($field->data);
                    } elseif ($field->form_field->type == 'file') {
                        $data = '<a href="' . Storage::url($field->data) . '" target="_blank">DOC</a>';
                    } elseif ($field->form_field->type == 'dropdown') {
                        $defaultValues = $field->form_field->default_values;
                        $data = $defaultValues[$field->data] ?? '';
                    } else {
                        $data = $field->data;
                    }
                    $tempRow[$field->form_field->name] = $data;
                }
            }
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function update(Request $request, $id)
    {
        ResponseService::noFeatureThenRedirect('Staff Management');
        ResponseService::noPermissionThenSendJson('driver-helper-edit');
        try {
            $validator = Validator::make(
                $request->all(),
                [
                    'first_name' => 'required',
                    'last_name' => 'required',
                    'mobile' => 'required|digits_between:6,15',
                    'email' => 'required|email|max:255|regex:/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/|unique:users,email,' . $id,
                    'role_id' => 'required|numeric',
                    'dob' => 'required',
                    'image' => 'nullable|image|max:5120',
                    'license' => 'nullable|file|mimetypes:image/jpeg,image/png,application/pdf|max:5120',
                ],
                [
                    'license.mimetypes' => 'The license must be a file of type: image or pdf.'
                ]
            );
            $validator->sometimes('license', 'nullable|file|max:5120', function ($input) {
                $driverRoleId = Role::where('name', 'Driver')->value('id');
                return $input->role_id == $driverRoleId;
            });
            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }
            DB::beginTransaction();
            $data = $request->except(['school_id', 'license']);
            if ($request->hasFile('image')) {
                $data['image'] = $request->file('image');
            }

            if ($request->reset_password) {
                $data['password'] = Hash::make($request->mobile);
            }

            if ($request->two_factor_verification == 1) {
                $data['two_factor_secret'] = null;
                $data['two_factor_expires_at'] = null;
                $data['two_factor_enabled'] = 1;
            } else {
                $data['two_factor_secret'] = null;
                $data['two_factor_expires_at'] = null;
                $data['two_factor_enabled'] = 0;
            }

            $user = $this->user->update($id, $data);

            // Store Extra Details
            $extraDetails = [];
            foreach ($request->edit_extra_fields ?? [] as $fields) {
                if ($fields['input_type'] == 'file') {
                    if (isset($fields['data']) && $fields['data'] instanceof UploadedFile) {
                        $extraDetails[] = array(
                            'id' => $fields['id'],
                            'user_id' => $user->id,
                            'form_field_id' => $fields['form_field_id'],
                            'data' => $fields['data']
                        );
                    }
                } else {
                    $data = null;
                    if (isset($fields['data'])) {
                        $data = (is_array($fields['data']) ? json_encode($fields['data'], JSON_THROW_ON_ERROR) : $fields['data']);
                    }
                    $extraDetails[] = array(
                        'id' => $fields['id'],
                        'user_id' => $user->id,
                        'form_field_id' => $fields['form_field_id'],
                        'data' => $data,
                    );
                }
            }
            $this->extraFormFields->upsert($extraDetails, ['id'], ['data']);

            $oldRole = $user->roles;
            if ($oldRole[0]->id !== $request->role_id) {
                $newRole = Role::findById($request->role_id);
                $user->removeRole($oldRole[0]);
                $user->assignRole($newRole);
            }
            $license = $user->staff->getRawOriginal('license');
            if ($request->hasFile('license')) {
                $license = $request->file('license');
            }
            if ($request->joining_date) {
                $joining_date = date('Y-m-d', strtotime($request->joining_date));
            } else {
                $joining_date = null;
            }
            $this->staff->update($user->staff->id, [
                'salary' => $request->salary,
                'joining_date' => $joining_date,
                'license' => $license
            ]);

            if ($user->school_id) {
                $leave_permission = [
                    'leave-list',
                    'leave-create',
                    'leave-edit',
                    'leave-delete',
                ];
                $user->givePermissionTo($leave_permission);
            }

            $this->staffSupportSchool->builder()->where('user_id', $user->id)->delete();
            if ($request->school_id) {
                $data = array();
                foreach ($request->school_id as $key => $school) {
                    $data[] = [
                        'user_id' => $user->id,
                        'school_id' => $school
                    ];
                }
                $this->staffSupportSchool->upsert($data, ['user_id', 'school_id'], ['user_id', 'school_id']);
            }

            DB::commit();
            ResponseService::successResponse('Data Updated Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function destroy($id)
    {
        ResponseService::noFeatureThenRedirect('Staff Management');
        ResponseService::noPermissionThenSendJson('driver-helper-delete');
        try {
            DB::beginTransaction();
            $user = $this->user->findById($id);
            $this->user->builder()->where('id', $id)->withTrashed()->update(['status' => $user->status == 0 ? 1 : 0, 'deleted_at' => $user->status == 1 ? now() : null]);
            if (Auth::user() && Auth::user()->school_id) {
                $sessionYear = $this->cache->getDefaultSessionYear();
                $this->sessionYearsTrackingsService->deleteSessionYearsTracking('App\Models\Staff', $id, Auth::user()->id, $sessionYear->id, Auth::user()->school_id, null);
            }
            DB::commit();
            ResponseService::successResponse('Data Deleted Successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function restore(int $id)
    {
        ResponseService::noFeatureThenRedirect('Staff Management');
        ResponseService::noPermissionThenSendJson('driver-helper-delete');
        try {
            DB::beginTransaction();
            $staff = $this->user->findTrashedById($id);

            if ($staff->status == 0) {
                // If prepaid plan check student limit
                $subscription = $this->subscriptionService->active_subscription(Auth::user()->school_id);
                if ($subscription && $subscription->package_type == 0) {
                    $status = $this->subscriptionService->check_user_limit($subscription, "Staffs");

                    if (!$status) {
                        ResponseService::errorResponse('You reach out limits');
                    }
                }
            }

            $this->user->builder()->where('id', $id)->withTrashed()->update(['status' => $staff->status == 0 ? 1 : 0, 'deleted_at' => $staff->status == 1 ? now() : null]);
            DB::commit();
            ResponseService::successResponse("Status Updated Successfully");
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function trash($id)
    {
        ResponseService::noFeatureThenRedirect('Staff Management');
        ResponseService::noPermissionThenSendJson('driver-helper-delete');
        try {
            $user = $this->user->findOnlyTrashedById($id);
            $user->staff->delete();
            $user->forceDelete();
            ResponseService::successResponse("Data Deleted Permanently");
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function changeStatusBulk(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Staff Management');
        ResponseService::noPermissionThenRedirect('driver-helper-delete');
        try {
            DB::beginTransaction();
            $userIds = json_decode($request->ids);
            foreach ($userIds as $userId) {
                $staff = $this->user->findTrashedById($userId);

                if ($staff->status == 0) {
                    // If prepaid plan check student limit
                    $subscription = $this->subscriptionService->active_subscription(Auth::user()->school_id);
                    if ($subscription && $subscription->package_type == 0) {
                        $status = $this->subscriptionService->check_user_limit($subscription, "Staffs");

                        if (!$status) {
                            ResponseService::errorResponse('You reach out limits');
                        }
                    }
                }

                $this->user->builder()->where('id', $userId)->withTrashed()->update(['status' => $staff->status == 0 ? 1 : 0, 'deleted_at' => $staff->status == 1 ? now() : null]);
            }
            DB::commit();
            ResponseService::successResponse("Status Updated Successfully");
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }
}
