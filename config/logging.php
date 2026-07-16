<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', env('LOG_STACK', 'single')),
            'ignore_exceptions' => true,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
            'permission' => 0664,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
            'permission' => 0664,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'Laravel Log'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        'payment_cron' => [
            'driver' => 'daily',
            'path' => storage_path('logs/payment_cron.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 60,
            'replace_placeholders' => true,
            'permission' => 0664,
        ],

        'saral_sync' => [
            'driver' => 'daily',
            'path' => storage_path('logs/saral_sync.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 60,
            'replace_placeholders' => true,
            'permission' => 0664,
        ],

        'external_sms' => [
            'driver' => 'daily',
            'path' => storage_path('logs/external_sms.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 60,
            'replace_placeholders' => true,
            'permission' => 0664,
        ],

        'sms' => [
            'driver' => 'daily',
            'path' => storage_path('logs/sms.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 60,
            'replace_placeholders' => true,
            'permission' => 0664
        ],
        
        'whatsapp' => [
            'driver' => 'daily',
            'path' => storage_path('logs/whatsapp.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 60,
            'replace_placeholders' => true,
            'permission' => 0664,
        ],

        'pan_verification' => [
            'driver' => 'daily',
            'path' => storage_path('logs/pan_verification.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 60,
            'replace_placeholders' => true,
            'permission' => 0664,
        ],

        'entity_locker' => [
            'driver' => 'daily',
            'path' => storage_path('logs/entity_locker.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 60,
            'replace_placeholders' => true,
            'permission' => 0664,
        ],

        'payment' => [
            'driver' => 'daily',
            'path' => storage_path('logs/payment.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 60,
            'replace_placeholders' => true,
            'permission' => 0664,
        ],

        'deemed_approval' => [
            'driver' => 'daily',
            'path' => storage_path('logs/deemed_approval.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 60,
            'replace_placeholders' => true,
            'permission' => 0664,
        ],

        'single_window_report' => [
            'driver' => 'daily',
            'path' => storage_path('logs/single_window_report.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 60,
            'replace_placeholders' => true,
            'permission' => 0664,
        ],

        'third_party' => [
            'driver' => 'daily',
            'path' => storage_path('logs/third_party.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 60,
            'replace_placeholders' => true,
            'permission' => 0664,
        ],

        'fix_assignment' => [
            'driver' => 'daily',
            'path' => storage_path('logs/fix_assignment.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 90,
            'replace_placeholders' => true,
            'permission' => 0664,
        ],

        'ai_chat' => [
            'driver' => 'daily',
            'path' => storage_path('logs/ai_chat.log'),
            'level' => 'debug',
            'days' => 30,
            'replace_placeholders' => true,
            'permission' => 0664,
        ],

    ],

];
