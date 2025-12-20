<?php

return [

    'gateway_url' => env('SMS_GATEWAY_URL'),

    'username' => env('SMS_USERNAME', 'triswag.sms'),
    'pin'      => env('SMS_PIN'),
    'signature'=> env('SMS_SIGNATURE'),

    'dlt_entity_id' => env('SMS_DLT_ENTITY_ID', null),
    'otp_template_id' => env('SMS_DLT_TEMPLATE_ID_OTP', null),

];
