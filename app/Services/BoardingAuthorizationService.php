<?php

namespace App\Services;

use App\Models\BoardingAuthorization;
use App\Models\Application;
use App\Models\EtaApplication;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Boarding Authorization Code (BAC) Service
 * 
 * Generates and validates Boarding Authorization Codes for airline liability protection.
 * BACs are valid for 24 hours and provide proof that travel authorization was verified.
 */
class BoardingAuthorizationService
{
    /**
     * Generate a Boarding Authorization Code (BAC)
     * 
     * Format: GH-BA-YYYYMMDD-XXXX
     * Validity: 24 hours
     * 
     * @param string $passportNumber Traveler's passport number
     * @param string $nationality ISO 3-letter country code
     * @param string $authorizationType 'ETA' or 'VISA'
     * @param string|null $etaNumber ETA number if applicable
     * @param int|null $visaId Visa application ID if applicable
     * @param int|null $userId User who performed verification
     * @param string|null $ipAddress IP address of verification request
     * @param string|null $userAgent User agent string
     * @return BoardingAuthorization
     */
    public function generateBAC(
        string $passportNumber,
        string $nationality,
        string $authorizationType,
        ?string $etaNumber = null,
        ?int $visaId = null,
        ?int $userId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): BoardingAuthorization {
        $code = $this->generateCode();
        $now = Carbon::now();
        
        $bac = BoardingAuthorization::create([
            'authorization_code' => $code,
            'passport_number_encrypted' => Crypt::encryptString(strtoupper($passportNumber)),
            'nationality' => strtoupper($nationality),
            'authorization_type' => strtoupper($authorizationType),
            'eta_number' => $etaNumber,
            'visa_id' => $visaId,
            'verification_timestamp' => $now,
            'expiry_timestamp' => $now->copy()->addHours(24),
            'verified_by_user_id' => $userId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        Log::info('BAC generated', [
            'code' => $code,
            'type' => $authorizationType,
            'eta_number' => $etaNumber,
            'visa_id' => $visaId,
            'expires_at' => $bac->expiry_timestamp,
        ]);

        return $bac;
    }

    /**
     * Generate unique BAC code
     * 
     * Format: GH-BA-YYYYMMDD-XXXX
     * Example: GH-BA-20260310-3A92
     */
    protected function generateCode(): string
    {
        $date = date('Ymd');
        $random = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));
        
        // Ensure uniqueness
        $code = "GH-BA-{$date}-{$random}";
        
        while (BoardingAuthorization::where('authorization_code', $code)->exists()) {
            $random = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));
            $code = "GH-BA-{$date}-{$random}";
        }
        
        return $code;
    }

    /**
     * Validate a BAC code
     * 
     * @param string $code BAC code to validate
     * @return array Validation result with status and details
     */
    public function validateBAC(string $code): array
    {
        $bac = BoardingAuthorization::where('authorization_code', $code)->first();
        
        if (!$bac) {
            return [
                'valid' => false,
                'reason' => 'BAC_NOT_FOUND',
                'message' => 'Boarding Authorization Code not found',
            ];
        }
        
        if ($bac->isExpired()) {
            return [
                'valid' => false,
                'reason' => 'BAC_EXPIRED',
                'message' => 'Boarding Authorization Code has expired',
                'expired_at' => $bac->expiry_timestamp->toIso8601String(),
            ];
        }
        
        return [
            'valid' => true,
            'bac' => $bac,
            'authorization_type' => $bac->authorization_type,
            'eta_number' => $bac->eta_number,
            'visa_id' => $bac->visa_id,
            'expires_at' => $bac->expiry_timestamp->toIso8601String(),
        ];
    }

    /**
     * Check if a BAC is valid (not expired)
     */
    public function isValid(string $code): bool
    {
        $result = $this->validateBAC($code);
        return $result['valid'] ?? false;
    }

    /**
     * Get BAC statistics for monitoring
     */
    public function getStatistics(Carbon $startDate, Carbon $endDate): array
    {
        $total = BoardingAuthorization::whereBetween('verification_timestamp', [$startDate, $endDate])->count();
        $etaCount = BoardingAuthorization::whereBetween('verification_timestamp', [$startDate, $endDate])
            ->where('authorization_type', 'ETA')->count();
        $visaCount = BoardingAuthorization::whereBetween('verification_timestamp', [$startDate, $endDate])
            ->where('authorization_type', 'VISA')->count();
        
        return [
            'total_bacs_issued' => $total,
            'eta_bacs' => $etaCount,
            'visa_bacs' => $visaCount,
            'period_start' => $startDate->toIso8601String(),
            'period_end' => $endDate->toIso8601String(),
        ];
    }

    /**
     * Clean up expired BACs (for maintenance)
     * 
     * @param int $daysOld Delete BACs older than this many days
     * @return int Number of deleted records
     */
    public function cleanupExpiredBACs(int $daysOld = 30): int
    {
        $cutoffDate = Carbon::now()->subDays($daysOld);
        
        $deleted = BoardingAuthorization::where('expiry_timestamp', '<', $cutoffDate)->delete();
        
        Log::info('Expired BACs cleaned up', [
            'deleted_count' => $deleted,
            'cutoff_date' => $cutoffDate,
        ]);
        
        return $deleted;
    }
}
