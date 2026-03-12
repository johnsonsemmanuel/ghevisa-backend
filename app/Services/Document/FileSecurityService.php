<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class FileSecurityService
{
    /**
     * Dangerous file signatures (magic bytes) that should be rejected.
     */
    protected array $dangerousSignatures = [
        "\x4D\x5A"         => 'Windows executable (PE)',
        "\x7F\x45\x4C\x46" => 'Linux executable (ELF)',
        "\x23\x21"         => 'Script (shebang)',
        "\xCA\xFE\xBA\xBE" => 'Java class file',
        "\x50\x4B\x03\x04" => 'ZIP archive (could contain macros)',
    ];

    /**
     * Allowed MIME types for document uploads.
     */
    protected array $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'application/pdf',
    ];

    /**
     * Maximum file size in bytes (10 MB).
     */
    protected int $maxFileSize = 10 * 1024 * 1024;

    /**
     * Validate uploaded file for security threats.
     *
     * @return array List of security issues found. Empty = safe.
     */
    public function scan(UploadedFile $file): array
    {
        $issues = [];

        // 1. Check actual MIME type (not just extension)
        $realMime = $file->getMimeType();
        if (!in_array($realMime, $this->allowedMimeTypes, true)) {
            $issues[] = "Disallowed MIME type: {$realMime}";
        }

        // 2. Check file extension matches MIME type
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeExtensionMap = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png'  => ['png'],
            'application/pdf' => ['pdf'],
        ];

        $expectedExtensions = $mimeExtensionMap[$realMime] ?? [];
        if (!empty($expectedExtensions) && !in_array($extension, $expectedExtensions, true)) {
            $issues[] = "Extension '{$extension}' does not match MIME type '{$realMime}'";
        }

        // 3. Check file size
        if ($file->getSize() > $this->maxFileSize) {
            $issues[] = 'File exceeds maximum allowed size of 10 MB';
        }

        // 4. Check for dangerous magic bytes
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle) {
            $header = fread($handle, 8);
            fclose($handle);

            foreach ($this->dangerousSignatures as $signature => $description) {
                if (str_starts_with($header, $signature)) {
                    $issues[] = "Dangerous file signature detected: {$description}";
                }
            }
        }

        // 5. For PDFs, check for JavaScript or embedded executables
        if ($realMime === 'application/pdf') {
            $pdfIssues = $this->scanPdf($file->getRealPath());
            $issues = array_merge($issues, $pdfIssues);
        }

        // 6. Check for double extensions (e.g., file.pdf.exe)
        $originalName = $file->getClientOriginalName();
        $parts = explode('.', $originalName);
        if (count($parts) > 2) {
            $suspiciousExtensions = ['exe', 'bat', 'cmd', 'sh', 'php', 'js', 'vbs', 'ps1', 'py'];
            foreach ($parts as $part) {
                if (in_array(strtolower($part), $suspiciousExtensions, true)) {
                    $issues[] = "Suspicious double extension detected in filename";
                    break;
                }
            }
        }

        if (!empty($issues)) {
            Log::warning('File security scan failed', [
                'filename' => $originalName,
                'mime' => $realMime,
                'size' => $file->getSize(),
                'issues' => $issues,
            ]);
        }

        return $issues;
    }

    /**
     * Scan a PDF file for potentially dangerous content.
     */
    protected function scanPdf(string $filePath): array
    {
        $issues = [];

        $content = file_get_contents($filePath, false, null, 0, 65536); // Read first 64KB
        if ($content === false) {
            return ['Could not read PDF file for scanning'];
        }

        // Check for JavaScript in PDF
        if (preg_match('/\/JavaScript\s/i', $content) || preg_match('/\/JS\s/i', $content)) {
            $issues[] = 'PDF contains JavaScript (potential malware vector)';
        }

        // Check for embedded files
        if (preg_match('/\/EmbeddedFile\s/i', $content)) {
            $issues[] = 'PDF contains embedded files (potential malware vector)';
        }

        // Check for launch actions
        if (preg_match('/\/Launch\s/i', $content)) {
            $issues[] = 'PDF contains launch actions (potential malware vector)';
        }

        // Check for OpenAction with URI
        if (preg_match('/\/OpenAction\s.*\/URI/i', $content)) {
            $issues[] = 'PDF contains auto-open URL action';
        }

        return $issues;
    }
}
