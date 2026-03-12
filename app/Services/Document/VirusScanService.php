<?php

namespace App\Services\Document;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * SECURITY FIX HIGH-04: Virus Scanning Service
 * Integrates with ClamAV for malware detection
 */
class VirusScanService
{
    protected bool $enabled;
    protected string $socket;

    public function __construct()
    {
        $this->enabled = config('security.virus_scan.enabled', false);
        $this->socket = config('security.virus_scan.socket', '/var/run/clamav/clamd.ctl');
        
        // CRITICAL SECURITY FIX: Enforce virus scanning in production
        if (config('app.env') === 'production' && !$this->enabled) {
            Log::critical('Virus scanning is DISABLED in PRODUCTION', [
                'environment' => config('app.env'),
                'risk' => 'Malware uploads can compromise system',
            ]);
            
            throw new \RuntimeException(
                'CRITICAL: Virus scanning is disabled in PRODUCTION. ' .
                'This allows malware uploads that can compromise the server and infect officers. ' .
                'Set VIRUS_SCAN_ENABLED=true and install ClamAV immediately.'
            );
        }
        
        // Warn if ClamAV is not available in production
        if ($this->enabled && config('app.env') === 'production' && !$this->isClamAvAvailable()) {
            Log::critical('ClamAV is not available in PRODUCTION', [
                'socket' => $this->socket,
                'host' => config('security.virus_scan.host'),
                'port' => config('security.virus_scan.port'),
            ]);
            
            throw new \RuntimeException(
                'CRITICAL: ClamAV is not available but virus scanning is enabled. ' .
                'Install and start ClamAV daemon: sudo apt-get install clamav clamav-daemon && sudo systemctl start clamav-daemon'
            );
        }
    }

    /**
     * Scan a file for viruses
     * 
     * CRITICAL SECURITY FIX: Fail-secure virus scanning.
     * 
     * If scan fails in production, file is REJECTED (not allowed through).
     * This prevents unscanned files from entering the system.
     * 
     * @param UploadedFile $file
     * @return array ['clean' => bool, 'result' => string, 'threat' => string|null]
     */
    public function scan(UploadedFile $file): array
    {
        if (!$this->enabled) {
            // Only allowed in non-production
            if (config('app.env') === 'production') {
                throw new \RuntimeException('Virus scanning cannot be disabled in production');
            }
            
            Log::info('Virus scanning disabled, skipping scan', [
                'file' => $file->getClientOriginalName(),
                'environment' => config('app.env'),
            ]);
            return ['clean' => true, 'result' => 'SCAN_DISABLED', 'threat' => null];
        }

        if (!$this->isClamAvAvailable()) {
            $failOnError = config('security.virus_scan.fail_on_error', true);
            
            Log::error('ClamAV not available', [
                'file' => $file->getClientOriginalName(),
                'fail_on_error' => $failOnError,
            ]);
            
            // In production, reject file if ClamAV unavailable
            if ($failOnError) {
                return [
                    'clean' => false,
                    'result' => 'CLAMAV_UNAVAILABLE',
                    'threat' => 'Virus scanner unavailable - file rejected for security',
                ];
            }
            
            return ['clean' => true, 'result' => 'CLAMAV_UNAVAILABLE', 'threat' => null];
        }

        try {
            $result = $this->scanWithClamAV($file->getPathname());
            
            if ($result['clean']) {
                Log::info('File passed virus scan', [
                    'file' => $file->getClientOriginalName(),
                    'result' => $result['result'],
                ]);
            } else {
                // CRITICAL: Malware detected
                Log::critical('MALWARE DETECTED in uploaded file', [
                    'file' => $file->getClientOriginalName(),
                    'threat' => $result['threat'],
                    'size' => $file->getSize(),
                    'mime' => $file->getMimeType(),
                    'user_id' => auth()->id(),
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
                
                // Alert security team if configured
                if (config('security.virus_scan.alert_on_detection')) {
                    $this->alertSecurityTeam($file, $result);
                }
                
                // Quarantine file if configured
                if (config('security.virus_scan.quarantine_suspicious')) {
                    $this->quarantineFile($file, $result);
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Virus scan failed', [
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);
            
            // CRITICAL: Fail securely - reject file if scan fails
            $failOnError = config('security.virus_scan.fail_on_error', true);
            
            if ($failOnError) {
                return [
                    'clean' => false,
                    'result' => 'SCAN_ERROR',
                    'threat' => 'Virus scan failed - file rejected for security: ' . $e->getMessage(),
                ];
            }
            
            return ['clean' => true, 'result' => 'SCAN_ERROR', 'threat' => null];
        }
    }

    /**
     * Check if ClamAV is available
     */
    protected function isClamAvAvailable(): bool
    {
        return file_exists($this->socket) || $this->canConnectToTcp();
    }

    /**
     * Check if can connect to ClamAV via TCP
     */
    protected function canConnectToTcp(): bool
    {
        $host = config('security.virus_scan.host', 'localhost');
        $port = config('security.virus_scan.port', 3310);
        
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);
        if ($connection) {
            fclose($connection);
            return true;
        }
        
        return false;
    }

    /**
     * Scan file using ClamAV
     */
    protected function scanWithClamAV(string $filePath): array
    {
        // Try socket connection first
        if (file_exists($this->socket)) {
            return $this->scanViaSocket($filePath);
        }
        
        // Fall back to TCP
        return $this->scanViaTcp($filePath);
    }

    /**
     * Scan via Unix socket
     */
    protected function scanViaSocket(string $filePath): array
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        
        if (!socket_connect($socket, $this->socket)) {
            throw new \Exception('Could not connect to ClamAV socket');
        }

        // Send SCAN command
        socket_write($socket, "SCAN {$filePath}\n");
        
        $response = '';
        while ($buffer = socket_read($socket, 1024)) {
            $response .= $buffer;
        }
        
        socket_close($socket);
        
        return $this->parseResponse($response);
    }

    /**
     * Scan via TCP
     */
    protected function scanViaTcp(string $filePath): array
    {
        $host = config('security.virus_scan.host', 'localhost');
        $port = config('security.virus_scan.port', 3310);
        
        $socket = fsockopen($host, $port, $errno, $errstr, 5);
        
        if (!$socket) {
            throw new \Exception("Could not connect to ClamAV: {$errstr}");
        }

        // Send file for scanning
        fwrite($socket, "SCAN {$filePath}\n");
        
        $response = '';
        while (!feof($socket)) {
            $response .= fgets($socket, 1024);
        }
        
        fclose($socket);
        
        return $this->parseResponse($response);
    }

    /**
     * Parse ClamAV response
     */
    protected function parseResponse(string $response): array
    {
        $response = trim($response);
        
        if (str_contains($response, 'OK')) {
            return ['clean' => true, 'result' => 'OK', 'threat' => null];
        }
        
        if (str_contains($response, 'FOUND')) {
            // Extract virus name
            preg_match('/: (.+) FOUND/', $response, $matches);
            $virusName = $matches[1] ?? 'Unknown';
            return [
                'clean' => false,
                'result' => 'VIRUS_FOUND',
                'threat' => $virusName,
            ];
        }
        
        return [
            'clean' => false,
            'result' => 'UNKNOWN_RESPONSE',
            'threat' => $response,
        ];
    }

    /**
     * Alert security team about malware detection.
     * 
     * @param UploadedFile $file
     * @param array $scanResult
     * @return void
     */
    protected function alertSecurityTeam(UploadedFile $file, array $scanResult): void
    {
        try {
            $alertEmail = config('security.virus_scan.alert_email');
            
            if (!$alertEmail) {
                return;
            }

            $details = [
                'timestamp' => now()->toIso8601String(),
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'threat' => $scanResult['threat'] ?? 'Unknown',
                'user_id' => auth()->id(),
                'user_email' => auth()->user()?->email,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ];

            // Send email alert
            \Illuminate\Support\Facades\Mail::raw(
                "CRITICAL SECURITY ALERT: Malware Detected\n\n" .
                "A malicious file was uploaded to the eVisa system and has been blocked.\n\n" .
                "Details:\n" .
                json_encode($details, JSON_PRETTY_PRINT) .
                "\n\nThe file has been rejected and the incident has been logged.\n" .
                "Please investigate immediately.",
                function ($message) use ($alertEmail) {
                    $message->to($alertEmail)
                        ->subject('[CRITICAL] Malware Detected in eVisa Upload');
                }
            );

            Log::info('Security team alerted about malware detection', [
                'alert_email' => $alertEmail,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to alert security team', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Quarantine suspicious file for manual review.
     * 
     * @param UploadedFile $file
     * @param array $scanResult
     * @return void
     */
    protected function quarantineFile(UploadedFile $file, array $scanResult): void
    {
        try {
            $quarantinePath = storage_path('app/quarantine');
            
            if (!is_dir($quarantinePath)) {
                mkdir($quarantinePath, 0700, true);
            }

            $quarantineFile = $quarantinePath . '/' . now()->format('Y-m-d_His') . '_' . $file->getClientOriginalName();
            
            copy($file->getPathname(), $quarantineFile);
            
            // Store metadata
            file_put_contents(
                $quarantineFile . '.json',
                json_encode([
                    'timestamp' => now()->toIso8601String(),
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'threat' => $scanResult['threat'] ?? 'Unknown',
                    'user_id' => auth()->id(),
                    'ip_address' => request()->ip(),
                ], JSON_PRETTY_PRINT)
            );

            Log::info('File quarantined for manual review', [
                'quarantine_file' => $quarantineFile,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to quarantine file', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
