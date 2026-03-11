<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Aeropass Integration Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Aeropass ↔ E-Visa integration.
    | This includes Interpol nominal checks and E-Visa record lookups.
    |
    */

    'base_url' => env('AEROPASS_BASE_URL'),

    'username' => env('AEROPASS_USERNAME'),

    'password' => env('AEROPASS_PASSWORD'),

    'timeout' => 20,

    'retries' => 3,

    'retry_delay_ms' => 2000,

];
