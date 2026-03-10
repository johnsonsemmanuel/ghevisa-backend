<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EtaApplication;
use App\Models\VisaType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class EtaController extends Controller
{
    /**
     * Get eligible ETA types for a nationality.
     */
    public function eligibleTypes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nationality' => 'required|string|size:2',
        ]);

        $nationality = strtoupper($validated['nationality']);

        // Use ETA Eligibility Service for comprehensive check
        $eligibilityService = app(\App\Services\EtaEligibilityService::class);
        $authType = $eligibilityService->getAuthorizationType($nationality);

        // Get ETA types where nationality is eligible
        $etaTypes = VisaType::where('type', 'eta')
            ->where('is_active', true)
            ->get()
            ->filter(function ($type) use ($nationality) {
                if (empty($type->eligible_nationalities)) {
                    return false; // ETA requires specific nationalities
                }
                return in_array($nationality, $type->eligible_nationalities);
            });

        return response()->json([
            'nationality' => $nationality,
            'authorization_type' => $authType,
            'eta_types' => $etaTypes->values(),
            'is_eligible' => $etaTypes->isNotEmpty(),
            'routing_logic' => $eligibilityService->getRoutingLogic(),
        ]);
    }

    /**
     * Submit a new ETA application.
     */
    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visa_type_id' => 'required|exists:visa_types,id',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'date_of_birth' => 'required|date|before:today',
            'gender' => 'nullable|in:male,female,other',
            'nationality' => 'required|string|size:2',
            'passport_number' => 'required|string|max:50',
            'passport_issue_date' => 'nullable|date|before:today',
            'passport_expiry_date' => 'required|date|after:today',
            'email' => 'required|email',
            'phone' => 'nullable|string|max:20',
            'residential_address' => 'nullable|string|max:500',
            'intended_arrival_date' => 'required|date|after:today',
            'port_of_entry' => 'nullable|string|max:100',
            'airline' => 'nullable|string|max:100',
            'flight_number' => 'nullable|string|max:20',
            'address_in_ghana' => 'nullable|string|max:500',
            'host_name' => 'nullable|string|max:100',
            'host_phone' => 'nullable|string|max:20',
            'denied_entry_before' => 'boolean',
            'criminal_conviction' => 'boolean',
            'previous_ghana_visa' => 'boolean',
            'travel_history' => 'nullable|string|max:500',
            'passport_scan' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'photo' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'hotel_booking' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        // Duplicate ETA prevention: check for existing active ETA
        $existing = EtaApplication::whereIn('status', ['approved', 'pending'])
            ->whereNotNull('expires_at')
            ->get()
            ->first(function (EtaApplication $eta) use ($validated) {
                $storedPassport = Crypt::decryptString($eta->passport_number_encrypted);
                $storedNationality = Crypt::decryptString($eta->nationality_encrypted);
                return strtoupper($storedPassport) === strtoupper($validated['passport_number'])
                    && strtoupper($storedNationality) === strtoupper($validated['nationality'])
                    && !$eta->isExpired();
            });

        if ($existing) {
            return response()->json([
                'message' => 'You already have an active ETA valid until ' . $existing->expires_at?->format('Y-m-d'),
                'eta' => $this->formatEtaResponse($existing),
            ], 422);
        }

        // Verify visa type is ETA
        $visaType = VisaType::findOrFail($validated['visa_type_id']);
        if ($visaType->type !== 'eta') {
            return response()->json(['message' => 'Invalid ETA type'], 422);
        }

        // Check nationality eligibility
        if (!empty($visaType->eligible_nationalities)) {
            $nationality = strtoupper($validated['nationality']);
            if (!in_array($nationality, $visaType->eligible_nationalities)) {
                return response()->json([
                    'message' => 'Your nationality is not eligible for this ETA type',
                ], 422);
            }
        }

        // Create ETA application with encrypted PII
        $eta = EtaApplication::create([
            'reference_number' => EtaApplication::generateReferenceNumber(),
            'user_id' => $request->user()?->id,
            'first_name_encrypted' => Crypt::encryptString($validated['first_name']),
            'last_name_encrypted' => Crypt::encryptString($validated['last_name']),
            'date_of_birth' => $validated['date_of_birth'],
            'gender' => $validated['gender'] ?? null,
            'nationality_encrypted' => Crypt::encryptString($validated['nationality']),
            'passport_number_encrypted' => Crypt::encryptString($validated['passport_number']),
            'passport_issue_date' => $validated['passport_issue_date'] ?? null,
            'passport_expiry_date' => $validated['passport_expiry_date'],
            'email_encrypted' => Crypt::encryptString($validated['email']),
            'phone_encrypted' => isset($validated['phone']) ? Crypt::encryptString($validated['phone']) : null,
            'residential_address_encrypted' => isset($validated['residential_address']) ? Crypt::encryptString($validated['residential_address']) : null,
            'intended_arrival_date' => $validated['intended_arrival_date'],
            'port_of_entry' => $validated['port_of_entry'] ?? null,
            'airline' => $validated['airline'] ?? null,
            'flight_number' => $validated['flight_number'] ?? null,
            'address_in_ghana_encrypted' => isset($validated['address_in_ghana']) ? Crypt::encryptString($validated['address_in_ghana']) : null,
            'host_name' => $validated['host_name'] ?? null,
            'host_phone' => $validated['host_phone'] ?? null,
            'denied_entry_before' => $validated['denied_entry_before'] ?? false,
            'criminal_conviction' => $validated['criminal_conviction'] ?? false,
            'previous_ghana_visa' => $validated['previous_ghana_visa'] ?? false,
            'travel_history' => $validated['travel_history'] ?? null,
            'passport_scan_path' => isset($validated['passport_scan']) ? $request->file('passport_scan')->store('eta-documents', 'private') : null,
            'photo_path' => isset($validated['photo']) ? $request->file('photo')->store('eta-photos', 'private') : null,
            'hotel_booking_path' => isset($validated['hotel_booking']) ? $request->file('hotel_booking')->store('eta-documents', 'private') : null,
            // ETA is free at this stage
            'fee_amount' => 0,
            'validity_days' => $visaType->max_duration_days,
            'entry_type' => $visaType->entry_type,
            'status' => 'pending',
        ]);

        // Basic screening: passport expiry and declarations
        $expiry = now()->parse($validated['passport_expiry_date']);
        $verificationService = app(\App\Services\PassportVerificationService::class);
        $expiryCheck = $verificationService->validateExpiry($expiry);

        $hasRiskFlags = ($validated['denied_entry_before'] ?? false)
            || ($validated['criminal_conviction'] ?? false);

        if (!$expiryCheck['valid']) {
            // Hard block expired passports
            return response()->json([
                'message' => __('passport.expired'),
            ], 422);
        }

        // Auto-approve low-risk ETAs, flag others for admin attention
        if (!$hasRiskFlags && ($expiryCheck['code'] === 'ok')) {
            $this->approveEta($eta);
        } else {
            $eta->update(['status' => 'flagged']);
        }

        return response()->json([
            'message' => 'ETA application submitted successfully',
            'reference_number' => $eta->reference_number,
            'fee_amount' => $eta->fee_amount,
            'eta' => $this->formatEtaResponse($eta),
        ], 201);
    }

    /**
     * Check ETA application status.
     */
    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reference_number' => 'required|string',
            'passport_number' => 'required|string',
        ]);

        $eta = EtaApplication::where('reference_number', $validated['reference_number'])->first();

        if (!$eta) {
            return response()->json(['message' => 'ETA application not found'], 404);
        }

        // Verify passport number matches
        $storedPassport = Crypt::decryptString($eta->passport_number_encrypted);
        if (strtoupper($storedPassport) !== strtoupper($validated['passport_number'])) {
            return response()->json(['message' => 'Invalid credentials'], 403);
        }

        return response()->json([
            'eta' => $this->formatEtaResponse($eta),
        ]);
    }

    /**
     * Verify ETA at border (for immigration officers).
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'eta_number' => 'nullable|string',
            'reference_number' => 'nullable|string',
            'passport_number' => 'required|string',
        ]);

        if (empty($validated['eta_number']) && empty($validated['reference_number'])) {
            return response()->json(['message' => 'Either ETA number or reference number is required'], 422);
        }

        $query = EtaApplication::query();

        if (!empty($validated['eta_number'])) {
            $query->where('eta_number', $validated['eta_number']);
        } else {
            $query->where('reference_number', $validated['reference_number']);
        }

        $eta = $query->first();

        if (!$eta) {
            return response()->json([
                'valid' => false,
                'message' => 'ETA not found',
            ], 404);
        }

        // Verify passport number
        $storedPassport = Crypt::decryptString($eta->passport_number_encrypted);
        if (strtoupper($storedPassport) !== strtoupper($validated['passport_number'])) {
            return response()->json([
                'valid' => false,
                'message' => 'Passport number does not match',
            ], 403);
        }

        // Check if approved and not expired
        $isValid = $eta->status === 'approved' && !$eta->isExpired();

        return response()->json([
            'valid' => $isValid,
            'status' => $eta->status,
            'eta_number' => $eta->eta_number,
            'holder_name' => Crypt::decryptString($eta->first_name_encrypted) . ' ' . Crypt::decryptString($eta->last_name_encrypted),
            'nationality' => Crypt::decryptString($eta->nationality_encrypted),
            'valid_until' => $eta->expires_at?->format('Y-m-d'),
            'entry_type' => $eta->entry_type,
            'message' => $isValid ? 'ETA is valid for entry' : ($eta->isExpired() ? 'ETA has expired' : 'ETA is not approved'),
        ]);
    }

    /**
     * Process ETA payment callback.
     */
    public function paymentCallback(Request $request): JsonResponse
    {
        // CRIT-06: Verify callback signature to prevent forged payment confirmations
        $signature = $request->header('X-Signature');
        $callbackSecret = config('services.eta.callback_secret');

        if ($callbackSecret) {
            if (!$signature) {
                \Log::warning('ETA payment callback missing signature', ['ip' => $request->ip()]);
                return response()->json(['message' => 'Missing signature'], 401);
            }

            $expectedSignature = hash_hmac('sha256', $request->getContent(), $callbackSecret);
            if (!hash_equals($expectedSignature, $signature)) {
                \Log::warning('ETA payment callback invalid signature', ['ip' => $request->ip()]);
                return response()->json(['message' => 'Invalid signature'], 401);
            }
        }

        $validated = $request->validate([
            'reference_number' => 'required|string',
            'payment_reference' => 'required|string',
            'status' => 'required|in:success,failed',
        ]);

        $eta = EtaApplication::where('reference_number', $validated['reference_number'])->first();

        if (!$eta) {
            return response()->json(['message' => 'ETA application not found'], 404);
        }

        if ($validated['status'] === 'success') {
            $eta->update([
                'payment_status' => 'completed',
                'payment_reference' => $validated['payment_reference'],
            ]);

            // Auto-approve for ECOWAS (simple check - no security flags)
            if (!$eta->denied_entry_before && !$eta->criminal_conviction) {
                $this->approveEta($eta);
            }
        } else {
            $eta->update([
                'payment_status' => 'failed',
            ]);
        }

        return response()->json([
            'message' => $validated['status'] === 'success' ? 'Payment processed successfully' : 'Payment failed',
            'eta' => $this->formatEtaResponse($eta->fresh()),
        ]);
    }

    /**
     * Approve an ETA and generate ETA number with QR code.
     */
    private function approveEta(EtaApplication $eta): void
    {
        $eta->update([
            'status' => 'approved',
            'approved_at' => now(),
            'expires_at' => now()->addDays($eta->validity_days),
        ]);

        $eta->generateEtaNumber();

        // Generate QR code
        $qrService = app(\App\Services\QrCodeService::class);
        $qrData = $qrService->generateEtaQrData($eta);
        $eta->update(['qr_code' => $qrData]);

        // Send confirmation notification
        try {
            app(\App\Services\NotificationService::class)->sendNotification(
                null,
                'email',
                'eta-approved',
                Crypt::decryptString($eta->email_encrypted),
                'Your Ghana ETA Has Been Approved',
                [
                    'first_name' => Crypt::decryptString($eta->first_name_encrypted),
                    'eta_number' => $eta->eta_number,
                    'valid_until' => $eta->expires_at->format('F j, Y'),
                    'entry_type' => $eta->entry_type,
                ]
            );
        } catch (\Exception $e) {
            \Log::error('Failed to send ETA approval notification: ' . $e->getMessage());
        }
    }

    /**
     * Format ETA response (decrypt sensitive fields).
     */
    private function formatEtaResponse(EtaApplication $eta): array
    {
        return [
            'reference_number' => $eta->reference_number,
            'eta_number' => $eta->eta_number,
            'status' => $eta->status,
            'first_name' => Crypt::decryptString($eta->first_name_encrypted),
            'last_name' => Crypt::decryptString($eta->last_name_encrypted),
            'nationality' => Crypt::decryptString($eta->nationality_encrypted),
            'passport_number' => substr(Crypt::decryptString($eta->passport_number_encrypted), 0, 3) . '****',
            'date_of_birth' => $eta->date_of_birth->format('Y-m-d'),
            'intended_arrival_date' => $eta->intended_arrival_date->format('Y-m-d'),
            'port_of_entry' => $eta->port_of_entry,
            'validity_days' => $eta->validity_days,
            'entry_type' => $eta->entry_type,
            'fee_amount' => $eta->fee_amount,
            'payment_status' => $eta->payment_status,
            'approved_at' => $eta->approved_at?->format('Y-m-d H:i:s'),
            'expires_at' => $eta->expires_at?->format('Y-m-d'),
            'created_at' => $eta->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
