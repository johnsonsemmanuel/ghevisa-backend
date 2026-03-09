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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET'),
        'currency' => env('PAYSTACK_CURRENCY', 'GHS'),
        'merchant_email' => env('PAYSTACK_MERCHANT_EMAIL', 'bluespacefinancialcloud@gmail.com'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
    ],

    'stripe' => [
        'public_key' => env('STRIPE_PUBLIC_KEY'),
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'mobile_money' => [
        'mtn' => [
            'api_key' => env('MTN_MOMO_API_KEY'),
            'api_secret' => env('MTN_MOMO_SECRET'),
            'subscription_key' => env('MTN_MOMO_SUBSCRIPTION_KEY'),
            'environment' => env('MTN_MOMO_ENVIRONMENT', 'sandbox'),
        ],
        'vodafone' => [
            'merchant_id' => env('VODAFONE_CASH_MERCHANT_ID'),
            'api_key' => env('VODAFONE_CASH_API_KEY'),
        ],
    ],

    'sms' => [
        'provider' => env('SMS_PROVIDER', 'twilio'),
        'twilio' => [
            'sid' => env('TWILIO_SID'),
            'token' => env('TWILIO_TOKEN'),
            'from' => env('TWILIO_FROM'),
        ],
    ],

    'gcb' => [
        'base_url' => env('GCB_BASE_URL', 'https://epayuat.gcbltd.com:98/paymentgateway'),
        'api_key' => env('GCB_API_KEY', ''),
        'callback_url' => env('GCB_CALLBACK_URL', ''),
        'callback_secret' => env('GCB_CALLBACK_SECRET', ''),
        'test_mode' => env('GCB_TEST_MODE', true),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY', ''),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
    ],

    'eta' => [
        'callback_secret' => env('ETA_CALLBACK_SECRET', ''),
    ],

    // HIGH-05: Configurable exchange rates (replace with live API in production)
    'exchange_rates' => [
        'USD' => (float) env('EXCHANGE_RATE_USD', 1),
        'GHS' => (float) env('EXCHANGE_RATE_GHS', 12.5),
        'EUR' => (float) env('EXCHANGE_RATE_EUR', 0.92),
        'GBP' => (float) env('EXCHANGE_RATE_GBP', 0.79),
    ],

];
