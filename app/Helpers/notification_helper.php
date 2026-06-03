<?php

use App\Models\User;
use App\Services\CachingService;
use Google\Client;
use Illuminate\Http\Client\Pool;

function send_notification($user, $title, $body, $type, $customData = [])
{
    // Fetch mobile FCM tokens (fcm_id)
    $mobileFcmTokens = User::where('fcm_id', '!=', '')->whereNotNull('fcm_id')->whereIn('id', $user)->get()->pluck('fcm_id');

    // Fetch web FCM tokens (web_fcm)
    $webFcmTokens = User::where('web_fcm', '!=', '')->whereNotNull('web_fcm')->whereIn('id', $user)->get()->pluck('web_fcm');

    // If no tokens found, return early
    if ($mobileFcmTokens->isEmpty() && $webFcmTokens->isEmpty()) {
        return; // No FCM tokens found
    }

    $cache = app(CachingService::class);

    $project_id = $cache->getSystemSettings('firebase_project_id');

    if (!$project_id) {
        return; // Firebase project ID not configured
    }

    $url = 'https://fcm.googleapis.com/v1/projects/' . $project_id . '/messages:send';

    $access_token = getAccessToken();

    // Convert all customData values to strings (FCM requires string values in data payload)
    $customDataStrings = array_map(function ($value) {
        if (is_array($value)) {
            return json_encode($value);
        }
        return (string) $value;
    }, $customData);

    // Send mobile notifications (Android & iOS)
    foreach ($mobileFcmTokens as $FcmToken) {
        $data = [
            "message" => [
                "token" => $FcmToken,
                "notification" => [
                    "title" => $title,
                    "body" => $body,
                    "image" => $customData['image'] ?? null,
                ],
                "data" => array_merge([
                    "title" => $title,
                    "body" => $body,
                    "type" => $type,
                    "image" => $customData['image'] ?? null,
                ], $customDataStrings),
                "android" => [
                    "notification" => [
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                        "sound" => "default"  // This is for Android sound
                    ],
                    "priority" => "high"

                ],
                "apns" => [
                    "headers" => [
                        "apns-priority" => "10" // Set APNs priority to 10 (high) for immediate delivery
                    ],
                    "payload" => array_merge([
                        "aps" => [
                            "alert" => [
                                "title" => $title,
                                "body" => $body,
                            ],
                            "sound" => "default",  // This is for iOS sound
                            "mutable-content" => 1,
                            "content-available" => 1
                        ],
                        "type" => $type
                    ], $customDataStrings)
                ]
            ]
        ];

        sendFcmNotification($url, $access_token, $data);
    }

    // Send web notifications
    foreach ($webFcmTokens as $webFcmToken) {

        $iconUrl = getWebNotificationIcon($customData);

        $webPushNotification = [
            "title" => $title,
            "body" => $body,
            "icon" => $iconUrl,
            "badge" => $iconUrl,
            "requireInteraction" => false,
            "silent" => false,
            "sound" => "default"
        ];


        // Add image if available in customData
        if (isset($customData['image']) && !empty($customData['image'])) {
            // Ensure image is a full URL
            $imageUrl = $customData['image'];
            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $imageUrl = url($imageUrl);
            }
            $webPushNotification["image"] = $imageUrl;
        }

        $data = [
            "message" => [
                "token" => $webFcmToken,

                // REMOVE notification block completely for web
                "data" => array_merge([
                    "title" => $title,
                    "body" => $body,
                    "type" => $type,
                ], $customDataStrings),

                "webpush" => [
                    "notification" => $webPushNotification,
                ]
            ]
        ];

        sendFcmNotification($url, $access_token, $data);
    }
}

/**
 * Send FCM notification via cURL
 */
function sendFcmNotification($url, $access_token, $data)
{
    $encodedData = json_encode($data);

    $headers = [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json',
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedData);

    // Execute post
    $result = curl_exec($ch);
    // dd($result);
    
    if ($result === FALSE) {
        // Log error but continue with other tokens
        error_log('FCM notification failed: ' . curl_error($ch));
    } else {
        // Log response for debugging (optional)
        $response = json_decode($result, true);
        if (isset($response['error'])) {
            error_log('FCM notification error: ' . json_encode($response['error']));
        }
    }

    // Close connection
    curl_close($ch);
}

/**
 * Get web notification icon URL
 */
function getWebNotificationIcon($customData = [])
{
    // Default icon path - adjust based on your project structure
    $defaultIcon = asset('assets/images/favicon.png');

    // If image is provided in customData, use it as icon
    if (isset($customData['image']) && !empty($customData['image'])) {
        // If image is already a full URL, return it
        if (filter_var($customData['image'], FILTER_VALIDATE_URL)) {
            return $customData['image'];
        }
        // Otherwise, it's likely a storage path, convert to URL
        return url($customData['image']);
    }

    return $defaultIcon;
}

function getAccessToken()
{
    $cache = app(CachingService::class);

    $file_name = $cache->getSystemSettings('firebase_service_file');
    $data = explode("storage/", $file_name ?? '');
    $file_name = end($data);

    $file_path = base_path('public/storage/' . $file_name);

    $client = new Client();
    $client->setAuthConfig($file_path);
    $client->setScopes(['https://www.googleapis.com/auth/firebase.messaging']);
    $accessToken = $client->fetchAccessTokenWithAssertion()['access_token'];

    return $accessToken;
}

function buildPayloads(array $userIds, string $title, string $body, string $type, array $customData = [])
{
    // Convert custom data values to strings
    $customDataStrings = array_map(function ($value) {
        return is_array($value) ? json_encode($value) : (string) $value;
    }, $customData);

    // Get FCM tokens
    $mobileTokens = User::whereIn('id', $userIds)
        ->whereNotNull('fcm_id')
        ->where('fcm_id', '!=', '')
        ->pluck('fcm_id')
        ->toArray();

    $webTokens = User::whereIn('id', $userIds)
        ->whereNotNull('web_fcm')
        ->where('web_fcm', '!=', '')
        ->pluck('web_fcm')
        ->toArray();

    // If no tokens → nothing to send
    if (empty($mobileTokens) && empty($webTokens)) {
        return [];
    }

    $cache = app(CachingService::class);
    $projectId = $cache->getSystemSettings('firebase_project_id');

    if (!$projectId) {
        return [];
    }

    $payloads = [];

    // ------------------------------
    // 🔥 Build MOBILE payloads
    // ------------------------------
    foreach ($mobileTokens as $token) {

        $payloads[] = [
            "message" => [
                "token" => $token,
                "notification" => [
                    "title" => $title,
                    "body" => $body,
                ],
                "data" => array_merge([
                    "title" => $title,
                    "body" => $body,
                    "type" => $type,
                ], $customDataStrings),
                "android" => [
                    "notification" => [
                        "sound" => "default",
                        "click_action" => "FLUTTER_NOTIFICATION_CLICK",
                    ],
                    "priority" => "high"
                ],
                "apns" => [
                    "headers" => [
                        "apns-priority" => "10"
                    ],
                    "payload" => [
                        "aps" => [
                            "alert" => [
                                "title" => $title,
                                "body" => $body,
                            ],
                            "sound" => "default",
                            "mutable-content" => 1,
                            "content-available" => 1
                        ]
                    ] + $customDataStrings
                ]
            ]
        ];
    }

    // ------------------------------
    // 🔥 Build WEB payloads
    // ------------------------------
    foreach ($webTokens as $token) {

        $icon = getWebNotificationIcon($customData);

        $webNotification = [
            "title" => $title,
            "body" => $body,
            "icon" => $icon,
            "badge" => $icon,
            "requireInteraction" => false,
            "silent" => false,
        ];

        if (!empty($customData['image'])) {
            $img = $customData['image'];
            $webNotification["image"] = filter_var($img, FILTER_VALIDATE_URL) ? $img : url($img);
        }

        $payloads[] = [
            "message" => [
                "token" => $token,
                "notification" => [
                    "title" => $title,
                    "body" => $body
                ],
                "data" => array_merge([
                    "title" => $title,
                    "body" => $body,
                    "type" => $type,
                ], $customDataStrings),
                "webpush" => [
                    "notification" => $webNotification,
                ]
            ]
        ];
    }

    return $payloads;
}

/**
 * Send all payloads in PARALLEL — super fast.
 */
function sendBulk(array $payloads)
{
    if (empty($payloads)) {
        return;
    }

    $cache = app(CachingService::class);
    $projectId = $cache->getSystemSettings('firebase_project_id');

    if (!$projectId) {
        return;
    }

    $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
    $token = getAccessToken();

    Http::pool(function (Pool $pool) use ($payloads, $url, $token) {

        $requests = [];

        foreach ($payloads as $payload) {
            $requests[] = $pool
                ->withToken($token)
                ->post($url, $payload);
        }

        return $requests;
    });
}
