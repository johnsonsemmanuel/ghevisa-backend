<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Alert Channels Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the various alert channels available for sending notifications.
    | Each channel can be enabled/disabled and has its own configuration.
    |
    */

    'channels' => [
        'email' => [
            'enabled' => env('ALERT_EMAIL_ENABLED', true),
            'from' => env('ALERT_EMAIL_FROM', 'alerts@evisa.gov.gh'),
            'from_name' => env('ALERT_EMAIL_FROM_NAME', 'Ghana eVisa Alerts'),
        ],

        'sms' => [
            'enabled' => env('ALERT_SMS_ENABLED', false),
            'provider' => 'twilio',
            'from' => env('TWILIO_FROM'),
            'account_sid' => env('TWILIO_ACCOUNT_SID'),
            'auth_token' => env('TWILIO_AUTH_TOKEN'),
        ],

        'slack' => [
            'enabled' => env('ALERT_SLACK_ENABLED', false),
            'webhook_url' => env('SLACK_WEBHOOK_URL'),
            'channel' => env('SLACK_ALERT_CHANNEL', '#alerts'),
            'username' => env('SLACK_ALERT_USERNAME', 'eVisa Alerts'),
        ],

        'teams' => [
            'enabled' => env('ALERT_TEAMS_ENABLED', false),
            'webhook_url' => env('TEAMS_WEBHOOK_URL'),
        ],

        'pagerduty' => [
            'enabled' => env('ALERT_PAGERDUTY_ENABLED', false),
            'integration_key' => env('PAGERDUTY_INTEGRATION_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Alert Rules
    |--------------------------------------------------------------------------
    |
    | These are the default alert rules that will be created when the system
    | is first set up. They can be modified or disabled later.
    |
    */

    'default_rules' => [
        [
            'name' => 'SLA Breach - Critical',
            'description' => 'Alert when average response time exceeds 2 seconds for 5 minutes',
            'metric' => 'average_response_time',
            'condition' => '>',
            'threshold' => 2000, // milliseconds
            'duration_minutes' => 5,
            'severity' => 'critical',
            'channels' => ['email', 'sms', 'pagerduty'],
            'recipients' => [
                'admin@evisa.gov.gh',
                '+233XXXXXXXXX',
            ],
            'cooldown_minutes' => 30,
            'enabled' => true,
        ],

        [
            'name' => 'Success Rate Low - High',
            'description' => 'Alert when success rate drops below 90%',
            'metric' => 'success_rate',
            'condition' => '<',
            'threshold' => 90,
            'duration_minutes' => 0,
            'severity' => 'high',
            'channels' => ['email', 'slack'],
            'recipients' => [
                'admin@evisa.gov.gh',
                'ops@evisa.gov.gh',
            ],
            'cooldown_minutes' => 15,
            'enabled' => true,
        ],

        [
            'name' => 'Success Rate Very Low - Critical',
            'description' => 'Alert when success rate drops below 80%',
            'metric' => 'success_rate',
            'condition' => '<',
            'threshold' => 80,
            'duration_minutes' => 0,
            'severity' => 'critical',
            'channels' => ['email', 'sms', 'slack', 'pagerduty'],
            'recipients' => [
                'admin@evisa.gov.gh',
                'ops@evisa.gov.gh',
                '+233XXXXXXXXX',
            ],
            'cooldown_minutes' => 10,
            'enabled' => true,
        ],

        [
            'name' => 'Slow Requests High - Warning',
            'description' => 'Alert when slow request percentage exceeds 20%',
            'metric' => 'slow_request_percentage',
            'condition' => '>',
            'threshold' => 20,
            'duration_minutes' => 10,
            'severity' => 'warning',
            'channels' => ['email', 'slack'],
            'recipients' => [
                'ops@evisa.gov.gh',
            ],
            'cooldown_minutes' => 60,
            'enabled' => true,
        ],

        [
            'name' => 'High Request Volume - Info',
            'description' => 'Alert when request volume exceeds 5000 per hour',
            'metric' => 'request_volume',
            'condition' => '>',
            'threshold' => 5000,
            'duration_minutes' => 0,
            'severity' => 'info',
            'channels' => ['email'],
            'recipients' => [
                'ops@evisa.gov.gh',
            ],
            'cooldown_minutes' => 120,
            'enabled' => true,
        ],

        [
            'name' => 'Very High Request Volume - Warning',
            'description' => 'Alert when request volume exceeds 8000 per hour',
            'metric' => 'request_volume',
            'condition' => '>',
            'threshold' => 8000,
            'duration_minutes' => 0,
            'severity' => 'warning',
            'channels' => ['email', 'slack'],
            'recipients' => [
                'admin@evisa.gov.gh',
                'ops@evisa.gov.gh',
            ],
            'cooldown_minutes' => 60,
            'enabled' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for how alerts are processed and evaluated.
    |
    */

    'processing' => [
        // How often to check alert rules (in minutes)
        'check_interval' => env('ALERT_CHECK_INTERVAL', 5),

        // Maximum number of alerts to process in one batch
        'batch_size' => env('ALERT_BATCH_SIZE', 50),

        // Timeout for webhook requests (in seconds)
        'webhook_timeout' => env('ALERT_WEBHOOK_TIMEOUT', 10),

        // Retry failed notifications
        'retry_failed' => env('ALERT_RETRY_FAILED', true),
        'max_retries' => env('ALERT_MAX_RETRIES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Severity Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for alert severity levels and their properties.
    |
    */

    'severity' => [
        'info' => [
            'color' => '#00BFFF',
            'icon' => 'ℹ️',
            'priority' => 1,
        ],
        'warning' => [
            'color' => '#FFD700',
            'icon' => '⚠️',
            'priority' => 2,
        ],
        'high' => [
            'color' => '#FF8C00',
            'icon' => '🔥',
            'priority' => 3,
        ],
        'critical' => [
            'color' => '#FF0000',
            'icon' => '🚨',
            'priority' => 4,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics Configuration
    |--------------------------------------------------------------------------
    |
    | Available metrics that can be used in alert rules.
    |
    */

    'metrics' => [
        'average_response_time' => [
            'name' => 'Average Response Time',
            'unit' => 'seconds',
            'description' => 'Average response time for verification requests',
        ],
        'success_rate' => [
            'name' => 'Success Rate',
            'unit' => 'percentage',
            'description' => 'Percentage of successful verification requests',
        ],
        'failure_rate' => [
            'name' => 'Failure Rate',
            'unit' => 'percentage',
            'description' => 'Percentage of failed verification requests',
        ],
        'slow_request_percentage' => [
            'name' => 'Slow Request Percentage',
            'unit' => 'percentage',
            'description' => 'Percentage of requests taking longer than 2 seconds',
        ],
        'request_volume' => [
            'name' => 'Request Volume',
            'unit' => 'count',
            'description' => 'Number of verification requests per hour',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Conditions Configuration
    |--------------------------------------------------------------------------
    |
    | Available conditions for alert rules.
    |
    */

    'conditions' => [
        '>' => 'Greater than',
        '<' => 'Less than',
        '>=' => 'Greater than or equal to',
        '<=' => 'Less than or equal to',
        '==' => 'Equal to',
    ],
];