<?php

$development = in_array(env('APP_ENV', 'production'), ['local', 'testing', 'staging'], true);

return [
    'resend_seconds' => $development ? 2 : 45,
    'sms_per_phone_hour' => $development ? 60 : 5,
    'sms_per_ip_hour' => $development ? 240 : 40,
    'login_per_phone' => $development ? 60 : 10,
    'login_per_ip' => $development ? 240 : 60,
    'code_attempts' => $development ? 10 : 5,
    'code_seconds' => 600,
    'reset_seconds' => 900,
    'access_minutes' => 15,
    'refresh_days' => 60,
    'send_sms' => env('MOBILE_AUTH_SEND_SMS', false),
    'demo_phone' => $development ? env('MOBILE_AUTH_TEST_PHONE') : null,
    'demo_code' => $development ? env('MOBILE_AUTH_TEST_CODE') : null,
];
