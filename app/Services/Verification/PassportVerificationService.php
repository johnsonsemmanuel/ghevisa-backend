<?php

namespace App\Services\Verification;

use App\Models\Application;
use App\Models\EtaApplication;
use Carbon\Carbon;

class PassportVerificationService
{
    /**
     * Local business-rule validation:
     * - Block expired passports.
     * - Flag passports with less than 6 months validity.
     */
    public function validateExpiry(Carbon $expiry): array
    {
        $now = now()->startOfDay();

        if ($expiry->lt($now)) {
            return [
                'valid' => false,
                'code' => 'expired',
                'message' => __('passport.expired'),
            ];
        }

        $months = $now->diffInMonths($expiry);

        if ($months < 6) {
            return [
                'valid' => true,
                'code' => 'near_expiry',
                'message' => __('passport.near_expiry'),
                'months_remaining' => $months,
            ];
        }

        return [
            'valid' => true,
            'code' => 'ok',
            'message' => __('passport.valid'),
            'months_remaining' => $months,
        ];
    }

    /**
     * Simulated external verification API.
     * In production, this should call the real government/passport API.
     */
    public function verifyWithExternalSource(array $payload): array
    {
        // TODO: Replace this stub with a real HTTP client integration.
        // For now we simulate a successful verification response.
        return [
            'success' => true,
            'source' => 'mock_government_api',
            'status' => 'valid',
            'details' => [
                'issuing_state' => $payload['issuing_authority'] ?? null,
                'number' => $payload['passport_number'] ?? null,
                'nationality' => $payload['nationality'] ?? null,
            ],
        ];
    }

    /**
     * Convenience method to attach verification metadata to an Application.
     */
    public function attachVerificationToApplication(Application $application, array $externalResult): void
    {
        $status = self::mapExternalStatus($externalResult['status'] ?? 'unknown');

        $application->passport_verification_status = $status;
        $application->passport_verification_source = $externalResult['source'] ?? null;
        $application->passport_verification_at = now();
        $application->save();
    }

    /**
     * Convenience method to attach verification metadata to an EtaApplication.
     */
    public function attachVerificationToEta(EtaApplication $eta, array $externalResult): void
    {
        $status = self::mapExternalStatus($externalResult['status'] ?? 'unknown');

        $eta->passport_verification_status = $status;
        $eta->passport_verification_source = $externalResult['source'] ?? null;
        $eta->passport_verification_at = now();
        $eta->save();
    }

    protected static function mapExternalStatus(string $status): string
    {
        return match ($status) {
            'valid' => Application::PASSPORT_VERIFICATION_PASSED,
            'invalid', 'stolen', 'lost' => Application::PASSPORT_VERIFICATION_FAILED,
            default => Application::PASSPORT_VERIFICATION_REVIEW,
        };
    }
}

