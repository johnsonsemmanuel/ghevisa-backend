<?php

namespace App\Http\Controllers\Api\Applicant;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function __construct(
        protected \App\Services\Document\DocumentService $documentService,
        protected \App\Services\Document\OcrService $ocrService,
        protected \App\Services\Document\FileSecurityService $fileSecurityService,
        protected \App\Services\Document\VirusScanService $virusScanService, // SECURITY FIX HIGH-04
    ) {}

    /**
     * Upload a document for an application.
     */
    public function upload(Request $request, Application $application): JsonResponse
    {
        if ($application->user_id !== $request->user()->id) {
            abort(403, __('auth.unauthorized'));
        }

        $request->validate([
            'file'          => [
                'required',
                'file',
                'max:10240', // 10MB
                'mimes:jpeg,jpg,png,pdf',
                // Additional security checks
                function ($attribute, $value, $fail) {
                    // Check file mime type to prevent forged extensions
                    $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
                    $fileMime = $value->getMimeType();
                    if (!in_array($fileMime, $allowedMimes)) {
                        $fail('The file type is not allowed.');
                    }
                    
                    // Check file size again (double protection)
                    if ($value->getSize() > 10 * 1024 * 1024) {
                        $fail('The file may not be greater than 10 MB.');
                    }
                },
            ],
            'document_type' => 'required|string|max:100',
        ]);

        $file = $request->file('file');

        // HIGH-04: Security scan for malware/dangerous content
        $securityIssues = $this->fileSecurityService->scan($file);
        if (!empty($securityIssues)) {
            return response()->json([
                'message' => 'File rejected by security scan',
                'errors' => ['file' => $securityIssues],
            ], 422);
        }

        // SECURITY FIX HIGH-04: Virus scanning
        $virusScanResult = $this->virusScanService->scan($file);
        if (!$virusScanResult['clean']) {
            \Log::channel('security')->alert('Malware detected in upload', [
                'user_id' => $request->user()->id,
                'application_id' => $application->id,
                'filename' => $file->getClientOriginalName(),
                'scan_result' => $virusScanResult['result'],
                'ip_address' => $request->ip(),
            ]);
            
            return response()->json([
                'message' => 'File rejected: Security threat detected',
                'errors' => ['file' => ['The uploaded file contains malicious content']],
            ], 422);
        }

        $errors = $this->documentService->validateFile($file);
        if (!empty($errors)) {
            return response()->json(['message' => 'Validation failed', 'errors' => $errors], 422);
        }

        $document = $this->documentService->upload($application, $file, $request->input('document_type'));

        return response()->json([
            'message'  => __('document.uploaded'),
            'document' => $document,
        ], 201);
    }

    /**
     * Re-upload a rejected or failed document.
     */
    public function reupload(Request $request, ApplicationDocument $document): JsonResponse
    {
        if ($document->application->user_id !== $request->user()->id) {
            abort(403, __('auth.unauthorized'));
        }

        if (!$document->needsReupload()) {
            return response()->json(['message' => __('document.reupload_not_needed')], 422);
        }

        $request->validate([
            'file' => 'required|file|max:5120|mimes:jpeg,jpg,png,pdf',
        ]);

        // HIGH-04: Security scan for reuploads
        $securityIssues = $this->fileSecurityService->scan($request->file('file'));
        if (!empty($securityIssues)) {
            return response()->json([
                'message' => 'File rejected by security scan',
                'errors' => ['file' => $securityIssues],
            ], 422);
        }

        // SECURITY FIX HIGH-04: Virus scanning for reuploads
        $virusScanResult = $this->virusScanService->scan($request->file('file'));
        if (!$virusScanResult['clean']) {
            \Log::channel('security')->alert('Malware detected in reupload', [
                'user_id' => $request->user()->id,
                'document_id' => $document->id,
                'filename' => $request->file('file')->getClientOriginalName(),
                'scan_result' => $virusScanResult['result'],
                'ip_address' => $request->ip(),
            ]);
            
            return response()->json([
                'message' => 'File rejected: Security threat detected',
                'errors' => ['file' => ['The uploaded file contains malicious content']],
            ], 422);
        }

        $document = $this->documentService->reupload($document, $request->file('file'));

        return response()->json([
            'message'  => __('document.reuploaded'),
            'document' => $document,
        ]);
    }

    /**
     * Check document completeness for an application.
     */
    public function checkCompleteness(Request $request, Application $application): JsonResponse
    {
        if ($application->user_id !== $request->user()->id) {
            abort(403, __('auth.unauthorized'));
        }

        $result = $this->documentService->validateCompleteness($application);

        return response()->json($result);
    }
}
