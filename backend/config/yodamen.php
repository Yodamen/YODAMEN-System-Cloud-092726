<?php

return [
    'business_timezone' => env('YODAMEN_TIMEZONE', 'Asia/Manila'),

    'authentication' => [
        'session_minutes' => (int) env('YODAMEN_SESSION_MINUTES', 360),
        'max_failed_attempts' => (int) env('YODAMEN_MAX_FAILED_ATTEMPTS', 5),
        'lock_minutes' => (int) env('YODAMEN_LOCK_MINUTES', 15),
        'minimum_access_code_length' => (int) env('YODAMEN_MIN_ACCESS_CODE_LENGTH', 6),
    ],
];
