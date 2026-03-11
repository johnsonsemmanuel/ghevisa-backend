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
