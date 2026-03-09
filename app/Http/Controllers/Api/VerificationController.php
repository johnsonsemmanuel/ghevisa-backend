<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Verification API for Border/Airline Portal
 * 
 * Provides QR Code validation endpoint for verifying eVisas
 * at immigration checkpoints and airline check-in.
 */
class VerificationController extends Controller
{
    /**
     * Validate an eVisa by QR code or reference number.
     * 
     * This endpoint is used by border control and airlines to verify
     * the authenticity of an eVisa.
     */
    public function validateEvisa(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string', // QR code content or reference number
            'passport_number' => 'nullable|string', // Optional passport verification
        ]);

        // Extract reference number from QR code
        // QR format: GHEVISA:GH-2026-000001:CHECKSUM
        $code = $validated['code'];
        $referenceNumber = $this->extractReferenceNumber($code);

        if (!$referenceNumber) {
            return response()->json([
                'valid' => false,
                'message' => 'Invalid QR code format',
            ], 400);
        }

        $application = Application::where('reference_number', $referenceNumber)->first();

        if (!$application) {
            return response()->json([
                'valid' => false,
                'message' => 'eVisa not found',
                'reference_number' => $referenceNumber,
            ], 404);
        }

        // Verify passport number if provided
        if (isset($validated['passport_number'])) {
            $passportMatch = strtoupper(trim($application->passport_number)) === 
                             strtoupper(trim($validated['passport_number']));
            if (!$passportMatch) {
                return response()->json([
                    'valid' => false,
                    'message' => 'Passport number does not match',
                    'reference_number' => $referenceNumber,
                ], 422);
            }
        }

        // Check if eVisa is approved
        if ($application->status !== 'approved') {
            return response()->json([
                'valid' => false,
                'message' => 'eVisa is not approved',
                'reference_number' => $referenceNumber,
                'status' => $application->status,
            ], 422);
        }

        // Check if QR code matches (if stored)
        if ($application->evisa_qr_code && $code !== $application->evisa_qr_code) {
            // Verify checksum
            if (!$this->verifyChecksum($code, $application)) {
                return response()->json([
                    'valid' => false,
                    'message' => 'QR code verification failed',
                    'reference_number' => $referenceNumber,
                ], 422);
            }
        }

        // Return verification result with minimized PII (CRIT-08)
        $firstName = $application->first_name ?? '';
        $lastName = $application->last_name ?? '';
        $maskedName = (substr($firstName, 0, 1) . str_repeat('*', max(0, strlen($firstName) - 1)))
                    . ' '
                    . (substr($lastName, 0, 1) . str_repeat('*', max(0, strlen($lastName) - 1)));

        return response()->json([
            'valid' => true,
            'message' => 'eVisa verified successfully',
            'reference_number' => $application->reference_number,
            'visa_type' => $application->visaType->name ?? null,
            'holder_name' => $maskedName,
            'valid_from' => $application->decided_at?->format('Y-m-d'),
            'valid_until' => $application->intended_arrival?->addDays($application->duration_days)->format('Y-m-d'),
            'duration_days' => $application->duration_days,
            'issued_at' => $application->decided_at?->toIso8601String(),
        ]);
    }

    /**
     * Extract reference number from QR code content.
     */
    protected function extractReferenceNumber(string $code): ?string
    {
        // Handle direct reference number
        if (preg_match('/^GH-\d{4}-\d{6}$/', $code)) {
            return $code;
        }

        // Handle QR format: GHEVISA:GH-2026-000001:CHECKSUM
        if (preg_match('/^GHEVISA:(GH-\d{4}-\d{6}):/', $code, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Verify QR code checksum.
     */
    protected function verifyChecksum(string $code, Application $application): bool
    {
        // Extract checksum from QR code
        $parts = explode(':', $code);
        if (count($parts) < 3) {
            return false;
        }

        $providedChecksum = $parts[2];
        
        // Generate expected checksum using HMAC
        $data = $application->reference_number . $application->passport_number . $application->decided_at?->timestamp;
        $expectedChecksum = hash_hmac('sha256', $data, config('app.key'));

        return strtoupper($providedChecksum) === strtoupper($expectedChecksum);
    }


    /**
     * Verify an eVisa by QR code (Public GET endpoint for border officers).
     */
    public function verifyQr(string $code): JsonResponse
    {
        // Parse the QR code format: GHEVISA:REFERENCE:CHECKSUM
        $parts = explode(':', $code);
        
        if (count($parts) !== 3 || $parts[0] !== 'GHEVISA') {
            return response()->json([
                'valid' => false,
                'message' => 'Invalid QR code format. This does not appear to be a valid Ghana eVisa.',
            ]);
        }

        $reference = $parts[1];

        // Find the application
        $application = Application::where('reference_number', $reference)
            ->whereIn('status', ['approved', 'issued'])
            ->first();

        if (!$application) {
            return response()->json([
                'valid' => false,
                'message' => 'No valid eVisa found with this reference number. The visa may have been revoked or does not exist.',
            ]);
        }

        // Verify checksum using the shared method
        if (!$this->verifyChecksum($code, $application)) {
            return response()->json([
                'valid' => false,
                'message' => 'Document verification failed. The QR code checksum does not match. This document may have been tampered with.',
            ]);
        }

        // Check if visa is still valid (if valid_until date exists)
        if ($application->intended_arrival && $application->duration_days) {
            $validUntil = $application->intended_arrival->copy()->addDays($application->duration_days);
            if ($validUntil->isPast()) {
                return response()->json([
                    'valid' => false,
                    'message' => 'This eVisa has expired. The validity period ended on ' . $validUntil->format('d M Y') . '.',
                ]);
            }
        }

        // Return verified application data
        return response()->json([
            'valid' => true,
            'application' => [
                'reference_number' => $application->reference_number,
                'full_name' => $application->first_name . ' ' . $application->last_name,
                'passport_number' => $application->passport_number,
                'nationality' => $application->nationality,
                'visa_type' => $application->visaType->name ?? 'N/A',
                'arrival_date' => $application->intended_arrival ? $application->intended_arrival->format('d M Y') : 'N/A',
                'duration_days' => $application->duration_days ?? 0,
                'issued_at' => $application->decided_at ? $application->decided_at->format('d M Y') : 'N/A',
                'valid_until' => $application->intended_arrival && $application->duration_days 
                    ? $application->intended_arrival->copy()->addDays($application->duration_days)->format('d M Y') 
                    : 'N/A',
                'status' => $application->status,
            ],
        ]);
    }
}
