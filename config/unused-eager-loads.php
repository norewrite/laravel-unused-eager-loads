<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Keep this disabled by default and explicitly enable it in development,
    | staging, or a controlled diagnostic environment.
    |
    */
    'enabled' => (bool) env('UNUSED_EAGER_LOADS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Automatic middleware registration
    |--------------------------------------------------------------------------
    |
    | v1 is intentionally server-side Laravel/Blade instrumentation. By
    | default we wrap the web middleware group and report when rendering is
    | complete. Add other groups only when that is useful for your application.
    |
    */
    'middleware' => [
        'auto_register' => true,
        'groups' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reporting
    |--------------------------------------------------------------------------
    */
    'reporting' => [
        'channel' => env('UNUSED_EAGER_LOADS_LOG_CHANNEL'),
        'unused_level' => 'warning',
        'serialization_only_level' => 'info',
        'partial_level' => 'debug',
        'report_serialization_only' => true,
        'report_partial' => false,
        'minimum_loaded' => 1,
        'report_on_error_responses' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignore rules
    |--------------------------------------------------------------------------
    |
    | Patterns use fnmatch syntax. "pivot" is ignored because Eloquent uses it
    | internally for many-to-many pivot models and it is not an eager-load
    | decision made by application code.
    |
    */
    'ignore' => [
        'models' => [],
        'relations' => ['pivot'],
        'paths' => [],
    ],

    /*
    | Maximum frames inspected when confirming that setRelation() happened
    | inside Eloquent's eager-loading pipeline.
    */
    'backtrace_frames' => 32,
];
