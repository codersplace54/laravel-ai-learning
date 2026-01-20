<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PAN OPV API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for PAN Online Verification API integration
    |
    */

    'user_id' => env('PAN_USER_ID', 'V0304801'),
    
    'api_url' => env('PAN_API_URL', 'https://opvapi.egov.proteantech.in/TIN/PanInquiryAPIBackEnd'),
    
    'pfx_path' => env('PAN_PFX_PATH') ?: storage_path('certificates/ilogitron_dsc.pfx'),
    
    'pfx_password' => env('PAN_PFX_PASSWORD', '12345678'),
    
    'version' => env('PAN_VERSION', '4'),
    
    'timeout' => env('PAN_TIMEOUT', 30),
    
    'ssl_verify' => env('PAN_SSL_VERIFY', false),
];