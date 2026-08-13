<?php

return [
    'enabled' => (bool) env('XIAOBAILONG_ENABLED', false),
    'service_secret' => (string) env('XIAOBAILONG_SERVICE_SECRET', ''),
    'launch_ttl_seconds' => (int) env('XIAOBAILONG_LAUNCH_TTL_SECONDS', 60),
    'embed_launch_path' => (string) env('XIAOBAILONG_EMBED_LAUNCH_PATH', '/xiaobailong/integrations/bowen/launch'),
    'issuer' => (string) env('XIAOBAILONG_ISSUER', 'bowen'),
];
