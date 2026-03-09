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
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY', ''),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
    ],

];
