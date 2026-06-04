<?php

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ClassTeacher;
use App\Models\Role;
use App\Models\Students;
use App\Models\SubjectTeacher;
use App\Models\User;
use App\Models\TransportationPayment;
use App\Models\RouteVehicle;
use App\Models\TransportationFee;
use App\Repositories\Attachment\AttachmentInterface;
use App\Repositories\Chat\ChatInterface;
use App\Repositories\ClassSection\ClassSectionInterface;
use App\Repositories\ExamResult\ExamResultInterface;
use App\Repositories\Gallery\GalleryInterface;
use App\Repositories\Grades\GradesInterface;
use App\Repositories\Holiday\HolidayInterface;
use App\Repositories\Leave\LeaveInterface;
use App\Repositories\LeaveDetail\LeaveDetailInterface;
use App\Repositories\LeaveMaster\LeaveMasterInterface;
use App\Repositories\Medium\MediumInterface;
use App\Repositories\PaymentConfiguration\PaymentConfigurationInterface;
use App\Repositories\PaymentTransaction\PaymentTransactionInterface;
use App\Repositories\SchoolSetting\SchoolSettingInterface;
use App\Repositories\SessionYear\SessionYearInterface;
use App\Repositories\Student\StudentInterface;
use App\Repositories\User\UserInterface;
use App\Services\CachingService;
use App\Services\Payment\PaymentService;
use App\Services\ResponseService;
use App\Repositories\Fees\FeesInterface;
use App\Repositories\ExtraFormField\ExtraFormFieldsInterface;
use Auth;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PDF;
use Stripe\Exception\ApiErrorException;
use Throwable;
use App\Repositories\Files\FilesInterface;
use App\Repositories\Message\MessageInterface;
use App\Repositories\Transportation\PickupPointRepositoryInterface;
use App\Rules\MaxFileSize;
use App\Services\GeneralFunctionService;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\School;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use App\Models\Notification;
use App\Models\UserNotification;
use App\Models\ClassSubject;
use App\Models\Subject;
use Exception;
use GuzzleHttp\Psr7\UploadedFile;

class ApiController extends Controller
{
    private CachingService $cache;
    private HolidayInterface $holiday;
    private StudentInterface $student;
    private PaymentConfigurationInterface $paymentConfiguration;
    private PaymentTransactionInterface $paymentTransaction;
    private GalleryInterface $gallery;
    private SessionYearInterface $sessionYear;
    private LeaveDetailInterface $leaveDetail;
    private LeaveMasterInterface $leaveMaster;
    private LeaveInterface $leave;
    private UserInterface $user;
    private MediumInterface $medium;
    private ClassSectionInterface $classSection;
    private FilesInterface $files;
    private ExamResultInterface $examResult;
    private GradesInterface $grade;
    private ChatInterface $chat;
    private MessageInterface $message;
    private AttachmentInterface $attachment;
    private SchoolSettingInterface $schoolSettings;
    private FeesInterface $fees;
    private PickupPointRepositoryInterface $pickupPoint;
    private ExtraFormFieldsInterface $extraFormFields;


    public function __construct(CachingService $cache, HolidayInterface $holiday, StudentInterface $student, PaymentConfigurationInterface $paymentConfiguration, PaymentTransactionInterface $paymentTransaction, GalleryInterface $gallery, SessionYearInterface $sessionYear, LeaveDetailInterface $leaveDetail, LeaveMasterInterface $leaveMaster, LeaveInterface $leave, UserInterface $user, MediumInterface $medium, ClassSectionInterface $classSection, ExamResultInterface $examResult, GradesInterface $grade, FilesInterface $files, ChatInterface $chat, MessageInterface $message, AttachmentInterface $attachment, SchoolSettingInterface $schoolSettings, FeesInterface $fees, PickupPointRepositoryInterface $pickupPoint, ExtraFormFieldsInterface $extraFormFields)
    {
        $this->cache = $cache;
        $this->holiday = $holiday;
        $this->student = $student;
        $this->paymentConfiguration = $paymentConfiguration;
        $this->paymentTransaction = $paymentTransaction;
        $this->gallery = $gallery;
        $this->sessionYear = $sessionYear;
        $this->leaveDetail = $leaveDetail;
        $this->leaveMaster = $leaveMaster;
        $this->leave = $leave;
        $this->user = $user;
        $this->medium = $medium;
        $this->classSection = $classSection;
        $this->files = $files;
        $this->examResult = $examResult;
        $this->grade = $grade;
        $this->chat = $chat;
        $this->message = $message;
        $this->attachment = $attachment;
        $this->schoolSettings = $schoolSettings;
        $this->fees = $fees;
        $this->pickupPoint = $pickupPoint;
        $this->extraFormFields = $extraFormFields;
    }

    public function logout(Request $request)
    {
        try {

            $user = $request->user();
            $user->fcm_id = '';
            $user->save();
            // $user->currentAccessToken()->delete();
            $token = $request->bearerToken();

            if ($token) {
                // Find the token in the personal_access_tokens table
                $accessToken = PersonalAccessToken::findToken($token);

                if ($accessToken) {
                    // Delete the token to revoke access
                    $accessToken->delete();
                }
            }

            ResponseService::successResponse('Logout Successfully done');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getHolidays(Request $request)
    {
        try {
            // $query->whereDate('date', '>=',$sessionYear->start_date)
            //     ->whereDate('date', '<=',$sessionYear->end_date);

            $sessionYear = $this->cache->getDefaultSessionYear();
            if ($request->child_id) {
                $child = $this->student->findById($request->child_id);
                $data = $this->holiday->builder()->where('school_id', $child->user->school_id);
            } else {
                $data = $this->holiday->builder();
            }

            $data = $data->whereDate('date', '>=', $sessionYear->start_date)
                ->whereDate('date', '<=', $sessionYear->end_date)->get();

            ResponseService::successResponse("Holidays Fetched Successfully", $data);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:student_parent_privacy_policy,teacher_staff_privacy_policy,student_terms_condition,teacher_terms_condition,contact_us,about_us,app_settings,fees_settings,terms_condition,privacy_policy'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $systemSettings = $this->cache->getSystemSettings();
            if ($request->type == "app_settings") {
                $data = array(
                    'app_link' => $systemSettings['app_link'] ?? "",
                    'ios_app_link' => $systemSettings['ios_app_link'] ?? "",
                    'app_version' => $systemSettings['app_version'] ?? "",
                    'ios_app_version' => $systemSettings['ios_app_version'] ?? "",
                    'force_app_update' => $systemSettings['force_app_update'] ?? "",
                    'app_maintenance' => $systemSettings['app_maintenance'] ?? "",
                    'teacher_app_link' => $systemSettings['teacher_app_link'] ?? "",
                    'teacher_ios_app_link' => $systemSettings['teacher_ios_app_link'] ?? "",
                    'teacher_app_version' => $systemSettings['teacher_app_version'] ?? "",
                    'teacher_ios_app_version' => $systemSettings['teacher_ios_app_version'] ?? "",
                    'teacher_force_app_update' => $systemSettings['teacher_force_app_update'] ?? "",
                    'teacher_app_maintenance' => $systemSettings['teacher_app_maintenance'] ?? "",
                    'tagline' => $systemSettings['tag_line'] ?? "",
                    'title' => $systemSettings['system_name'] ?? "",
                );
            } else {
                $data = isset($systemSettings[$request->type]) ? htmlspecialchars_decode($systemSettings[$request->type]) : "";
            }
            ResponseService::successResponse("Data Fetched Successfully", $data);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    protected function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => "required|email",
            'school_code' => 'required'
        ]);
        try {

            $schoolCode = $request->school_code;

            if ($schoolCode) {
                $school = School::on('mysql')->where('code', $schoolCode)->first();
                if ($school) {
                    DB::setDefaultConnection('school');
                    Config::set('database.connections.school.database', $school->database_name);
                    DB::purge('school');
                    DB::connection('school')->reconnect();
                    DB::setDefaultConnection('school');

                    $response = Password::sendResetLink(['email' => $request->email]);

                    if ($response == Password::RESET_LINK_SENT) {
                        ResponseService::successResponse("Forgot Password email send successfully");
                    } else {
                        ResponseService::errorResponse("Cannot send Reset Password Link.Try again later", null, config('constants.RESPONSE_CODE.RESET_PASSWORD_FAILED'));
                    }
                } else {
                    return response()->json(['error' => true, 'message' => 'Invalid school code'], 200);
                }
            } else {
                return response()->json(['error' => true, 'message' => 'Unauthenticated'], 200);
            }
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    protected function changePassword(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'new_password' => 'required|min:6',
            'new_confirm_password' => 'same:new_password',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $user = $request->user();
            if (Hash::check($request->current_password, $user->password)) {
                $user->update(['password' => Hash::make($request->new_password)]);
                ResponseService::successResponse("Password Changed successfully.");
            } else {
                ResponseService::errorResponse("Invalid Password", null, config('constants.RESPONSE_CODE.INVALID_PASSWORD'));
            }
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getPaymentMethod(Request $request)
    {
        if (Auth::user()->hasRole('Guardian')) {
            $validator = Validator::make($request->all(), [
                'child_id' => 'required|numeric',
            ]);
            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }
        }
        try {

            $response = $this->paymentConfiguration->builder()->select('payment_method', 'status')->pluck('status', 'payment_method');
            ResponseService::successResponse("Payment Details Fetched", $response);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getPaymentConfirmation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $paymentTransaction = app(PaymentTransactionInterface::class)->builder()->where('id', $request->id)->first();
            if (empty($paymentTransaction)) {
                ResponseService::errorResponse("No Data Found");
            }
            $data = PaymentService::create($paymentTransaction->payment_gateway, $paymentTransaction->school_id)->retrievePaymentIntent($paymentTransaction->order_id);

            $data = PaymentService::formatPaymentIntent($paymentTransaction->payment_gateway, $data);

            // Success
            ResponseService::successResponse("Payment Details Fetched", $data, ['payment_transaction' => $paymentTransaction]);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getPaymentTransactions(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'latest_only' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            if (!Auth::user()->hasRole('School Admin') || Auth::user()->hasRole('Super Admin')) {
                $user_id = Auth::user()->id;
            }
            $paymentTransactions = app(PaymentTransactionInterface::class)->builder();
            if ($request->latest_only) {
                $paymentTransactions->where('created_at', '>', Carbon::now()->subMinutes(30)->toDateTimeString());
            }
            $paymentTransactions = $paymentTransactions->with('school')->orderBy('id', 'DESC');
            if (isset($user_id)) {
                if (Auth::user()->hasRole('Guardian')) {
                    $childIds = Students::where('guardian_id', $user_id)->pluck('user_id')->toArray();
                    $paymentTransactions->whereIn('user_id', $childIds);
                } else {
                    $paymentTransactions->where('user_id', $user_id);
                }
            }
            $paymentTransactions = $paymentTransactions->get();

            $schoolSettings = app(SchoolSettingInterface::class)->builder()
                ->where(function ($q) {
                    $q->where('name', 'currency_code')->orWhere('name', 'currency_symbol');
                })->whereIn('school_id', $paymentTransactions->pluck('school_id'))->get();

            $paymentTransactions = $paymentTransactions->map(function ($data) use ($schoolSettings) {
                $getSchoolSettings = $schoolSettings->filter(function ($settings) use ($data) {
                    return $settings->school_id == $data->school_id;
                })->where('status', 1)->pluck('data', 'name');
                $data->currency_code = $getSchoolSettings['currency_code'] ?? '';
                $data->currency_symbol = sanitize_currency_symbol($getSchoolSettings['currency_symbol'] ?? null);
                if ($data->payment_status == "pending") {
                    try {
                        if ($data->order_id) {
                            // For Flutterwave, use tx_ref for verification
                            if ($data->payment_gateway == "Flutterwave") {
                                $paymentIntent = PaymentService::create($data->payment_gateway, $data->school_id)
                                    ->retrievePaymentIntent($data->order_id);
                                $paymentIntent = PaymentService::formatPaymentIntent($data->payment_gateway, $paymentIntent);

                                // Update transaction status based on verification
                                if (isset($paymentIntent['status'])) {
                                    $status = match (strtolower($paymentIntent['status'])) {
                                        'successful', 'completed', 'success' => 'succeed',
                                        'failed', 'cancelled' => 'failed',
                                        default => 'pending'
                                    };

                                    if ($status !== 'pending') {
                                        $this->paymentTransaction->update($data->id, [
                                            'payment_status' => $status,
                                            'payment_id' => $paymentIntent['transaction_id'] ?? null,
                                            'school_id' => $data->school_id
                                        ]);
                                        $data->payment_status = $status;
                                        if ($status === 'succeed') {
                                            $fee_id = Transportationpayment::where('payment_transaction_id', $data->id)->first();
                                            $transportationFee = TransportationFee::where('id', $fee_id->transportation_fee_id)->first();
                                            $expiryDate = null;
                                            if ($transportationFee) {
                                                if (!empty($transportationFee->duration)) {
                                                    $expiryDate = now()->addDays($transportationFee->duration);
                                                }
                                            }
                                            TransportationPayment::where('payment_transaction_id', $data->id)
                                                ->update([
                                                    'status' => "paid",
                                                    'paid_at' => Carbon::now()->format('Y-m-d H:i:s'),
                                                    'expiry_date' => $expiryDate
                                                ]);
                                        }
                                    }
                                }
                            } else {
                                // For other payment gateways
                                $paymentIntent = PaymentService::create($data->payment_gateway, $data->school_id)
                                    ->retrievePaymentIntent($data->order_id);
                                $paymentIntent = PaymentService::formatPaymentIntent($data->payment_gateway, $paymentIntent);

                                if ($paymentIntent['status'] != "pending") {
                                    $this->paymentTransaction->update($data->id, [
                                        'payment_status' => $paymentIntent['status'],
                                        'school_id' => $data->school_id
                                    ]);
                                    if ($paymentIntent['status'] == "succeed") {
                                        $transportationFee = TransportationFee::where('id', $paymentIntent['metadata']['fees_id'])->first();
                                        $expiryDate = null;
                                        if ($transportationFee) {
                                            if (!empty($transportationFee->duration)) {
                                                $expiryDate = now()->addDays($transportationFee->duration);
                                            }
                                        }
                                        TransportationPayment::where('payment_transaction_id', $data->id)
                                            ->update([
                                                'status' => "paid",
                                                'paid_at' => Carbon::now()->format('Y-m-d H:i:s'),
                                                'expiry_date' => $expiryDate
                                            ]);
                                    } else {
                                        TransportationPayment::where('payment_transaction_id', $data->id)
                                            ->update([
                                                'status' => "canceled"
                                            ]);
                                    }
                                    $data->payment_status = $paymentIntent['status'];
                                }
                            }
                        }
                    } catch (Exception $e) {
                        Log::error('Payment verification error:', [
                            'payment_id' => $data->id,
                            'order_id' => $data->order_id,
                            'error' => $e->getMessage()
                        ]);
                        // Don't update status on verification error
                    }
                }
                return $data;
            });

            ResponseService::successResponse("Payment Transactions Fetched", $paymentTransactions);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getGallery(Request $request)
    {
        try {
            $limit = $request->limit ?? 10;   // default limit
            $offset = $request->offset ?? 0;   // default offset 0

            if ($request->gallery_id) {
                $data = $this->gallery->builder()
                    ->with('file')
                    ->where('id', $request->gallery_id)
                    ->first();

                // Organize files into videos and photos with limit/offset
                if ($data) {
                    $data = $this->organizeGalleryFiles($data, $limit, $offset);
                }
            } else {
                if ($request->child_id) {
                    $child = $this->student->findById($request->child_id);
                    $query = $this->gallery->builder()
                        ->with('file')
                        ->where('school_id', $child->user->school_id);
                    if ($request->session_year_id) {
                        $query = $query->where('session_year_id', $request->session_year_id);
                    }
                    $data = $query->offset($offset)->limit($limit)->get();

                    // Organize files for each gallery with limit/offset
                    $data = $data->map(function ($gallery) use ($limit, $offset) {
                        return $this->organizeGalleryFiles($gallery, $limit, $offset);
                    });
                } else {
                    if ($request->session_year_id) {
                        $query = $this->gallery->builder()
                            ->with('file')
                            ->where('session_year_id', $request->session_year_id);
                    } else {
                        $query = $this->gallery->builder()
                            ->with('file');
                    }
                    $data = $query->offset($offset)->limit($limit)->get();

                    // Organize files for each gallery with limit/offset
                    $data = $data->map(function ($gallery) use ($limit, $offset) {
                        return $this->organizeGalleryFiles($gallery, $limit, $offset);
                    });
                }
            }
            ResponseService::successResponse("Gallery Fetched Successfully", $data);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    /**
     * Organize gallery files into videos and photos arrays based on type_detail
     * Apply limit and offset separately to each array
     */
    private function organizeGalleryFiles($gallery, $limit = 10, $offset = 0)
    {
        $videos = [];
        $photos = [];

        if ($gallery->file && $gallery->file->count() > 0) {
            foreach ($gallery->file as $file) {
                $fileArray = $file->toArray();
                if ($file->type_detail == 'Youtube Link') {
                    $videos[] = $fileArray;
                } elseif ($file->type_detail == 'File Upload') {
                    $photos[] = $fileArray;
                }
            }
        }

        // Apply limit and offset to videos array
        $videos = array_slice($videos, $offset, $limit);

        // Apply limit and offset to photos array
        $photos = array_slice($photos, $offset, $limit);

        // Convert to array if it's a model instance
        $galleryArray = $gallery->toArray();
        $galleryArray['videos'] = $videos;
        $galleryArray['photos'] = $photos;

        // Remove the original file relation
        unset($galleryArray['file']);

        return $galleryArray;
    }

    public function getSessionYear(Request $request)
    {
        try {
            if ($request->filled('child_id')) {
                $child = $this->student->findById($request->child_id);

                if (!$child) {
                    return ResponseService::validationError('Invalid child selected.');
                }

                $sessionYears = $this->sessionYear
                    ->builder()
                    ->where('school_id', $child->user->school_id)
                    ->get();
            } else {
                $sessionYears = $this->sessionYear->builder()->get();
            }

            $data = $sessionYears->map(function ($item) {
                return [
                    "id" => $item->id,
                    "name" => $item->name,
                    "default" => $item->default,
                    "start_date" => Carbon::parse($item->original_start_date)->format('d-m-Y'),
                    "end_date" => Carbon::parse($item->original_end_date)->format('d-m-Y'),
                    "school_id" => $item->school_id,
                    "created_at" => $item->created_at,
                    "updated_at" => $item->updated_at,
                    "deleted_at" => $item->deleted_at,
                ];
            });

            return ResponseService::successResponse(
                "Session Year Fetched Successfully",
                $data
            );

        } catch (\Throwable $e) {
            ResponseService::logErrorResponse($e);
            return ResponseService::errorResponse();
        }
    }

    public function getLeaves(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Staff Leave Management');
        try {
            $leave = $this->leaveDetail->builder()->with('leave:id,user_id', 'leave.user:id,first_name,last_name,image', 'leave.user.roles')
                ->whereHas('leave', function ($q) {
                    $q->where('status', 1);
                });
            if ($request->type == 0 || $request->type == null) {
                $leave->whereDate('date', '<=', Carbon::now()->format('Y-m-d'))->whereDate('date', '>=', Carbon::now()->format('Y-m-d'));
            }
            if ($request->type == 1) {
                $tomorrow_date = Carbon::now()->addDay()->format('Y-m-d');
                $leave->whereDate('date', '<=', $tomorrow_date)->whereDate('date', '>=', $tomorrow_date);
            }
            if ($request->type == 2) {
                $upcoming_date = Carbon::now()->addDays(1)->format('Y-m-d');
                $leave->whereDate('date', '>', $upcoming_date);
            }
            $leave = $leave->orderBy('date', 'ASC')->get()->append(['leave_date']);
            ResponseService::successResponse("Data Fetched Successfully", $leave);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function applyLeaves(Request $request)
    {
        $file_upload_size_limit = $this->cache->getSystemSettings('file_upload_size_limit');
        $validator = Validator::make($request->all(), [
            'reason' => 'required',
            'files' => 'nullable|array',
            'files.*' => ['nullable', 'mimes:jpg,jpeg,png,pdf,doc,docx', new MaxFileSize($file_upload_size_limit)]
        ], [
            'files.*.max_file_size' => trans('The file Uploaded must be less than :file_upload_size_limit MB.', [
                'file_upload_size_limit' => $file_upload_size_limit,
            ]),
            'files.*.mimes' => 'Only JPG, JPEG, PNG, PDF, DOC, and DOCX files are allowed.',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();
            $sessionYear = $this->cache->getDefaultSessionYear();
            $leaveMaster = $this->leaveMaster->builder()->where('session_year_id', $sessionYear->id)->first();

            if (!$leaveMaster) {
                ResponseService::errorResponse("Kindly contact the school admin to update settings for continued access.");
            }

            $public_holiday = $this->holiday->builder()->whereDate('date', '>=', $sessionYear->start_date)->whereDate('date', '<=', $sessionYear->end_date)->get()->pluck('date')->toArray();

            $dates = collect($request->leave_details)
                ->pluck('date')
                ->map(fn($d) => \Carbon\Carbon::parse($d));

            $from_date = $dates->min()->toDateString();
            $to_date = $dates->max()->toDateString();

            $exists = $this->leave->builder()
                ->where('user_id', Auth::user()->id)
                ->where(function ($q) use ($from_date, $to_date) {
                    $q->where('from_date', '<=', $to_date)
                        ->where('to_date', '>=', $from_date);
                })
                ->exists();

            if ($exists) {
                ResponseService::errorResponse('You already have a leave request during this period.');
            }

            $leave_data = [
                'user_id' => Auth::user()->id,
                'reason' => $request->reason,
                'from_date' => $from_date,
                'to_date' => $to_date,
                'status' => 0,
                'leave_master_id' => $leaveMaster->id
            ];

            $holidays = explode(',', $leaveMaster->holiday);

            $leave = $this->leave->create($leave_data);
            foreach ($request->leave_details as $key => $leaves) {
                $day = date('l', strtotime($leaves['date']));
                if (!in_array($day, $holidays) && !in_array($leaves['date'], $public_holiday)) {
                    $data[] = [
                        'leave_id' => $leave->id,
                        'date' => $leaves['date'],
                        'type' => $leaves['type']
                    ];
                } else {
                    ResponseService::errorResponse("please choose a valid date that is not a holiday or a public holiday");
                }
            }
            if ($request->hasFile('files')) {
                $fileData = []; // Empty FileData Array
                // Create A File Model Instance
                $leaveModelAssociate = $this->files->model()->modal()->associate($leave);
                foreach ($request->file('files') as $file_upload) {
                    // Create Temp File Data Array
                    $tempFileData = [
                        'modal_type' => $leaveModelAssociate->modal_type,
                        'modal_id' => $leaveModelAssociate->modal_id,
                        'file_name' => $file_upload->getClientOriginalName(),
                        'type' => 1,
                        'file_url' => $file_upload->store('files', 'public') // Store file and get the file path
                    ];
                    $fileData[] = $tempFileData; // Store Temp File Data in Multi-Dimensional File Data Array
                }
                $this->files->createBulk($fileData); // Store File Data
            }

            $this->leaveDetail->createBulk($data);

            $user = $this->user->builder()->whereHas('roles.permissions', function ($q) {
                $q->where('name', 'approve-leave');
            })->pluck('id');

            $type = "Leave";
            $title = Auth::user()->full_name . ' has submitted a new leave request.';
            $body = $request->reason;

            DB::commit();

            send_notification($user, $title, $body, $type);

            ResponseService::successResponse("Data Stored Successfully");
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
                ResponseService::logErrorResponse($e);
                ResponseService::errorResponse();
            }
        }
    }

    public function getMyLeaves(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Staff Leave Management');
        $validator = Validator::make($request->all(), [
            'month' => 'in:1,2,3,4,5,6,7,8,9,10,11,12',
            'status' => 'in:0,1,2'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $sessionYear = $this->cache->getDefaultSessionYear();
            $leaveMaster = $this->leaveMaster->builder();
            $sql = $this->leave->builder()->with('leave_detail')->where('user_id', Auth::user()->id)->withCount([
                'leave_detail as full_leave' => function ($q) {
                    $q->where('type', 'Full');
                }
            ])->withCount([
                        'leave_detail as half_leave' => function ($q) {
                            $q->whereNot('type', 'Full');
                        }
                    ]);

            if ($request->session_year_id) {
                $sql->whereHas('leave_master', function ($q) use ($request) {
                    $q->where('session_year_id', $request->session_year_id);
                });
                $leaveMaster->where('session_year_id', $request->session_year_id);
            } else {
                $sql->whereHas('leave_master', function ($q) use ($sessionYear) {
                    $q->where('session_year_id', $sessionYear->id);
                });
                $leaveMaster->where('session_year_id', $sessionYear->id);
            }
            if (isset($request->status)) {
                $sql->where('status', $request->status);
            }
            if ($request->month) {
                $sql->whereHas('leave_detail', function ($q) use ($request) {
                    $q->whereMonth('date', $request->month);
                });
            }
            $leaveMaster = $leaveMaster->first();
            $sql = $sql->get();
            $sql = $sql->map(function ($sql) {
                $total_leaves = ($sql->half_leave / 2) + $sql->full_leave;
                $sql->days = $total_leaves;
                return $sql;
            });
            $data = [
                'monthly_allowed_leaves' => $leaveMaster->leaves,
                'taken_leaves' => $sql->sum('days'),
                'leave_details' => $sql
            ];

            ResponseService::successResponse("Data Fetched Successfully", $data);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function deleteLeaves(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Staff Leave Management');
        $validator = Validator::make($request->all(), [
            'leave_id' => 'required',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();
            $leave = $this->leave->findById($request->leave_id);
            if ($leave->status != 0) {
                ResponseService::errorResponse("You cannot delete this leave");
            }
            $this->leave->deleteById($request->leave_id);
            DB::commit();
            ResponseService::successResponse("Data Deleted Successfully");
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getStaffLeaveDetail(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Staff Leave Management');
        $validator = Validator::make($request->all(), [
            'staff_id' => 'required',
            'month' => 'in:1,2,3,4,5,6,7,8,9,10,11,12',
            'status' => 'in:0,1,2'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $sessionYear = $this->cache->getDefaultSessionYear();
            $leaveMaster = $this->leaveMaster->builder();
            $sql = $this->leave->builder()->with('leave_detail', 'file')->withCount([
                'leave_detail as full_leave' => function ($q) {
                    $q->where('type', 'Full');
                }
            ])->withCount([
                        'leave_detail as half_leave' => function ($q) {
                            $q->whereNot('type', 'Full');
                        }
                    ])->where('user_id', $request->staff_id);

            if ($request->session_year_id) {
                $sql->whereHas('leave_master', function ($q) use ($request) {
                    $q->where('session_year_id', $request->session_year_id);
                });
                $leaveMaster->where('session_year_id', $request->session_year_id);
            } else {
                $sql->whereHas('leave_master', function ($q) use ($sessionYear) {
                    $q->where('session_year_id', $sessionYear->id);
                });
                $leaveMaster->where('session_year_id', $sessionYear->id);
            }
            if (isset($request->status)) {
                $sql->where('status', $request->status);
            }
            if ($request->month) {
                $sql->whereHas('leave_detail', function ($q) use ($request) {
                    $q->whereMonth('date', $request->month);
                });
            }
            $leaveMaster = $leaveMaster->first();
            if (!$leaveMaster) {
                ResponseService::errorResponse("Leave settings not found");
            }
            $sql = $sql->get();
            $sql = $sql->map(function ($sql) {
                $total_leaves = ($sql->half_leave / 2) + $sql->full_leave;
                $sql->days = $total_leaves;
                if ($sql->status == 1) {
                    $sql->taken_leaves = $total_leaves;
                }
                return $sql;
            });
            $data = [
                'monthly_allowed_leaves' => $leaveMaster->leaves,
                'total_leaves' => $sql->sum('days'),
                'taken_leaves' => $sql->sum('taken_leaves'),
                'leave_details' => $sql
            ];

            ResponseService::successResponse("Data Fetched Successfully", $data);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getMedium()
    {
        try {
            $sql = $this->medium->builder()->get();
            ResponseService::successResponse("Data Fetched Successfully", $sql);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getClass(Request $request)
    {
        try {
            $currentSemester = $this->cache->getDefaultSemesterData();

            // Teacher
            if (Auth::user()->hasRole('Teacher')) {
                $user = $request->user()->teacher;
                //Find the class in which teacher is assigns as Class Teacher
                $class_section_ids = [];
                if ($user->class_teacher) {
                    $class_teacher = $user->class_teacher;
                    $class_section_ids = $class_teacher->pluck('class_section_id');
                }
                //Find the Classes in which teacher is taking subjects
                $class_section = $this->classSection->builder()->with('class.stream', 'section', 'medium')->whereNotIn('id', $class_section_ids);

                $class_section = $class_section->whereHas('subject_teachers.class_subject', function ($q) use ($currentSemester) {
                    (!empty($currentSemester)) ? $q->where('semester_id', $currentSemester->id)->orWhereNull('semester_id') : $q->orWhereNull('semester_id');
                })->get();

                // $class_section = $class_section->get();
            } else {
                // Staff
                $class_section = $this->classSection->builder()->with('class', 'section', 'medium', 'class.stream')->get();
            }
            ResponseService::successResponse('Data Fetched Successfully', null, [
                'class_teacher' => $class_teacher ?? [],
                'other' => $class_section,
                'semester' => $currentSemester ?? null
            ]);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function updateProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required',
            'last_name' => 'required',
            'mobile' => 'required',
            'email' => 'required|unique:users,email,' . Auth::user()->id,
            'dob' => 'required',
            'current_address' => 'required',
            'permanent_address' => 'required',
            'gender' => 'required|in:male,female',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,svg|max:5120',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        if (Auth::user()->school_id) {
            $schoolSettings = $this->cache->getSchoolSettings();
        } else {
            $schoolSettings = $this->cache->getSystemSettings();
        }
        try {
            $user_data = [
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'mobile' => $request->mobile,
                'email' => $request->email,
                'dob' => Carbon::parse($request->dob)->format('Y-m-d'),
                'current_address' => $request->current_address,
                'permanent_address' => $request->permanent_address,
                'gender' => $request->gender,
            ];

            if ($request->hasFile('image')) {
                $user_data['image'] = $request->file('image');
            }

            $user = $this->user->update(Auth::user()->id, $user_data);

            $extraDetails = [];
            foreach ($request->custom_fields ?? [] as $fields) {
                if ($fields['input_type'] == 'file' && $fields['data'] instanceof UploadedFile) {
                    if (isset($fields['data'])) {
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

            $user = User::where('id', Auth::id())
                ->with(['extra_user_details.form_field'])
                ->first();

            $customFields = $user->extra_user_details->map(function ($row) {
                $field = $row->form_field;
                $value = $row->data;
                if ($field->type === 'file' && !empty($value)) {
                    $raw = $row->getRawOriginal('data');

                    $value = url('storage/' . ltrim($raw, '/'));
                }
                return [
                    'id' => $row->id,
                    'form_field_id' => $field->id,
                    'name' => $field->name,
                    'type' => $field->type,
                    'is_required' => (bool) $field->is_required,
                    'default_value' => $field->default_values,
                    'value' => $value,
                    'user_type' => $field->user_type == 1 ? 'student' : 'teacher/staff',
                    'rank' => $field->rank,
                ];
            });

            $user->custom_fields = $customFields;
            unset($user->extra_user_details);

            ResponseService::successResponse('Data Updated Successfully', $user);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function getExamResultPdf(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Exam Management');


        try {

            $validator = Validator::make($request->all(), [
                'exam_id' => 'required',
                'student_id' => 'required',
            ]);
            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }

            $exam_id = $request->exam_id;
            $student_id = $request->student_id;

            $results = $this->examResult->builder()
                ->with([
                    'exam',
                    'session_year',
                    'class_section.class.stream',
                    'class_section.section',
                    'class_section.medium',
                    'user' => function ($q) use ($exam_id) {
                        $q->with([
                            'student.guardian',
                            'exam_marks' => function ($q) use ($exam_id) {
                                $q->whereHas('timetable', function ($q) use ($exam_id) {
                                    $q->where('exam_id', $exam_id);
                                })->with([
                                            'class_subject' => function ($q) {
                                                $q->withTrashed()->with('subject:id,name,type');
                                            },
                                            'timetable'
                                        ]);
                            }
                        ]);
                    }
                ])
                ->where('exam_id', $exam_id)
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

            if (!$result) {
                return redirect()->back()->with('error', trans('no_records_found'));
            }

            $grades = $this->grade->builder()->orderBy('starting_range', 'ASC')->get();


            $settings = $this->cache->getSchoolSettings();
            $data = explode("storage/", $settings['horizontal_logo'] ?? '');
            $settings['horizontal_logo'] = end($data);

            if ($settings['horizontal_logo'] == null) {
                $systemSettings = $this->cache->getSystemSettings();
                $data = explode("storage/", $systemSettings['horizontal_logo'] ?? '');
                $settings['horizontal_logo'] = end($data);
            }


            $pdf = PDF::loadView('exams.exam_result_pdf', compact('result', 'settings', 'grades'))->output();

            return $response = array(
                'error' => false,
                'pdf' => base64_encode($pdf),
            );
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function leaveSettings(Request $request)
    {
        try {

            if ($request->session_year_id) {
                $session_year_id = $request->session_year_id;
            } else {
                $sessionYear = $this->cache->getDefaultSessionYear();
                $session_year_id = $sessionYear->id;
            }
            $sql = $this->leaveMaster->builder()->where('session_year_id', $session_year_id)->get();
            ResponseService::successResponse("Data Fetched Successfully", $sql);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getSchoolSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:privacy_policy,terms_condition,refund_cancellation'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {

            if ($request->child_id) {
                $child = $this->student->builder()->where('id', $request->child_id)->first();
                if (!$child) {
                    ResponseService::errorResponse("no_data_found");
                }
                $schoolSettings = $this->cache->getSchoolSettings('*', $child->school_id);
            } else {
                $schoolSettings = $this->cache->getSchoolSettings();
            }

            $data = isset($schoolSettings[$request->type]) ? htmlspecialchars_decode($schoolSettings[$request->type]) : "";
            ResponseService::successResponse("Data Fetched Successfully", $data);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function sendMessage(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'to' => 'required'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        $message = '';

        try {
            DB::beginTransaction();
            $chat = $this->chat->builder()
                ->where(function ($q) use ($request) {
                    $q->where('sender_id', Auth::user()->id)->where('receiver_id', $request->to);
                })
                ->orWhere(function ($q) use ($request) {
                    $q->where('sender_id', $request->to)->where('receiver_id', Auth::user()->id);
                })
                ->first();

            if (!$chat) {
                $chat = $this->chat->create(['sender_id' => Auth::user()->id, 'receiver_id' => $request->to]);
            }

            $chat = $chat->load(['receiver']);

            $message = $this->message->create(['chat_id' => $chat->id, 'sender_id' => Auth::user()->id, 'message' => $request->message]);

            $data = [];
            $subjectName = '';
            // Prepare customData if Teacher is messaging Student
            $customData = [];
            $receiverUser = User::find($request->to);
            if (Auth::user()->hasRole('Teacher') && $receiverUser && $receiverUser->hasRole('Student')) {
                // Get student's class_section_id
                $student = $receiverUser->student;

                if ($student && $student->class_section_id) {
                    // Get teacher's subject teachers for the student's class section
                    $teacherSubjectTeachers = SubjectTeacher::where('teacher_id', Auth::user()->id)
                        ->where('class_section_id', $student->class_section_id)
                        ->with('class_subject.subject')
                        ->get();

                    // Get student's subjects (student_id in StudentSubject references Students.user_id)
                    $studentSubjects = \App\Models\StudentSubject::where('student_id', $student->user_id)
                        ->where('class_section_id', $student->class_section_id)
                        ->pluck('class_subject_id')
                        ->toArray();

                    // Find common subject
                    foreach ($teacherSubjectTeachers as $subjectTeacher) {
                        if (in_array($subjectTeacher->class_subject_id, $studentSubjects)) {
                            if ($subjectTeacher->class_subject && $subjectTeacher->class_subject->subject) {
                                $subjectName = $subjectTeacher->class_subject->subject->name;
                                if ($subjectTeacher->class_subject->subject->type) {
                                    $subjectName .= ' (' . $subjectTeacher->class_subject->subject->type . ')';
                                }
                            }
                            break; // Get first matching subject
                        }
                    }
                }
            }

            $customData = [
                'receiver_id' => Auth::user()->id,
                'teacher_name' => Auth::user()->full_name ?? '',
                'teacher_image' => Auth::user()->image ?? '',
                'subject_name' => $subjectName,
            ];

            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file) {
                    $file_path = $file->store('chat_file', 'public');
                    $data[] = [
                        'message_id' => $message->id,
                        'file' => $file_path,
                        'file_type' => $file->getClientOriginalExtension()
                    ];
                }
                $this->attachment->createBulk($data);

                // set attachment
                $message['attachment'] = $message->load(['attachment'])->toArray();

                // send notification
                $user[] = $request->to;
                $title = 'New Message from ' . Auth::user()->full_name;

                $fileNames = array_map(function ($file) {
                    return basename($file->getClientOriginalName());
                }, $request->file('files'));

                $body = $request->message ? $request->message . ' (Files: ' . implode(', ', $fileNames) . ')'
                    : 'Files attached: ' . implode(', ', $fileNames);
                $type = 'Message';

                DB::commit();

                send_notification($user, $title, $body, $type, $customData);

                ResponseService::successResponse("Data Stored Successfully", $message);
            } else {
                // Only send notification if no files attached
                $user[] = $request->to;
                $title = 'New Message from ' . Auth::user()->full_name;
                $body = $request->message ?? 'No message';
                $type = 'Message';

                DB::commit();
                send_notification($user, $title, $body, $type, $customData);

                ResponseService::successResponse("Data Stored Successfully", $message);
            }
        } catch (\Throwable $th) {
            $notificationStatus = app(GeneralFunctionService::class)->wrongNotificationSetup($th);
            if ($notificationStatus) {
                DB::rollBack();
                ResponseService::logErrorResponse($th);
                ResponseService::errorResponse();
            } else {
                DB::commit();
                ResponseService::warningResponse("Data Stored successfully. But App push notification not send.", $message);
            }
        }
    }

    public function getMessage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'receiver_id' => 'required'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = [];
            $data = $this->message->builder()->whereHas('chat', function ($q) use ($request) {
                $q->where(function ($q) use ($request) {
                    $q->where('sender_id', Auth::user()->id)->where('receiver_id', $request->receiver_id);
                })
                    ->orWhere(function ($q) use ($request) {
                        $q->where('sender_id', $request->receiver_id)->where('receiver_id', Auth::user()->id);
                    });
            })
                ->with('attachment')->orderBy('id', 'DESC')
                ->paginate(10);

            ResponseService::successResponse("data_fetch_successfully", $data);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function deleteMessage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required'
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $this->message->builder()->whereIn('id', $request->id)->delete();
            ResponseService::successResponse("Data Deleted Successfully");
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function readMessage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'message_id' => 'required'
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $read_at = Carbon::now();
            $this->message->builder()->whereIn('id', $request->message_id)->update(['read_at' => $read_at]);
            ResponseService::successResponse("Data Updated Successfully");
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function getUsers(Request $request)
    {
        if (Auth::user()->hasRole('Teacher')) {
            $validator = Validator::make($request->all(), [
                'role' => 'required|in:Guardian,Staff,Student,Teacher',
            ]);
            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }
        }

        try {

            $users = [];
            $currentSemester = $this->cache->getDefaultSemesterData(Auth::user()->school_id);
            $sessionYear = $this->cache->getDefaultSessionYear();
            $sessionYearId = $sessionYear->id;
            if (Auth::user()) {
                $searchTerm = $request->search;
                DB::enableQueryLog();
                $users = User::select('id', 'first_name', 'last_name', 'image', 'school_id')->whereNot('id', Auth::user()->id)->where('status', 1);
                if ($request->search) {
                    $search = $request->search;
                    $users->where(function ($query) use ($search) {
                        $query->where('first_name', 'LIKE', "%$search%")
                            ->orWhere('last_name', 'LIKE', "%$search%");
                    });
                }

                if (Auth::user()->hasRole('Student')) {

                    $userId = Auth::user()->id;
                    $student = Auth::user()->student;
                    $classId = $student->class_section->class_id;

                    $class_subject_ids = ClassSubject::where('class_id', $classId)
                        ->where(function ($q) use ($currentSemester) {
                            if ($currentSemester) {
                                $q->where('semester_id', $currentSemester->id)
                                    ->orWhereNull('semester_id');
                            } else {
                                $q->whereNull('semester_id');
                            }
                        })
                        ->pluck('id')
                        ->toArray();

                    $class_teachers = ClassTeacher::where('class_section_id', $student->class_section_id)
                        ->pluck('teacher_id')
                        ->toArray();

                    // Transportation (optional)
                    $allowedUserIds = [];
                    $transportationPayment = TransportationPayment::where('user_id', $userId)
                        ->where('expiry_date', '>', now())
                        ->whereNotNull('route_vehicle_id')
                        ->first();

                    if ($transportationPayment) {
                        $routeVehicle = RouteVehicle::find($transportationPayment->route_vehicle_id);
                        $allowedUserIds = array_filter([
                            $routeVehicle?->driver_id,
                            $routeVehicle?->helper_id,
                        ]);
                    }

                    $users = $users
                        ->with([
                            'subjectTeachers' => function ($q) use ($class_teachers, $class_subject_ids) {
                                $q->whereIn('class_subject_id', $class_subject_ids)
                                    ->whereNotIn('teacher_id', $class_teachers)
                                    ->with('subject:id,name');
                            },
                            'class_teacher.class_section.class.stream',
                            'class_teacher.class_section.section',
                            'class_teacher.class_section.medium',
                            'roles'
                        ])
                        ->where(function ($query) use ($class_subject_ids, $class_teachers, $allowedUserIds, $searchTerm, $sessionYearId) {

                            // 🎓 Academic teachers
                            $query->whereHas('subjectTeachers', function ($q) use ($class_subject_ids, $class_teachers) {
                                $q->whereIn('class_subject_id', $class_subject_ids)
                                    ->whereNotIn('teacher_id', $class_teachers);
                            })
                                ->orWhereHas('class_teacher', function ($q) use ($class_teachers) {
                                $q->whereIn('teacher_id', $class_teachers);
                            });

                            // 🚌 Transportation staff
                            if (!empty($allowedUserIds)) {
                                $query->orWhereIn('id', $allowedUserIds);
                            }

                            $query->orWhereHas('roles', function ($q) {
                                $q->where('custom_role', 1);
                            });

                            $query->whereHas('staff', function ($q) use ($sessionYearId) {
                                $q->where('join_session_year_id', $sessionYearId);
                            });

                            $query->orWhereHas('roles', function ($r) {
                                $r->where('name', 'School Admin');
                            });

                        })
                        ->where(function ($q) use ($searchTerm) {
                            if ($searchTerm) {
                                $q->where('first_name', 'like', "%{$searchTerm}%")
                                    ->orWhere('last_name', 'like', "%{$searchTerm}%");
                            }
                        });
                } else if (Auth::user()->hasRole('Teacher')) { // Teacher login
                    // Get guardian list
                    if ($request->role == 'Guardian') {
                        $validator = Validator::make($request->all(), [
                            'class_section_id' => 'required',
                        ]);
                        if ($validator->fails()) {
                            ResponseService::validationError($validator->errors()->first());
                        }
                        $users = $users->role(['Guardian'])->whereHas('child', function ($q) use ($request, $sessionYearId) {
                            $q->where('school_id', Auth::user()->school_id)
                                ->where('session_year_id', $sessionYearId)
                                ->where('class_section_id', $request->class_section_id);
                        })->with('child:id,user_id,guardian_id,class_section_id', 'child.user:id,first_name,last_name,image');
                    } else if ($request->role == 'Staff') {

                        $userId = Auth::user()->id;
                        $allowedUserIds = [];
                        if ($userId) {
                            $transportationPayment = TransportationPayment::where('user_id', $userId)
                                ->where('expiry_date', '>', Carbon::now())
                                ->whereNotNull('route_vehicle_id')
                                ->first();

                            if ($transportationPayment) {
                                $routeVehicle = RouteVehicle::find($transportationPayment->route_vehicle_id);

                                // ✅ driver_id & helper_id are USER IDs
                                $allowedUserIds = array_filter([
                                    $routeVehicle?->driver_id,
                                    $routeVehicle?->helper_id,
                                ]);
                            }
                        }
                        // Get staff list

                        $users = $users
                            ->where('school_id', Auth::user()->school_id)
                            ->where(function ($q) use ($allowedUserIds, $sessionYearId) {

                                // (staff AND custom_role)
                                $q->where(function ($q2) use ($sessionYearId) {
                                    $q2->whereHas('staff', function ($q) use ($sessionYearId) {
                                        $q->where('join_session_year_id', $sessionYearId);
                                    })
                                        ->whereHas('roles', function ($r) {
                                            $r->where('custom_role', 1);
                                        });
                                });

                                // OR School Admin
                                $q->orWhereHas('roles', function ($r) {
                                    $r->where('name', 'School Admin');
                                });

                                if (!empty($allowedUserIds)) {
                                    $q->orWhereIn('id', $allowedUserIds);
                                }
                            });
                        // dd($users->get()->toArray());
                    }
                } else if (Auth::user()->hasRole('Guardian')) { // Guardian login

                    $users = $users
                        ->where('school_id', Auth::user()->school_id)
                        ->where(function ($q) use ($sessionYearId) {

                            // Staff users
                            $q->whereHas('staff', function ($q) use ($sessionYearId) {
                                $q->where('join_session_year_id', $sessionYearId);
                            });

                            // OR School Admins (even without staff record)
                            $q->orWhereHas('roles', function ($r) {
                                $r->where('name', 'School Admin');
                            });
                        });
                    $driverId = null;
                    $helperId = null;
                    if ($request->role === 'Staff') {

                        // Always initialize
                        $allowedUserIds = [];

                        if ($request->child_id) {

                            // ✅ FIX: get scalar, not collection
                            $userId = Students::where('id', $request->child_id)->where('session_year_id', $sessionYearId)->value('user_id');

                            if ($userId) {
                                $transportationPayment = TransportationPayment::where('user_id', $userId)
                                    ->where('expiry_date', '>', Carbon::now())
                                    ->whereNotNull('route_vehicle_id')
                                    ->first();

                                if ($transportationPayment) {
                                    $routeVehicle = RouteVehicle::find($transportationPayment->route_vehicle_id);

                                    // ✅ driver_id & helper_id are USER IDs
                                    $allowedUserIds = array_filter([
                                        $routeVehicle?->driver_id,
                                        $routeVehicle?->helper_id,
                                    ]);
                                }
                            }
                        }

                        $users = $users
                            ->with('roles')
                            ->where(function ($query) use ($allowedUserIds) {

                                // Custom staff
                                $query->whereHas('roles', function ($q) {
                                    $q->where('custom_role', 1);
                                });

                                // OR School Admin
                                $query->orWhereHas('roles', function ($q) {
                                    $q->where('name', 'School Admin');
                                });

                                // OR assigned Driver / Helper
                                if (!empty($allowedUserIds)) {
                                    $query->orWhereIn('id', $allowedUserIds);
                                }
                            });
                    } else {
                        if ($request->child_id) {
                            $class_sections = Students::where('id', $request->child_id)->where('session_year_id', $sessionYearId)->pluck('class_section_id')->toArray();
                        } else {
                            $child_ids = Auth::user()->load('child.user')->child->pluck('id')->toArray();
                            $class_sections = Students::whereIn('id', $child_ids)->where('session_year_id', $sessionYearId)->pluck('class_section_id')->toArray();
                        }

                        $class_sections = array_filter($class_sections);

                        $class_teachers = ClassTeacher::whereIn('class_section_id', $class_sections)->pluck('teacher_id')->toArray();
                        $class_teachers = array_unique($class_teachers);
                        $class_teachers = array_values($class_teachers);

                        $subject_teachers = SubjectTeacher::whereIn('class_section_id', $class_sections)->pluck('teacher_id')->toArray();
                        $subject_teachers = array_unique($subject_teachers);
                        $subject_teachers = array_values($subject_teachers);

                        $teacher_ids = array_merge($class_teachers, $subject_teachers);
                        $teacher_ids = array_unique($teacher_ids);
                        $teacher_ids = array_values($teacher_ids);

                        $users = $users->whereIn('id', $teacher_ids)->with([
                            'subjectTeachers' => function ($q) use ($class_sections) {
                                $q->whereIn('class_section_id', $class_sections)
                                    ->with('subject:id,name');
                            }
                        ])
                            ->where(function ($query) use ($searchTerm) {
                                $query->where('first_name', 'like', '%' . $searchTerm . '%')
                                    ->orWhere('last_name', 'like', '%' . $searchTerm . '%');
                            })
                            ->with([
                                'class_teacher' => function ($q) use ($class_sections) {
                                    $q->whereIn('class_section_id', $class_sections)
                                        ->with('class_section.class.stream', 'class_section.section', 'class_section.medium');
                                }
                            ])
                            ->whereHas('staff', function ($q) use ($sessionYearId) {
                                $q->where('join_session_year_id', $sessionYearId);
                            });
                    }



                    // =====================
                    // $users = $users->whereHas('subjectTeachers', function($q) use($class_sections) {
                    //     $q->whereIn('class_section_id', $class_sections);
                    // })
                    // ->with(['subjectTeachers' => function($q) use($class_sections){
                    //     $q->whereIn('class_section_id', $class_sections)
                    //     ->with('subject:id,name');
                    // }])
                    // ->where(function($query) use ($searchTerm) {
                    //     $query->where('first_name', 'like', '%' . $searchTerm . '%')
                    //           ->orWhere('last_name', 'like', '%' . $searchTerm . '%');
                    // })
                    // ->orWhereHas('class_teacher',function($q) use($class_sections) {
                    //     $q->whereIn('class_section_id', $class_sections);
                    // })
                    // ->with(['class_teacher' => function($q) use($class_sections) {
                    //     $q->whereIn('class_section_id',$class_sections)
                    //     ->with('class_section.class.stream','class_section.section','class_section.medium');
                    // }])
                    // ->has('staff');


                } else if (Auth::user()->hasRole('School Admin')) { // Admin or Super Admin login
                    if ($request->role == 'Guardian') {
                        $validator = Validator::make($request->all(), [
                            'class_section_id' => 'required',
                        ]);
                        if ($validator->fails()) {
                            ResponseService::validationError($validator->errors()->first());
                        }
                        $users = $users->role(['Guardian'])->whereHas('child', function ($q) use ($request, $sessionYearId) {
                            $q->where('school_id', Auth::user()->school_id)
                                ->where('session_year_id', $sessionYearId)
                                ->where('class_section_id', $request->class_section_id);
                        })->with('child:id,user_id,guardian_id,class_section_id', 'child.user:id,first_name,last_name,image');
                    } else if ($request->role == 'Staff') {
                        // Get staff list

                        $users = $users
                            ->where('school_id', Auth::user()->school_id)
                            ->whereHas('staff', function ($q) use ($sessionYearId) {
                                $q->where('join_session_year_id', $sessionYearId);
                            })
                            ->where(function ($q) {

                                // custom staff
                                $q->whereHas('roles', function ($r) {
                                    $r->where('custom_role', 1);
                                });

                                // OR Driver / Helper (custom_role = 0)
                                $q->orWhereHas('roles', function ($r) {
                                    $r->where('custom_role', 0)
                                        ->whereIn('name', ['Driver', 'Helper']);
                                });
                            });
                    }
                } else if (Auth::user()->hasRole('Staff') || Auth::user()->roles->first()->custom_role == 1 || Auth::user()->hasRole('Driver') || Auth::user()->hasRole('Helper')) { // Staff login
                    if ($request->role == 'Guardian') {
                        $validator = Validator::make($request->all(), [
                            'class_section_id' => 'required',
                        ]);
                        if ($validator->fails()) {
                            ResponseService::validationError($validator->errors()->first());
                        }
                        $users = $users->role(['Guardian'])->whereHas('child', function ($q) use ($request, $sessionYearId) {
                            $q->where('school_id', Auth::user()->school_id)
                                ->where('session_year_id', $sessionYearId)
                                ->where('class_section_id', $request->class_section_id);
                        })->with('child:id,user_id,guardian_id,class_section_id', 'child.user:id,first_name,last_name,image');
                    } else if ($request->role == 'Staff') {
                        // Get staff list

                        $users = $users->where('school_id', Auth::user()->school_id)
                            ->whereHas('staff', function ($q) use ($sessionYearId) {
                                $q->where('join_session_year_id', $sessionYearId);
                            })
                            ->whereHas('roles', function ($q) {
                                $q->where('custom_role', 1);
                            })->orWhereHas('roles', function ($q) {
                                $q->where('name', 'School Admin');
                            });
                    } else if ($request->role == 'Student') {
                        $validator = Validator::make($request->all(), [
                            'class_section_id' => 'required',
                        ]);
                        if ($validator->fails()) {
                            ResponseService::validationError($validator->errors()->first());
                        }
                        $users = $users->role(['Student'])->whereHas('student', function ($q) use ($request, $sessionYearId) {
                            $q->where('school_id', Auth::user()->school_id)
                                ->where('session_year_id', $sessionYearId)
                                ->where('class_section_id', $request->class_section_id);
                        })->with('student:id,user_id,class_section_id', 'student.class_section:id,class_id,section_id,medium_id', 'student.class_section.class:id,name', 'student.class_section.section:id,name', 'student.class_section.medium:id,name');
                    }
                } else { // Staff login
                    $users = $users->where('school_id', Auth::user()->school_id);
                }
                if ($request->role == 'Staff') {
                    $users = $users->whereHas('roles', function ($q) use ($request) {
                        $q->whereNotIn('name', ['Student', 'Guardian']);
                    })->orderBy('first_name', 'ASC')->with('roles')->paginate(10);
                } else {
                    $users = $users->whereHas('roles', function ($q) use ($request) {
                        $q->where('name', $request->role);
                    })->orderBy('first_name', 'ASC')->with('roles')->paginate(10);
                }
            }

            ResponseService::successResponse("Data Fetched Successfully", $users);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function usersChatHistory(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'role' => 'required|in:Guardian,Staff,Student,Teacher',
        ], [
            'role.required' => 'The role field is mandatory. Please select a role.',
            'role.in' => 'The selected role is invalid. Valid roles are: Guardian, Staff, Student, Teacher.',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = [];
            $userId = Auth::id();
            $search = $request->search;

            if (Auth::user()) {

                $data = Chat::where(function ($q) use ($userId) {
                    $q->where('sender_id', $userId)
                        ->orWhere('receiver_id', $userId);
                })
                    ->where(function ($q) use ($request, $userId) {

                        switch ($request->role) {
                            case 'Staff':
                                $roleNames = ['Driver', 'Helper', 'Teacher'];
                                if (Auth::user()->hasRole('Guardian')) {
                                    $roleNames = ['Driver', 'Helper'];
                                }
                                $q->whereHas('receiver', function ($receiverQ) use ($userId, $roleNames) {
                                    $receiverQ->where('id', '!=', $userId)
                                        ->whereHas('roles', function ($roleQ) use ($roleNames) {
                                            $roleQ->where('custom_role', 1)
                                                ->orWhere(function ($q2) use ($roleNames) {
                                                    $q2->where('custom_role', 0)
                                                        ->whereIn('name', $roleNames);
                                                });
                                        });
                                })
                                    ->orWhereHas('sender', function ($senderQ) use ($userId, $roleNames) {
                                        $senderQ->where('id', '!=', $userId)
                                            ->whereHas('roles', function ($roleQ) use ($roleNames) {
                                                $roleQ->where('custom_role', 1)
                                                    ->orWhere(function ($q2) use ($roleNames) {
                                                        $q2->where('custom_role', 0)
                                                            ->whereIn('name', $roleNames);
                                                    });
                                            });
                                    });
                                break;

                            case 'Teacher':
                                $q->whereHas('receiver', function ($receiverQ) use ($userId) {
                                    $receiverQ->where('id', '!=', $userId)
                                        ->whereHas('roles', fn($roleQ) => $roleQ->where('name', 'Teacher'));
                                })
                                    ->orWhereHas('sender', function ($senderQ) use ($userId) {
                                        $senderQ->where('id', '!=', $userId)
                                            ->whereHas('roles', fn($roleQ) => $roleQ->where('name', 'Teacher'));
                                    });
                                break;

                            case 'Student':
                            case 'Guardian':
                                $role = $request->role;
                                $q->whereHas('receiver', function ($receiverQ) use ($role, $userId) {
                                    $receiverQ->where('id', '!=', $userId)
                                        ->role([$role]);
                                })
                                    ->orWhereHas('sender', function ($senderQ) use ($role, $userId) {
                                        $senderQ->where('id', '!=', $userId)
                                            ->role([$role]);
                                    });
                                break;
                        }
                    })
                    ->withCount([
                        'message as unread_count' => function ($q) use ($userId) {
                            $q->where('read_at', null)
                                ->where('sender_id', '!=', $userId);
                        }
                    ])
                    ->when($search, function ($q) use ($search, $userId) {
                        $q->where(function ($query) use ($search, $userId) {
                            // Search in receiver's name
                            $query->whereHas('receiver', function ($receiverQ) use ($search, $userId) {
                                $receiverQ->where('id', '!=', $userId)
                                    ->where(function ($nameQ) use ($search) {
                                        $nameQ->where('first_name', 'LIKE', "%$search%")
                                            ->orWhere('last_name', 'LIKE', "%$search%")
                                            ->orWhereRaw("concat(first_name,' ',last_name) LIKE ?", ["%$search%"]);
                                    });
                            })
                                // Search in sender's name
                                ->orWhereHas('sender', function ($senderQ) use ($search, $userId) {

                                $senderQ->where('id', '!=', $userId)
                                    ->where(function ($nameQ) use ($search) {
                                        $nameQ->where('first_name', 'LIKE', "%$search%")
                                            ->orWhere('last_name', 'LIKE', "%$search%")
                                            ->orWhereRaw("concat(first_name,' ',last_name) LIKE ?", ["%$search%"]);
                                    });
                            });
                        });
                    })
                    ->paginate(10);
            }
            ResponseService::successResponse("Data Fetched Successfully", $data);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }


    public function classSectionTeachers(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'class_section_id' => 'required'
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {

            $users = $this->user->builder()->role(['Teacher'])->select('id', 'first_name', 'last_name', 'image')
                ->whereHas('class_teacher', function ($q) use ($request) {
                    $q->where('class_section_id', $request->class_section_id);
                })
                ->orWhereHas('subjectTeachers', function ($q) use ($request) {
                    $q->where('class_section_id', $request->class_section_id);
                })
                // ->with(['class_teacher' => function($q) use($request) {
                //     $q->where('class_section_id', $request->class_section_id);
                // }])
                // ->with(['subjectTeachers' => function($q) use($request) {
                //     $q->where('class_section_id', $request->class_section_id);
                // }])
                ->get();

            ResponseService::successResponse("Data Fetched Successfully", $users);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function schoolDetails(Request $request)
    {
        try {
            $gallery_images = [];
            $school_code = $request->header('school-code');

            if ($school_code) {
                $school = School::on('mysql')->where('code', $school_code)->first();

                if ($school) {
                    DB::setDefaultConnection('school');
                    Config::set('database.connections.school.database', $school->database_name);
                    DB::purge('school');
                    DB::connection('school')->reconnect();
                    DB::setDefaultConnection('school');

                    $names = array('school_name', 'school_tagline', 'horizontal_logo');

                    $settings = $this->schoolSettings->getBulkData($names);

                    $gallery = $this->gallery->builder()->with('file')->first();
                    if ($gallery) {
                        $gallery_images = $gallery->file->where('file_name', '!=', 'YouTube Link')->pluck('file_url')->toArray();
                    }

                    $schoolDetails = array(
                        'school_name' => $settings['school_name'],
                        'school_tagline' => $settings['school_tagline'],
                        'school_logo' => $settings['horizontal_logo'],
                        'school_images' => $gallery_images ?? []
                    );
                } else {
                    return response()->json(['message' => 'Invalid school code'], 400);
                }
            } else {
                return response()->json(['message' => 'Unauthenticated'], 400);
            }


            ResponseService::successResponse("Data Fetched Successfully", $schoolDetails);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function sendFeeNotification(Request $request)
    {
        try {

            $school_code = $request->header('school-code');

            if ($school_code) {
                $school = School::on('mysql')->where('code', $school_code)->first();

                if ($school) {
                    DB::setDefaultConnection('school');
                    Config::set('database.connections.school.database', $school->database_name);
                    DB::purge('school');
                    DB::connection('school')->reconnect();
                    DB::setDefaultConnection('school');

                    $feesRemainderDuration = $this->schoolSettings->builder()->where('name', 'fees_remainder_duration')->value('data') ?? 2;

                    $feesRemainderDuration = (int) $feesRemainderDuration;

                    if (!$feesRemainderDuration) {
                        return response()->json(['message' => 'Remainder duration not found in settings'], 400);
                    }

                    $classesWithDueDates = $this->fees->builder()->with('installments')->get();

                    $today = Carbon::now();

                    foreach ($classesWithDueDates as $classFee) {

                        $dueDate = Carbon::parse($classFee->due_date);
                        $class_section_id = $this->classSection->builder()->where('class_id', $classFee->class_id)->first();
                        $daysUntilDue = $today->diffInDays($dueDate, false);

                        if ($daysUntilDue <= $feesRemainderDuration && $daysUntilDue >= 0) {

                            $user = $this->student->builder()->whereIn('class_section_id', $class_section_id)->pluck('guardian_id')->toArray();
                            $title = 'Fees Due Reminder';
                            $body = "Pay fees if you didn't paid !!";
                            $type = "fee-reminder";
                            send_notification($user, $title, $body, $type);
                        }
                    }
                } else {
                    return response()->json(['message' => 'Invalid school code'], 400);
                }
            } else {
                return response()->json(['message' => 'Unauthenticated'], 400);
            }


            ResponseService::successResponse("Notification Sent Successfully", );
        } catch (\Throwable $e) {
            ResponseService::logErrorResponse($e);
            return ResponseService::errorResponse();
        }
    }



    public function paymentStatus(Request $request)
    {
        Log::info('Payment Status Callback received.', [
            'school_id' => $request->query('school_id'),
            'reference' => $request->query('reference'),
            'status' => $request->query('status'),
        ]);
        ResponseService::successResponse("Payment Status Callback received.");
    }

    public function flutterwaveFeesWebhook(Request $request)
    {
        Log::info('Flutterwave Fees Webhook received.', [
            'has_payload' => !empty($request->all()),
        ]);
        ResponseService::successResponse("Flutterwave Fees Webhook received.");
    }

    public function flutterwaveSuccessCallback()
    {
        Log::info('Flutterwave Successfully.');
        ResponseService::successResponse("Flutterwave Successfully.");
    }

    public function flutterwaveCancelCallback()
    {
        Log::info('Flutterwave Payment Canceled.');
        ResponseService::successResponse("Flutterwave Payment Canceled.");
    }

    public function getStudentDetails(Request $request)
    {
        try {
            if (!$request->has('student_id') || empty($request->student_id)) {
                return ResponseService::errorResponse("Student ID is required");
            }
            $user = $this->student->builder()
                ->where('user_id', $request->student_id)
                ->with([
                    'user',
                    'class_section.class',
                    'class_section.section',
                    'class_section.medium'
                ])->first();

            if (!$user) {
                return ResponseService::errorResponse("Student not found");
            }

            // Add semester subjects as a property
            $user->subjects = $user->currentSemesterSubjects();

            ResponseService::successResponse("Student Class Details Fetched Successfully", $user);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function pickupPoints(Request $request)
    {
        try {
            $school_code = $request->header('school-code');

            if (!$school_code) {
                return response()->json(['message' => 'Unauthenticated'], 400);
            }

            $school = School::on('mysql')->where('code', $school_code)->first();

            if (!$school) {
                return response()->json(['message' => 'Invalid school code'], 400);
            }

            // Switch DB
            DB::setDefaultConnection('school');
            Config::set('database.connections.school.database', $school->database_name);
            DB::purge('school');
            DB::connection('school')->reconnect();
            DB::setDefaultConnection('school');

            $pickupPoints = $this->pickupPoint->builder()->select('id', 'name', 'latitude', 'longitude')->where('status', 1)->get();

            return ResponseService::successResponse("Pickup points fetched successfully", $pickupPoints);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            return ResponseService::errorResponse();
        }
    }

    public function getStudentDiaries(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required',
        ]);
        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $sortType = strtolower($request->get('sort', 'new'));
            $users = $this->user->builder()
                ->select('id', 'first_name', 'last_name', 'mobile', 'email', 'image', 'dob')
                ->whereHas('roles', function ($q) {
                    $q->where('name', 'Student');
                });

            // Search
            if ($request->search) {
                $users->where(function ($q) use ($request) {
                    $q->where('first_name', 'like', '%' . $request->search . '%')
                        ->orWhere('last_name', 'like', '%' . $request->search . '%')
                        ->orWhere('mobile', 'like', '%' . $request->search . '%')
                        ->orWhere('email', 'like', '%' . $request->search . '%');
                });
            }

            if ($request->student_id) {
                $users->where('id', $request->student_id);
            }

            $diaryStudentFilter = function ($q) use ($request, $sortType) {
                // Category filter
                if ($request->diary_category_id) {
                    $q->whereHas('diary', function ($q) use ($request) {
                        $q->where('diary_category_id', $request->diary_category_id);
                    });
                }

                // Positive / Negative filter
                if ($sortType === 'positive') {
                    $q->whereHas('diary.diary_category', function ($q) {
                        $q->where('type', 'positive');
                    });
                }
                if ($sortType === 'negative') {
                    $q->whereHas('diary.diary_category', function ($q) {
                        $q->where('type', 'negative');
                    });
                }

                // Subject filter
                if ($request->subject_id) {
                    $q->whereHas('diary.subject', function ($q) use ($request) {
                        $q->where('id', $request->subject_id);
                    });
                }

                // Sorting
                if ($sortType === 'new') {
                    $q->orderBy('created_at', 'DESC');
                }
                if ($sortType === 'old') {
                    $q->orderBy('created_at', 'ASC');
                }
            };

            $users->whereHas('diary_student', $diaryStudentFilter);

            $users->with([
                'diary_student' => $diaryStudentFilter,
                'diary_student.diary.subject',
                'diary_student.diary.diary_category' => function ($q) {
                    $q->withTrashed();
                }
            ]);

            $sql = $users->orderBy('id', 'DESC')->paginate(10);

            ResponseService::successResponse("Student Diaries Fetched Successfully", $sql);
        } catch (\Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getTeachers(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            // Get student by user_id (student_id is the user_id)
            $student = $this->student->builder()
                ->where('user_id', $request->student_id)
                ->whereHas('user', function ($q) {
                    $q->whereNull('deleted_at');
                })
                ->first();

            if (empty($student)) {
                ResponseService::errorResponse("Student Account is not Active. Contact School Support", NULL, config('constants.RESPONSE_CODE.INACTIVE_CHILD'));
            }

            // Get class subject IDs for the student
            $class_subject_id = $student->selectedStudentSubjects()->pluck('class_subject_id');

            // Get subject teachers for the student's class section and subjects
            $subjectTeachers = SubjectTeacher::select('id', 'subject_id', 'teacher_id', 'school_id')
                ->whereIn('class_subject_id', $class_subject_id)
                ->where('class_section_id', $student->class_section_id)
                ->whereHas('teacher', fn($q) => $q->where('status', 1))
                ->with([
                    'subject:id,name,type',
                    'teacher:id,first_name,last_name,image,mobile'
                ])
                ->get();

            ResponseService::successResponse("Teacher Details Fetched Successfully", $subjectTeachers);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getNotifications(Request $request)
    {
        try {

            $offset = $request->offset ?? 0;
            $limit = $request->limit ?? 10;
            $user = Auth::user();
            if (!$user) {
                return ResponseService::errorResponse("User not authenticated");
            }

            // Only notifications belonging to this user (directly attached)
            $user_notifications = UserNotification::where('user_id', $user->id)->pluck('notification_id')->toArray();
            $notifications = Notification::whereIn('id', $user_notifications)->orderBy('id', 'DESC')->offset($offset)->limit($limit)->get();

            return ResponseService::successResponse("Notifications Fetched Successfully", $notifications);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            return ResponseService::errorResponse();
        }
    }

    public function getFirebaseConfig()
    {
        try {
            // Fetch Firebase configuration fields individually and decode HTML entities
            $firebaseConfig = [
                'firebase_api_key' => htmlspecialchars_decode($this->cache->getSystemSettings('firebase_api_key') ?? ''),
                'firebase_auth_domain' => htmlspecialchars_decode($this->cache->getSystemSettings('firebase_auth_domain') ?? ''),
                'firebase_storage_bucket' => htmlspecialchars_decode($this->cache->getSystemSettings('firebase_storage_bucket') ?? ''),
                'firebase_messaging_sender_id' => htmlspecialchars_decode($this->cache->getSystemSettings('firebase_messaging_sender_id') ?? ''),
                'firebase_app_id' => htmlspecialchars_decode($this->cache->getSystemSettings('firebase_app_id') ?? ''),
                'firebase_measurement_id' => htmlspecialchars_decode($this->cache->getSystemSettings('firebase_measurement_id') ?? ''),
                'firebase_service_file' => htmlspecialchars_decode($this->cache->getSystemSettings('firebase_service_file') ?? ''),
                'firebase_project_id' => htmlspecialchars_decode($this->cache->getSystemSettings('firebase_project_id') ?? '')
            ];

            return ResponseService::successResponse("Data Fetched Successfully", $firebaseConfig);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            return ResponseService::errorResponse();
        }
    }

    public function getSystemSettings(Request $request)
    {
        try {
            $school_code = $request->school_code;

            if ($school_code) {
                $school = School::on('mysql')->where('code', $school_code)->first();
                if ($school) {

                    //   // Switch DB
                    DB::setDefaultConnection('school');
                    Config::set('database.connections.school.database', $school->database_name);
                    DB::purge('school');
                    DB::connection('school')->reconnect();
                    DB::setDefaultConnection('school');

                    $data = [
                        'vertical_logo' => $this->cache->getSchoolSettings('vertical_logo', $school->id) ?? '',
                        'horizontal_logo' => $this->cache->getSchoolSettings('horizontal_logo', $school->id) ?? '',
                        'favicon' => $this->cache->getSchoolSettings('favicon', $school->id) ?? '',
                        'student_web_background_image' => $this->cache->getSystemSettings('student_web_background_image') ?? '',
                        'system_name' => $this->cache->getSchoolSettings('system_name', $school->id) ?? '',
                        'address' => $this->cache->getSystemSettings('address', $school->id) ?? '',
                        'tag_line' => $this->cache->getSchoolSettings('tag_line', $school->id) ?? '',
                        'theme_color' => $this->cache->getSchoolSettings('theme_color', $school->id) ?? '',
                        'mobile' => $this->cache->getSchoolSettings('mobile', $school->id) ?? '',
                        'student_parent_privacy_policy' => url('page/student-parent-privacy-policy') ?? '',
                        'student_terms_condition' => url('page/student-terms-conditions') ?? '',
                    ];

                    return ResponseService::successResponse("System Settings Fetched Successfully", $data);
                } else {
                    return ResponseService::errorResponse("School not found");
                }
            } else {
                $data = [
                    'vertical_logo' => $this->cache->getSystemSettings('vertical_logo') ?? '',
                    'horizontal_logo' => $this->cache->getSystemSettings('horizontal_logo') ?? '',
                    'favicon' => $this->cache->getSystemSettings('favicon') ?? '',
                    'student_web_background_image' => $this->cache->getSystemSettings('student_web_background_image') ?? '',
                    'system_name' => $this->cache->getSystemSettings('system_name') ?? '',
                    'address' => $this->cache->getSystemSettings('address') ?? '',
                    'tag_line' => $this->cache->getSystemSettings('tag_line') ?? '',
                    'theme_color' => $this->cache->getSystemSettings('theme_color') ?? '',
                    'mobile' => $this->cache->getSystemSettings('mobile') ?? '',
                    'student_parent_privacy_policy' => url('page/student-parent-privacy-policy') ?? '',
                    'student_terms_condition' => url('page/student-terms-conditions') ?? '',
                ];
                return ResponseService::successResponse("System Settings Fetched Successfully", $data);
            }
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            return ResponseService::errorResponse();
        }
    }
}
