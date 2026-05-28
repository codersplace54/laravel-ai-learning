<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'otp_sms' => [
        'secret_key' => env('OTP_SMS_SECRET_KEY'),
    ],

    'entity_locker' => [
        'client_id' => env('ENTITY_LOCKER_CLIENT_ID'),
        'client_secret' => env('ENTITY_LOCKER_CLIENT_SECRET'),
        'redirect_uri' => env('ENTITY_LOCKER_REDIRECT_URI'),
        'frontend_redirect_url' => env('FRONTEND_REDIRECT_URL'),
    ],

    'pan_lookup' => [
        'key' => env('PAN_LOOKUP_HMAC_KEY'),
    ],

    'saral_sso' => [
        'secret' => env('SARAL_SSO_SHARED_SECRET'),
    ],

];
