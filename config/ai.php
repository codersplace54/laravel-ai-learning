<?php

return [
    'base_url' => env('AI_SERVICE_BASE_URL', 'http://127.0.0.1:8001'),
    'secret' => env('AI_SERVICE_SECRET'),
    'timeout' => env('AI_SERVICE_TIMEOUT', 60),
];