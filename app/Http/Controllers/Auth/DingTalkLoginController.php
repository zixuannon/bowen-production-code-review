<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\DingTalkBinding;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class DingTalkLoginController extends Controller
{
    private const AUTH_URL = 'https://login.dingtalk.com/oauth2/auth';
    private const TOKEN_URL = 'https://api.dingtalk.com/v1.0/oauth2/userAccessToken';
    private const USERINFO_URL = 'https://api.dingtalk.com/v1.0/contact/users/me';

    /**
     * Step 1: 生成 state，保存到 session，跳转钉钉授权页。
     *
     * school_code 改为可选：
     * - 有 ?school_code=XXX → 旧入口，验证并保存到 session
     * - 无 school_code → 统一入口，不预设学校
     */
    public function login(Request $request)
    {
        $rawSchoolCode = $request->query('school_code');

        if (!empty($rawSchoolCode)) {
            // 旧入口：验证 school_code 并保存到 session
            $school = School::on('mysql')->whereCanonicalCode($rawSchoolCode)->first();

            if (!$school) {
                return response('Invalid school_code.', 400);
            }

            session([
                'dingtalk_school_id'            => $school->id,
                'dingtalk_school_code'          => $school->code,
                'dingtalk_school_database_name' => $school->database_name,
            ]);

            Log::info('DingTalk login started with school_code', [
                'school_code' => $school->code,
            ]);
        } else {
            // 统一入口：清除可能的残留学校信息
            session()->forget([
                'dingtalk_school_id',
                'dingtalk_school_code',
                'dingtalk_school_database_name',
            ]);

            Log::info('DingTalk login started without school_code (unified entry)');
        }

        // 清除之前可能残留的 pending 绑定信息
        session()->forget([
            'dingtalk_pending_open_id',
            'dingtalk_pending_union_id',
            'dingtalk_pending_nick',
        ]);

        // 生成 OAuth state 并跳转
        $state = Str::random(32);
        session(['dingtalk_oauth_state' => $state]);

        $query = http_build_query([
            'redirect_uri'  => config('services.dingtalk.redirect_uri'),
            'response_type' => 'code',
            'client_id'     => config('services.dingtalk.client_id'),
            'scope'         => 'openid',
            'state'         => $state,
            'prompt'        => 'consent',
        ]);

        return redirect(self::AUTH_URL . '?' . $query);
    }

    /**
     * Step 2: 钉钉回调，用 authCode 换取 userAccessToken，再获取用户信息。
     *
     * 已绑定 → 自动登录并跳转 /dashboard。
     * 未绑定 → 跳转绑定页。
     */
    public function callback(Request $request)
    {
        $authCode = $request->input('authCode');
        $state    = $request->input('state');

        $savedState = session('dingtalk_oauth_state');
        session()->forget('dingtalk_oauth_state');

        if (empty($state) || $state !== $savedState) {
            return response('State mismatch – possible CSRF attack.', 400);
        }

        if (empty($authCode)) {
            return response('Missing authCode from DingTalk callback.', 400);
        }

        // 从 session 读取学校信息（login() 有 school_code 时设置，无 school_code 时为空）
        $schoolId           = session('dingtalk_school_id');
        $schoolCode         = session('dingtalk_school_code');
        $schoolDatabaseName = session('dingtalk_school_database_name');

        try {
            // 1. 用 authCode 换取 userAccessToken
            $tokenResponse = Http::asJson()->post(self::TOKEN_URL, [
                'clientId'     => config('services.dingtalk.client_id'),
                'clientSecret' => config('services.dingtalk.client_secret'),
                'code'         => $authCode,
                'grantType'    => 'authorization_code',
            ]);

            if (!$tokenResponse->successful()) {
                Log::error('DingTalk token request failed', [
                    'status' => $tokenResponse->status(),
                ]);
                return response('DingTalk user info fetch FAILED (token).', 500);
            }

            $tokenData = $tokenResponse->json();
            $accessToken = $tokenData['accessToken'] ?? null;

            if (empty($accessToken)) {
                Log::error('DingTalk token response missing accessToken');
                return response('DingTalk user info fetch FAILED (token).', 500);
            }

            // 2. 用 userAccessToken 获取用户信息
            // 钉钉要求 x-acs-dingtalk-access-token，不能用 Authorization: Bearer
            $userResponse = Http::withHeaders([
                'x-acs-dingtalk-access-token' => $accessToken,
                'Accept' => 'application/json',
            ])->get(self::USERINFO_URL);

            if (!$userResponse->successful()) {
                $errBody = $userResponse->json();
                Log::error('DingTalk user info request failed', [
                    'status'    => $userResponse->status(),
                    'err_code'  => $errBody['code'] ?? null,
                    'err_msg'   => $errBody['message'] ?? null,
                ]);
                return response('DingTalk user info fetch FAILED (userinfo).', 500);
            }

            $userData = $userResponse->json();
            $openId  = $userData['openId'] ?? null;
            $unionId = $userData['unionId'] ?? null;
            $nick    = $userData['nick'] ?? null;

            // 3. 查询绑定记录
            $binding = DingTalkBinding::where('dingtalk_open_id', $openId)->first();

            if ($binding) {
                // 已绑定：从 binding 记录反查学校信息（不依赖 session）
                $boundSchool = School::on('mysql')->find($binding->school_id);

                if (!$boundSchool) {
                    Log::error('DingTalk callback: bound school not found', [
                        'school_id'      => $binding->school_id,
                        'open_id_masked' => substr($openId, 0, 6) . '****',
                    ]);
                    return response('Bound school not found.', 500);
                }

                // 如果 session 中有 school_id（旧入口带 school_code），校验一致性
                if (!empty($schoolId) && $binding->school_id != $schoolId) {
                    return response('This DingTalk account is not bound to this school.', 403);
                }

                Log::info('DingTalk callback: binding found, auto-login using binding school', [
                    'school_code'    => $boundSchool->code,
                    'school_id'      => $boundSchool->id,
                    'open_id_masked' => substr($openId, 0, 6) . '****',
                ]);

                return $this->autoLogin($request, $binding, $boundSchool->id, $boundSchool->code, $boundSchool->database_name);
            }

            // 4. 无绑定 → 保存钉钉信息到 session
            Log::info('DingTalk callback: no binding found, redirect to bind', [
                'open_id_masked'         => substr($openId, 0, 6) . '****',
                'has_school_in_session'  => !empty($schoolId),
            ]);

            session([
                'dingtalk_pending_open_id'  => $openId,
                'dingtalk_pending_union_id' => $unionId,
                'dingtalk_pending_nick'     => $nick,
            ]);
            // 注意：不修改 dingtalk_school_*，保留 login() 中设置的值（如果有）

            return redirect()->route('dingtalk.bind');

        } catch (\Throwable $e) {
            Log::error('DingTalk callback exception', [
                'stage'   => 'callback',
                'class'   => get_class($e),
                'code'    => $e->getCode(),
            ]);
            return response('DingTalk user info fetch FAILED (exception).', 500);
        }
    }

    /**
     * 已绑定用户自动登录 eSchool。
     *
     * 复用 LoginController 的 session 设置模式：
     * - Session::put('school_database_name', ...) 供 SwitchDatabase middleware 使用
     * - Auth::login() + redirect /dashboard
     */
    private function autoLogin(Request $request, DingTalkBinding $binding, $schoolId, $schoolCode, $schoolDatabaseName)
    {
        // 1. 切换到学校数据库
        Config::set('database.connections.school.database', $schoolDatabaseName);
        DB::purge('school');
        DB::reconnect('school');
        DB::setDefaultConnection('school');

        try {
            // 2. 在学校库查找绑定用户
            $user = User::where('id', $binding->user_id)->first();

            if (!$user) {
                Log::warning('DingTalk auto-login: bound user not found', [
                    'school_code' => $schoolCode,
                    'school_id'   => $schoolId,
                    'user_id'     => $binding->user_id,
                ]);
                return response('Bound eSchool user not found.', 403);
            }

            // 3. 必须是 Teacher（不允许 Student/Guardian web 登录）
            if (!$user->hasRole('Teacher')) {
                Log::warning('DingTalk auto-login: bound account is not a teacher', [
                    'school_code' => $schoolCode,
                    'school_id'   => $schoolId,
                    'user_id'     => $user->id,
                ]);
                return response('Bound account is not a teacher.', 403);
            }

            // 4. 检查账号状态（与 Status middleware 一致：status 必须为 1）
            if ($user->status != 1) {
                Log::warning('DingTalk auto-login: account deactivated', [
                    'school_code' => $schoolCode,
                    'school_id'   => $schoolId,
                    'user_id'     => $user->id,
                ]);
                return response('Bound account is deactivated.', 403);
            }

            // 5. 设置 session（在 Auth::login 之前，与 LoginController 顺序一致）
            // db_connection_name 必须设置：User::getConnectionName() 依赖它选择正确的数据库连接
            session(['db_connection_name' => 'school']);
            session(['user_id'             => $user->id]);
            session(['user_email'          => $user->email]);
            session(['school_id'           => $schoolId]);
            session(['school_code'         => $schoolCode]);

            session()->save();

            // 6. Laravel Auth 登录
            Auth::login($user);

            // 7. 防止 session fixation
            $request->session()->regenerate();

            // school_database_name 供 CheckRole / SwitchDatabase middleware 在下一次请求中重新配置
            Session::put('school_database_name', $schoolDatabaseName);

            // 8. 更新最后登录时间
            $binding->update(['last_login_at' => now()]);

            // 9. 清除 DingTalk 相关 session（保留 login 相关 session）
            session()->forget([
                'dingtalk_pending_open_id',
                'dingtalk_pending_union_id',
                'dingtalk_pending_nick',
                'dingtalk_school_id',
                'dingtalk_school_code',
                'dingtalk_school_database_name',
            ]);

            Log::info('DingTalk auto-login successful', [
                'school_code' => $schoolCode,
                'school_id'   => $schoolId,
                'user_id'     => $user->id,
            ]);

            // 10. 跳转 dashboard
            return redirect('/dashboard');

        } catch (\Throwable $e) {
            Log::error('DingTalk auto-login exception', [
                'stage'   => 'auto-login',
                'class'   => get_class($e),
                'code'    => $e->getCode(),
            ]);
            return response('DingTalk auto-login failed.', 500);
        }
    }

    /**
     * Step 3A (GET): 显示绑定表单。
     */
    public function bindForm(Request $request)
    {
        $pendingOpenId = session('dingtalk_pending_open_id');

        if (empty($pendingOpenId)) {
            return response('DingTalk session expired. Please re-enter from DingTalk.', 400);
        }

        $schoolCodeInSession = session('dingtalk_school_code');

        Log::info('DingTalk bind form opened', [
            'open_id_masked'         => substr($pendingOpenId, 0, 6) . '****',
            'has_school_in_session'  => !empty($schoolCodeInSession),
        ]);

        return view('auth.dingtalk-bind', [
            'schoolCodeInSession' => $schoolCodeInSession,
        ]);
    }

    /**
     * Step 3B (POST): 处理绑定逻辑。
     */
    public function bind(Request $request)
    {
        $request->validate([
            'school_code' => 'required|string',
            'email'       => 'required|string',
            'password'    => 'required|string',
        ]);

        $pendingOpenId  = session('dingtalk_pending_open_id');
        $pendingUnionId = session('dingtalk_pending_union_id');
        $pendingNick    = session('dingtalk_pending_nick');
        // 优先从 request 读 school_code，fallback 到 session（兼容旧入口）
        $schoolCode     = $request->input('school_code') ?: session('dingtalk_school_code');

        if (empty($pendingOpenId)) {
            return redirect()->route('dingtalk.bind')
                ->with('error', 'DingTalk session expired. Please re-enter from DingTalk.');
        }

        if (empty($schoolCode)) {
            return redirect()->route('dingtalk.bind')
                ->with('error', 'Please enter your school code.');
        }

        Log::info('DingTalk bind started', [
            'open_id_masked' => substr($pendingOpenId, 0, 6) . '****',
            'school_code'    => $schoolCode,
        ]);

        // 1. 根据 school_code 在主库查找学校
        $school = School::on('mysql')->whereCanonicalCode($schoolCode)->first();

        if (!$school) {
            Log::warning('DingTalk bind: school not found by code', ['school_code' => $schoolCode]);
            return redirect()->route('dingtalk.bind')
                ->with('error', 'School not found. Please check your school code.');
        }

        // 更新 session 中的学校信息
        session([
            'dingtalk_school_id'   => $school->id,
            'dingtalk_school_code' => $school->code,
        ]);

        // 2. 切换到学校数据库
        $previousConnection = DB::getDefaultConnection();
        Config::set('database.connections.school.database', $school->database_name);
        DB::purge('school');
        DB::reconnect('school');
        DB::setDefaultConnection('school');

        try {
            // 3. 在学校库查找用户（email 或 mobile）
            $loginValue = $request->input('email');
            $user = User::where('email', $loginValue)
                ->orWhere('mobile', $loginValue)
                ->first();

            if (!$user || !Hash::check($request->input('password'), $user->password)) {
                return redirect()->route('dingtalk.bind')
                    ->with('error', 'Invalid credentials.');
            }

            // 4. 必须是 Teacher 角色
            if (!$user->hasRole('Teacher')) {
                Log::warning('DingTalk bind: user is not a teacher');
                return redirect()->route('dingtalk.bind')
                    ->with('error', 'Only teacher accounts can bind with DingTalk.');
            }

            // 5a. 检查当前 DingTalk 账号是否已绑定其他学校
            $crossSchool = DingTalkBinding::where('dingtalk_open_id', $pendingOpenId)
                ->where('school_id', '!=', $school->id)
                ->first();

            if ($crossSchool) {
                Log::warning('DingTalk bind: openId already bound to another school', [
                    'other_school_id' => $crossSchool->school_id,
                ]);
                return redirect()->route('dingtalk.bind')
                    ->with('error', 'This DingTalk account is already bound to another school.');
            }

            // 5b. 检查当前 school_id + user_id 是否已被其他 DingTalk 账号绑定
            $sameUserOtherDingTalk = DingTalkBinding::where('school_id', $school->id)
                ->where('user_id', $user->id)
                ->where('dingtalk_open_id', '!=', $pendingOpenId)
                ->first();

            if ($sameUserOtherDingTalk) {
                Log::warning('DingTalk bind: eSchool user already bound to another DingTalk account');
                return redirect()->route('dingtalk.bind')
                    ->with('error', 'This eSchool account is already bound to another DingTalk account.');
            }

            // 6. 保存绑定记录到主库（显式指定 mysql 连接，防御性编程）
            DingTalkBinding::on('mysql')->create([
                'dingtalk_open_id'  => $pendingOpenId,
                'dingtalk_union_id' => $pendingUnionId,
                'school_id'         => $school->id,
                'school_code'       => $school->code,
                'user_id'           => $user->id,
                'dingtalk_nick'     => $pendingNick,
                'last_login_at'     => now(),
            ]);

            Log::info('DingTalk binding created', [
                'school_code'    => $school->code,
                'school_id'      => $school->id,
                'user_id'        => $user->id,
                'open_id_masked' => substr($pendingOpenId, 0, 6) . '****',
            ]);

            // 7. 设置 session（参考 autoLogin 模式，与 LoginController 顺序一致）
            session(['db_connection_name' => 'school']);
            session(['user_id'             => $user->id]);
            session(['user_email'          => $user->email]);
            session(['school_id'           => $school->id]);
            session(['school_code'         => $school->code]);
            Session::put('school_database_name', $school->database_name);

            session()->save();

            // 8. Laravel Auth 登录
            Auth::login($user);

            // 9. 防止 session fixation
            $request->session()->regenerate();

            // 10. 清除所有 DingTalk session
            session()->forget([
                'dingtalk_pending_open_id',
                'dingtalk_pending_union_id',
                'dingtalk_pending_nick',
                'dingtalk_school_id',
                'dingtalk_school_code',
                'dingtalk_school_database_name',
            ]);

            Log::info('DingTalk bind and auto-login successful', [
                'school_code'    => $school->code,
                'school_id'      => $school->id,
                'user_id'        => $user->id,
                'open_id_masked' => substr($pendingOpenId, 0, 6) . '****',
            ]);

            // 11. 跳转 dashboard
            return redirect('/dashboard')->with('success', __('DingTalk binding created successfully.'));

        } catch (\Throwable $e) {
            Log::error('DingTalk bind exception', [
                'stage'          => 'bind',
                'class'          => get_class($e),
                'code'           => $e->getCode(),
                'message'        => $e->getMessage(),
                'school_code'    => $schoolCode ?? 'unknown',
                'open_id_masked' => $pendingOpenId ? substr($pendingOpenId, 0, 6) . '****' : 'missing',
            ]);
            return redirect()->route('dingtalk.bind')
                ->with('error', 'Binding failed. Please try again later.');
        } finally {
            // 恢复默认数据库连接
            if ($previousConnection !== 'school') {
                DB::setDefaultConnection($previousConnection);
                DB::purge('school');
            }
        }
    }
}
