<?php

return [
    'enabled' => (bool) env('XIAOBAILONG_ENABLED', false),
    'service_secret' => (string) env('XIAOBAILONG_SERVICE_SECRET', ''),
    'launch_ttl_seconds' => (int) env('XIAOBAILONG_LAUNCH_TTL_SECONDS', 60),
    'embed_launch_path' => (string) env('XIAOBAILONG_EMBED_LAUNCH_PATH', '/xiaobailong/integrations/bowen/launch'),
    'issuer' => (string) env('XIAOBAILONG_ISSUER', 'bowen'),
    'lifecycle_notifications_enabled' => (bool) env('XIAOBAILONG_LIFECYCLE_NOTIFICATIONS_ENABLED', false),
    'status_webhook_url' => (string) env('XIAOBAILONG_STATUS_WEBHOOK_URL', ''),
    'logout_webhook_url' => (string) env('XIAOBAILONG_LOGOUT_WEBHOOK_URL', ''),
    'notification_timeout_seconds' => (int) env('XIAOBAILONG_NOTIFICATION_TIMEOUT_SECONDS', 4),
];
