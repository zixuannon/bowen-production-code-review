<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\DingTalkBinding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DingTalkLoginController extends Controller
{
    private const AUTH_URL = 'https://login.dingtalk.com/oauth2/auth';
    private const TOKEN_URL = 'https://api.dingtalk.com/v1.0/oauth2/userAccessToken';
    private const USERINFO_URL = 'https://api.dingtalk.com/v1.0/contact/users/me';

    /**
     * Step 1: 生成 state，保存到 session，跳转钉钉授权页。
     *
     * 可选 query: ?school_code=XXX，用于知道用户从哪个学校入口进入。
     */
    public function login(Request $request)
    {
        $state = Str::random(32);
        session(['dingtalk_oauth_state' => $state]);

        // 如果 URL 携带 school_code，存入 session 以便 callback 使用
        if ($schoolCode = $request->query('school_code')) {
            session(['dingtalk_school_code' => $schoolCode]);
        } else {
            session()->forget('dingtalk_school_code');
        }

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

            // 3. 仅展示脱敏结果，不做登录
            $openId  = $userData['openId'] ?? null;
            $unionId = $userData['unionId'] ?? null;
            $nick    = $userData['nick'] ?? null;

            // 4. 检查主库中是否已有绑定
            $binding = DingTalkBinding::where('dingtalk_open_id', $openId)->first();
            $schoolCodeInSession = session('dingtalk_school_code');

            if ($binding) {
                $lines = [
                    'DingTalk binding found.',
                    'school_id: ' . ($binding->school_id ? 'YES' : 'NO'),
                    'user_id:   ' . ($binding->user_id ? 'YES' : 'NO'),
                ];
            } else {
                $lines = [
                    'DingTalk binding not found.',
                    'school_code in session: ' . ($schoolCodeInSession ? 'YES' : 'NO'),
                    'openId:  ' . ($openId ? 'YES' : 'NO'),
                    'unionId: ' . ($unionId ? 'YES' : 'NO'),
                    'nick:    ' . ($nick ? 'YES (' . mb_strlen($nick) . ' chars)' : 'NO'),
                ];
            }

            return response(implode("\n", $lines));

        } catch (\Throwable $e) {
            Log::error('DingTalk callback exception', [
                'message' => $e->getMessage(),
            ]);
            return response('DingTalk user info fetch FAILED (exception).', 500);
        }
    }
}
