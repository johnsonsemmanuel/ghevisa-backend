<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PassportVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PassportVerificationController extends Controller
{
    public function __construct(
        protected PassportVerificationService $passportVerificationService,
    ) {}

    /**
     * Test endpoint to simulate passport verification against an external authority.
     * This is intended for development/integration testing until a real API is wired.
     */
    public function simulate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'passport_number' => 'required|string|max:50',
            'nationality' => 'required|string|max:3',
            'issuing_authority' => 'nullable|string|max:255',
            'passport_expiry' => 'required|date',
        ]);

        $expiry = now()->parse($validated['passport_expiry']);
        $expiryCheck = $this->passportVerificationService->validateExpiry($expiry);

        $external = $this->passportVerificationService->verifyWithExternalSource([
            'passport_number' => $validated['passport_number'],
            'nationality' => $validated['nationality'],
            'issuing_authority' => $validated['issuing_authority'] ?? null,
        ]);

        return response()->json([
            'expiry_check' => $expiryCheck,
            'external_verification' => $external,
        ]);
    }
}

