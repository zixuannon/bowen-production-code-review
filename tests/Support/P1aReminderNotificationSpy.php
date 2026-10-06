<?php

namespace App\Http\Controllers\Api;

function send_notification($users, $title, $body, $type, $customData = []): void
{
    if (empty($GLOBALS['p1a_capture_due_date_reminders'])) {
        \send_notification($users, $title, $body, $type, $customData);
        return;
    }

    $GLOBALS['p1a_due_date_reminder_calls'][] = [$users, $title, $body, $type, $customData];
}
