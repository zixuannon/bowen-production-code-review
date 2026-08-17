<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Services\TenantPasswordBroker;
use App\Providers\RouteServiceProvider;
use Auth;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;


class ResetPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset requests
    | and uses a simple trait to include this behavior. You're free to
    | explore this trait and override any methods you wish to tweak.
    |
    */

    use ResetsPasswords;

    /**
     * Reset tokens for tenant users are stored and verified only in the
     * selected school database, never on a request-global default connection.
     */
    public function broker()
    {
        return app(TenantPasswordBroker::class)->broker();
    }

    protected function reset(Request $request)
    {
        $request->validate(array_merge($this->rules(), [
            'school_code' => ['required', 'string', 'max:64'],
        ]), $this->validationErrorMessages());

        // Resolve the submitted code only through the central registry before
        // selecting a tenant connection. The broker then finds the user inside
        // that tenant and we additionally require the user's school_id to match.
        $school = School::on('mysql')
            ->where('code', $request->school_code)
            ->where('installed', 1)
            ->where('status', 1)
            ->first();

        if (!$school) {
            throw ValidationException::withMessages([
                'school_code' => [__('Invalid school identifier.')],
            ]);
        }

        $previousDefault = DB::getDefaultConnection();
        $previousDatabase = Config::get('database.connections.school.database');

        try {
            Config::set('database.connections.school.database', $school->database_name);
            DB::purge('school');
            DB::connection('school')->reconnect();
            DB::setDefaultConnection('school');

            // Do not permit a reset token/email that resolves to a user whose
            // tenant ownership differs from the trusted school-code context.
            $tenantUser = User::where('email', $request->email)
                ->where('school_id', $school->id)
                ->first();

            if (!$tenantUser) {
                return $this->sendResetFailedResponse($request, Password::INVALID_USER);
            }

            $response = $this->broker()->reset(
                $this->credentials($request), function ($user, $password) {
                    $this->resetPassword($user, $password);
                }
            );
        } finally {
            DB::purge('school');
            Config::set('database.connections.school.database', $previousDatabase);
            DB::setDefaultConnection($previousDefault);
        }

        if ($response == Password::PASSWORD_RESET) {
            Auth::logout();
            return redirect()->route('login')->with('emailSuccess', 'Your password has been successfully updated. Please log in with your new credentials.');
        }

        return $this->sendResetFailedResponse($request, $response);
    }

    /**
     * Where to redirect users after resetting their password.
     *
     * @var string
     */
    // protected $redirectTo = RouteServiceProvider::HOME;
}
