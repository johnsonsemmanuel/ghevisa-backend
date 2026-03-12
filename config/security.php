<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Virus Scanning Configuration
    |--------------------------------------------------------------------------
    |
    | CRITICAL SECURITY FIX: ClamAV integration for malware detection
    | 
    | MANDATORY IN PRODUCTION to prevent:
    | - Malware uploads compromising server
    | - Infected documents reaching officers
    | - Ransomware attacks
    | - Data exfiltration
    | 
    | Real-world impact:
    | - Officer computers infected by malicious passport scans
    | - Server compromise via uploaded malware
    | - Ransomware encrypting visa database
    | - National security breach
    |
    */

    'virus_scan' => [
        // CRITICAL: Mandatory in production, optional in development
        'enabled' => env('VIRUS_SCAN_ENABLED', config('app.env') === 'production'),
        
        // Fail-secure: Reject file if scan fails (don't allow unscanned files)
        'fail_on_error' => env('VIRUS_SCAN_FAIL_ON_ERROR', config('app.env') === 'production'),
        
        // Quarantine suspicious files for manual review
        'quarantine_suspicious' => env('VIRUS_SCAN_QUARANTINE', true),
        
        // ClamAV connection settings
        'socket' => env('CLAMAV_SOCKET', '/var/run/clamav/clamd.ctl'),
        'host' => env('CLAMAV_HOST', 'localhost'),
        'port' => env('CLAMAV_PORT', 3310),
        
        // Connection timeout (seconds)
        'timeout' => env('CLAMAV_TIMEOUT', 30),
        
        // Alert security team on malware detection
        'alert_on_detection' => env('VIRUS_SCAN_ALERT', config('app.env') === 'production'),
        'alert_email' => env('SECURITY_ALERT_EMAIL', 'security@ghevisa.gov.gh'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Interpol Integration Configuration
    |--------------------------------------------------------------------------
    |
    | SECURITY FIX: Automated Interpol checks for all applications
    |
    */

    'interpol' => [
        // Auto-trigger Interpol checks on application submission
        'auto_trigger' => env('INTERPOL_AUTO_TRIGGER', true),
        
        // Block application if Interpol check fails (false = flag for manual review)
        'block_on_failure' => env('INTERPOL_BLOCK_ON_FAILURE', false),
        
        // Retry failed checks
        'retry_failed' => env('INTERPOL_RETRY_FAILED', true),
        'retry_attempts' => env('INTERPOL_RETRY_ATTEMPTS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Duplicate Detection Configuration
    |--------------------------------------------------------------------------
    |
    | SECURITY FIX: Prevent multiple applications with same passport
    |
    */

    'duplicate_detection' => [
        // Enable duplicate passport detection
        'enabled' => env('DUPLICATE_DETECTION_ENABLED', true),
        
        // Statuses that count as "active" for duplicate detection
        'active_statuses' => [
            'draft',
            'submitted',
            'submitted_awaiting_payment',
            'pending_payment',
            'paid_submitted',
            'under_review',
            'pending_approval',
            'approved',
            'issued',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bot Protection Configuration
    |--------------------------------------------------------------------------
    |
    | SECURITY FIX: reCAPTCHA integration
    |
    */

    'recaptcha' => [
        // Enable reCAPTCHA
        'enabled' => env('RECAPTCHA_ENABLED', config('app.env') === 'production'),
        
        // reCAPTCHA v3 score threshold (0.0 to 1.0, higher = more human-like)
        'score_threshold' => env('RECAPTCHA_SCORE_THRESHOLD', 0.5),
        
        // Endpoints that require reCAPTCHA
        'protected_endpoints' => [
            'register',
            'login',
            'application.store',
            'payment.initialize',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Identity Verification Configuration
    |--------------------------------------------------------------------------
    |
    | SECURITY FIX: SumSub/Onfido integration (infrastructure ready)
    |
    */

    'identity_verification' => [
        // Enable identity verification (requires provider setup)
        'enabled' => env('IDENTITY_VERIFICATION_ENABLED', false),
        
        // Provider: sumsub, onfido, or custom
        'provider' => env('IDENTITY_VERIFICATION_PROVIDER', 'sumsub'),
        
        // Block application if verification fails
        'block_on_failure' => env('IDENTITY_VERIFICATION_BLOCK', true),
        
        // Verification level: basic, standard, enhanced
        'verification_level' => env('IDENTITY_VERIFICATION_LEVEL', 'standard'),
    ],

    /*
    |--------------------------------------------------------------------------
    | MRZ Validation Configuration
    |--------------------------------------------------------------------------
    |
    | SECURITY FIX: Machine Readable Zone validation
    |
    */

    'mrz_validation' => [
        // Enable MRZ validation
        'enabled' => env('MRZ_VALIDATION_ENABLED', false),
        
        // Require MRZ validation for approval
        'required_for_approval' => env('MRZ_REQUIRED_FOR_APPROVAL', false),
        
        // Strict mode: reject if MRZ doesn't match application data
        'strict_mode' => env('MRZ_STRICT_MODE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | File Upload Security
    |--------------------------------------------------------------------------
    */

    'file_upload' => [
        'max_size' => env('MAX_UPLOAD_SIZE', 10485760), // 10MB
        'allowed_mime_types' => [
            'image/jpeg',
            'image/png',
            'application/pdf',
        ],
        'allowed_extensions' => [
            'jpg',
            'jpeg',
            'png',
            'pdf',
        ],
    ],

];
