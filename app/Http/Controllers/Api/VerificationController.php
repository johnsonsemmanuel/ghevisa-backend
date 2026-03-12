<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\EtaApplication;
use App\Services\Verification\BoardingAuthorizationService;
use App\Services\Security\ImmutableAuditService;
use App\Services\Verification\VerificationPerformanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class VerificationController extends Controller
{
    public function __construct(
        protected BoardingAuthorizationService $boardingAuthService,
        protected ImmutableAuditService $auditService,
        protected VerificationPerformanceService $performanceService,
    ) {}

    /**
     * Verify travel authorization for airlines and border control.
     * 
     * This endpoint checks if a traveler is authorized to board/enter Ghana.
     * Supports verification by passport number, ETA number, or visa ID.
     */
    public function verifyTravel(Request $request): JsonResponse
    {
        $startTime = microtime(true);
        
        $validated = $request->validate([
            'passport_number' => 'required|string|min:6|max:50',
            'nationality' => 'required|string|size:2',
            'eta_number' => 'nullable|string',
            'visa_id' => 'nullable|string',
            'taid' => 'nullable|string',
        ]);

        // Audit the verification attempt
        $this->auditService->log(
            'travel_verification_attempt',
            'App\Models\User',
            $request->user()?->id ?? 0,
            [
                'passport_number' => substr($validated['passport_number'], 0, 3) . '****',
                'nationality' => $validated['nationality'],
                'eta_number' => $validated['eta_number'] ?? null,
                'visa_id' => $validated['visa_id'] ?? null,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]
        );

        // Try to find authorization by different methods
        $authorization = null;
        $authorizationType = null;

        // 1. Check by ETA number (if provided)
        if (!empty($validated['eta_number'])) {
            $authorization = $this->verifyByEtaNumber(
                $validated['eta_number'],
                $validated['passport_number']
            );
            if ($authorization) {
                $authorizationType = 'ETA';
            }
        }

        // 2. Check by Visa ID (if provided)
        if (!$authorization && !empty($validated['visa_id'])) {
            $authorization = $this->verifyByVisaId(
                $validated['visa_id'],
                $validated['passport_number']
            );
            if ($authorization) {
                $authorizationType = 'VISA';
            }
        }

        // 3. Check by TAID (if provided)
        if (!$authorization && !empty($validated['taid'])) {
            $authorization = $this->verifyByTaid(
                $validated['taid'],
                $validated['passport_number']
            );
            if ($authorization) {
                $authorizationType = $authorization['type'];
            }
        }

        // 4. Check by passport number and nationality only
        if (!$authorization) {
            $authorization = $this->verifyByPassportAndNationality(
                $validated['passport_number'],
                $validated['nationality']
            );
            if ($authorization) {
                $authorizationType = $authorization['type'];
            }
        }

        // Determine verification result
        if (!$authorization) {
            $responseTime = microtime(true) - $startTime;
            $this->performanceService->recordVerification(
                'UNKNOWN',
                $responseTime,
                false,
                'NO_AUTHORIZATION_FOUND'
            );
            return $this->unauthorizedResponse($request, $validated, 'NO_AUTHORIZATION_FOUND');
        }

        // Check if authorization is valid
        $validationResult = $this->validateAuthorization($authorization, $authorizationType);

        if (!$validationResult['valid']) {
            $responseTime = microtime(true) - $startTime;
            $this->performanceService->recordVerification(
                $authorizationType,
                $responseTime,
                false,
                $validationResult['reason']
            );
            return $this->unauthorizedResponse($request, $validated, $validationResult['reason']);
        }

        // Generate Boarding Authorization Code (BAC) for airlines
        $bac = null;
        if ($request->user() && $request->user()->role === 'airline_staff') {
            $bac = $this->boardingAuthService->generateBAC(
                $validated['passport_number'],
                $validated['nationality'],
                $authorizationType,
                $authorizationType === 'ETA' ? ($authorization['eta_number'] ?? null) : null,
                $authorizationType === 'VISA' ? ($authorization['id'] ?? null) : null,
                $request->user()->id,
                $request->ip(),
                $request->userAgent()
            );
        }

        // Log successful verification
        $this->auditService->log(
            'travel_verification_success',
            $authorizationType === 'ETA' ? 'App\Models\EtaApplication' : 'App\Models\Application',
            $authorization['id'] ?? 0,
            [
                'authorization_type' => $authorizationType,
                'passport_number' => substr($validated['passport_number'], 0, 3) . '****',
                'bac' => $bac?->authorization_code ?? null,
            ]
        );

        // Record performance metrics
        $responseTime = microtime(true) - $startTime;
        $this->performanceService->recordVerification(
            $authorizationType,
            $responseTime,
            true,
            null
        );

        return response()->json([
            'status' => 'AUTHORIZED',
            'authorization_type' => $authorizationType,
            'traveler_name' => $authorization['traveler_name'],
            'passport_number' => $this->maskPassport($validated['passport_number']),
            'nationality' => $validated['nationality'],
            'valid_until' => $authorization['valid_until'],
            'entry_type' => $authorization['entry_type'] ?? 'single',
            'eta_number' => $authorizationType === 'ETA' ? $authorization['eta_number'] : null,
            'visa_reference' => $authorizationType === 'VISA' ? $authorization['reference_number'] : null,
            'taid' => $authorization['taid'] ?? null,
            'boarding_authorization_code' => $bac?->authorization_code ?? null,
            'bac_expires_at' => $bac?->expiry_timestamp ?? null,
            'message' => 'Traveler is authorized to board/enter Ghana',
        ])->header('X-Response-Time', round($responseTime * 1000, 2) . 'ms');
    }

    /**
     * Verify by ETA number with passport binding.
     */
    protected function verifyByEtaNumber(string $etaNumber, string $passportNumber): ?array
    {
        $eta = EtaApplication::where('eta_number', $etaNumber)->first();

        if (!$eta) {
            return null;
        }

        // CRITICAL: Passport binding - ETA must match passport number
        $storedPassport = Crypt::decryptString($eta->passport_number_encrypted);
        if (strtoupper($storedPassport) !== strtoupper($passportNumber)) {
            \Log::warning('ETA passport binding failed', [
                'eta_number' => $etaNumber,
                'provided_passport' => substr($passportNumber, 0, 3) . '****',
            ]);
            return null;
        }

        return [
            'id' => $eta->id,
            'type' => 'ETA',
            'eta_number' => $eta->eta_number,
            'taid' => $eta->taid,
            'traveler_name' => Crypt::decryptString($eta->first_name_encrypted) . ' ' . Crypt::decryptString($eta->last_name_encrypted),
            'passport_number' => $storedPassport,
            'nationality' => Crypt::decryptString($eta->nationality_encrypted),
            'status' => $eta->status,
            'valid_until' => $eta->expires_at?->format('Y-m-d'),
            'entry_type' => $eta->entry_type ?? 'single',
            'entry_consumed' => $eta->entry_consumed ?? false,
            'approved_at' => $eta->approved_at,
            'expires_at' => $eta->expires_at,
        ];
    }

    /**
     * Verify by Visa ID with passport binding.
     */
    protected function verifyByVisaId(string $visaId, string $passportNumber): ?array
    {
        $application = Application::where('reference_number', $visaId)
            ->orWhere('id', $visaId)
            ->first();

        if (!$application) {
            return null;
        }

        // CRITICAL: Passport binding - Visa must match passport number
        if (strtoupper($application->passport_number) !== strtoupper($passportNumber)) {
            \Log::warning('Visa passport binding failed', [
                'visa_id' => $visaId,
                'provided_passport' => substr($passportNumber, 0, 3) . '****',
            ]);
            return null;
        }

        return [
            'id' => $application->id,
            'type' => 'VISA',
            'reference_number' => $application->reference_number,
            'taid' => $application->taid,
            'traveler_name' => $application->first_name . ' ' . $application->last_name,
            'passport_number' => $application->passport_number,
            'nationality' => $application->nationality,
            'status' => $application->status,
            'valid_until' => $application->decided_at?->addDays($application->visa_duration ?? 90)->format('Y-m-d'),
            'entry_type' => $application->entry_type ?? 'single',
            'approved_at' => $application->decided_at,
            'visa_type' => $application->visaType?->name,
        ];
    }

    /**
     * Verify by TAID with passport binding.
     */
    protected function verifyByTaid(string $taid, string $passportNumber): ?array
    {
        // Check ETA applications first
        $eta = EtaApplication::where('taid', $taid)->first();
        if ($eta) {
            $storedPassport = Crypt::decryptString($eta->passport_number_encrypted);
            if (strtoupper($storedPassport) === strtoupper($passportNumber)) {
                return $this->verifyByEtaNumber($eta->eta_number, $passportNumber);
            }
        }

        // Check visa applications
        $application = Application::where('taid', $taid)->first();
        if ($application) {
            if (strtoupper($application->passport_number) === strtoupper($passportNumber)) {
                return $this->verifyByVisaId($application->reference_number, $passportNumber);
            }
        }

        return null;
    }

    /**
     * Verify by passport number and nationality only.
     */
    protected function verifyByPassportAndNationality(string $passportNumber, string $nationality): ?array
    {
        // Check for active ETA
        $etas = EtaApplication::whereIn('status', ['approved', 'issued'])
            ->whereNotNull('expires_at')
            ->get();

        foreach ($etas as $eta) {
            $storedPassport = Crypt::decryptString($eta->passport_number_encrypted);
            $storedNationality = Crypt::decryptString($eta->nationality_encrypted);

            if (strtoupper($storedPassport) === strtoupper($passportNumber) &&
                strtoupper($storedNationality) === strtoupper($nationality)) {
                return $this->verifyByEtaNumber($eta->eta_number, $passportNumber);
            }
        }

        // Check for active visa
        $application = Application::whereIn('status', ['approved', 'issued'])
            ->where('passport_number', $passportNumber)
            ->where('nationality', $nationality)
            ->first();

        if ($application) {
            return $this->verifyByVisaId($application->reference_number, $passportNumber);
        }

        return null;
    }

    /**
     * Validate if authorization is currently valid.
     */
    protected function validateAuthorization(array $authorization, string $type): array
    {
        // Check if approved
        if (!in_array($authorization['status'], ['approved', 'issued'])) {
            return [
                'valid' => false,
                'reason' => 'AUTHORIZATION_NOT_APPROVED',
                'message' => 'Travel authorization is not approved',
            ];
        }

        // Check if expired
        $expiresAt = $authorization['expires_at'] ?? null;
        
        // For visa applications, calculate expiry from decided_at + visa_duration
        if ($type === 'VISA' && !$expiresAt && isset($authorization['decided_at'])) {
            $visaDuration = $authorization['visa_duration'] ?? 90;
            $expiresAt = $authorization['decided_at']->addDays($visaDuration);
        }
        
        if ($expiresAt && $expiresAt->isPast()) {
            return [
                'valid' => false,
                'reason' => 'AUTHORIZATION_EXPIRED',
                'message' => 'Travel authorization has expired',
            ];
        }

        // Check if ETA entry already consumed (for single entry)
        if ($type === 'ETA' && ($authorization['entry_consumed'] ?? false)) {
            return [
                'valid' => false,
                'reason' => 'ETA_ALREADY_USED',
                'message' => 'ETA has already been used for entry',
            ];
        }

        return [
            'valid' => true,
            'reason' => null,
            'message' => 'Authorization is valid',
        ];
    }

    /**
     * Return unauthorized response with proper logging.
     */
    protected function unauthorizedResponse(Request $request, array $validated, string $reason): JsonResponse
    {
        $this->auditService->log(
            'travel_verification_failed',
            'App\Models\User',
            $request->user()?->id ?? 0,
            [
                'reason' => $reason,
                'passport_number' => substr($validated['passport_number'], 0, 3) . '****',
                'nationality' => $validated['nationality'],
                'ip_address' => $request->ip(),
            ]
        );

        $messages = [
            'NO_AUTHORIZATION_FOUND' => 'No valid travel authorization found',
            'AUTHORIZATION_NOT_APPROVED' => 'Travel authorization is not approved',
            'AUTHORIZATION_EXPIRED' => 'Travel authorization has expired',
            'ETA_ALREADY_USED' => 'ETA has already been used for entry',
            'PASSPORT_MISMATCH' => 'Passport number does not match authorization',
        ];

        $statusCodes = [
            'NO_AUTHORIZATION_FOUND' => 'ETA_REQUIRED',
            'AUTHORIZATION_NOT_APPROVED' => 'DENIED',
            'AUTHORIZATION_EXPIRED' => 'EXPIRED',
            'ETA_ALREADY_USED' => 'DENIED',
            'PASSPORT_MISMATCH' => 'DENIED',
        ];

        return response()->json([
            'status' => $statusCodes[$reason] ?? 'DENIED',
            'message' => $messages[$reason] ?? 'Travel authorization denied',
            'reason' => $reason,
            'passport_number' => $this->maskPassport($validated['passport_number']),
            'nationality' => $validated['nationality'],
        ], 403);
    }

    /**
     * Mask passport number for security.
     */
    protected function maskPassport(string $passport): string
    {
        if (strlen($passport) <= 6) {
            return substr($passport, 0, 2) . str_repeat('*', strlen($passport) - 2);
        }
        return substr($passport, 0, 3) . str_repeat('*', strlen($passport) - 6) . substr($passport, -3);
    }

    /**
     * Confirm entry at border (for immigration officers only).
     */
    public function confirmEntry(Request $request): JsonResponse
    {
        // Only immigration officers can confirm entry
        if (!$request->user() || $request->user()->role !== 'border_officer') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'passport_number' => 'required|string',
            'eta_number' => 'nullable|string',
            'visa_id' => 'nullable|string',
            'port_of_entry' => 'required|string',
        ]);

        // Find the authorization
        $eta = null;
        $application = null;

        if (!empty($validated['eta_number'])) {
            $eta = EtaApplication::where('eta_number', $validated['eta_number'])->first();
            
            if ($eta) {
                $storedPassport = Crypt::decryptString($eta->passport_number_encrypted);
                if (strtoupper($storedPassport) !== strtoupper($validated['passport_number'])) {
                    return response()->json(['message' => 'Passport number does not match ETA'], 403);
                }
            }
        }

        if (!$eta && !empty($validated['visa_id'])) {
            $application = Application::where('reference_number', $validated['visa_id'])->first();
            
            if ($application && strtoupper($application->passport_number) !== strtoupper($validated['passport_number'])) {
                return response()->json(['message' => 'Passport number does not match visa'], 403);
            }
        }

        if (!$eta && !$application) {
            return response()->json(['message' => 'Authorization not found'], 404);
        }

        // Consume ETA entry if applicable
        if ($eta && !$eta->entry_consumed) {
            $eta->update([
                'entry_consumed' => true,
                'entry_date' => now(),
                'port_of_entry_used' => $validated['port_of_entry'],
                'entry_officer_id' => $request->user()->id,
            ]);

            $this->auditService->log(
                'eta_entry_confirmed',
                'App\Models\EtaApplication',
                $eta->id,
                [
                    'eta_number' => $eta->eta_number,
                    'port_of_entry' => $validated['port_of_entry'],
                    'passport_number' => substr($validated['passport_number'], 0, 3) . '****',
                ]
            );
        }

        return response()->json([
            'message' => 'Entry confirmed successfully',
            'entry_date' => now()->toIso8601String(),
            'port_of_entry' => $validated['port_of_entry'],
        ]);
    }
}
