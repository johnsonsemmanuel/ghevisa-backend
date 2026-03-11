<?php

namespace App\Services;

use App\Models\Application;
use App\Models\EtaApplication;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class QrCodeService
{
    /**
     * Generate QR code data for an approved eVisa.
     * 
     * Format includes passport number for offline verification and passport binding enforcement.
     * This prevents document sharing and enables border officers to verify without network access.
     */
    public function generateEvisaQrData(Application $application): string
    {
        if ($application->status !== 'approved') {
            throw new \InvalidArgumentException('Cannot generate QR for non-approved application');
        }

        // Get passport number (handle both encrypted and plain fields)
        $passportNumber = $application->passport_number_encrypted 
            ? strtoupper(Crypt::decryptString($application->passport_number_encrypted))
            : strtoupper($application->passport_number);

        $payload = [
            'type' => 'GHEVISA',
            'ref' => $application->reference_number,
            'passport' => $passportNumber, // Passport binding
            'v' => 2, // version 2 includes passport
            'issued' => $application->decided_at?->format('Ymd'),
            'validity' => $application->visaType?->max_duration_days ?? 90,
            'entry' => $application->visaType?->entry_type ?? 'single',
            'hash' => $this->generateVerificationHash($application),
        ];

        // Encode as compact JSON for QR
        $qrData = 'GH-EVISA:' . base64_encode(json_encode($payload));

        // Update application with QR code
        $application->update(['evisa_qr_code' => $qrData]);

        return $qrData;
    }

    /**
     * Generate QR code data for an approved ETA.
     * 
     * Format: ETA_NUMBER|PASSPORT_NUMBER (simple pipe-delimited for easy scanning)
     * This enables offline passport binding verification at border checkpoints.
     * 
     * Example: GH-ETA-20260308-AX7283|P12345678
     */
    public function generateEtaQrData(EtaApplication $eta): string
    {
        if ($eta->status !== 'approved') {
            throw new \InvalidArgumentException('Cannot generate QR for non-approved ETA');
        }

        // Decrypt passport for QR encoding
        $passportNumber = strtoupper(Crypt::decryptString($eta->passport_number_encrypted));

        // Simple format for offline verification: ETA_NUMBER|PASSPORT_NUMBER
        $qrData = $eta->eta_number . '|' . $passportNumber;

        // Update ETA with QR code
        $eta->update(['qr_code' => $qrData]);

        return $qrData;
    }

    /**
     * Verify QR code and extract data.
     * 
     * Supports multiple formats:
     * - GH-EVISA: (JSON payload with passport)
     * - GH-ETA: (legacy JSON format)
     * - ETA_NUMBER|PASSPORT_NUMBER (new simple format)
     */
    public function verifyQrCode(string $qrData): array
    {
        if (str_starts_with($qrData, 'GH-EVISA:')) {
            return $this->verifyEvisaQr($qrData);
        } elseif (str_starts_with($qrData, 'GH-ETA:')) {
            return $this->verifyEtaQr($qrData);
        } elseif (str_contains($qrData, '|')) {
            // New simple format: ETA_NUMBER|PASSPORT_NUMBER
            return $this->verifyEtaSimpleFormat($qrData);
        }

        return [
            'valid' => false,
            'error' => 'Unknown QR code format',
        ];
    }

    /**
     * Verify eVisa QR code.
     * 
     * Supports both v1 (without passport) and v2 (with passport) formats.
     */
    protected function verifyEvisaQr(string $qrData): array
    {
        try {
            $encoded = substr($qrData, 9); // Remove 'GH-EVISA:'
            $payload = json_decode(base64_decode($encoded), true);

            if (!$payload || !isset($payload['ref'])) {
                return ['valid' => false, 'error' => 'Invalid QR data'];
            }

            $application = Application::where('reference_number', $payload['ref'])
                ->where('status', 'approved')
                ->first();

            if (!$application) {
                return ['valid' => false, 'error' => 'eVisa not found'];
            }

            // Verify hash
            $expectedHash = $this->generateVerificationHash($application);
            if ($payload['hash'] !== $expectedHash) {
                return ['valid' => false, 'error' => 'QR code tampered or invalid'];
            }

            // Verify passport binding (v2 format)
            if (isset($payload['passport']) && $payload['v'] >= 2) {
                $qrPassport = strtoupper($payload['passport']);
                $storedPassport = strtoupper($application->passport_number);
                
                if ($qrPassport !== $storedPassport) {
                    return [
                        'valid' => false,
                        'error' => 'Passport binding verification failed',
                        'passport_mismatch' => true,
                    ];
                }
            }

            // Check expiry
            $expiryDate = $application->decided_at?->addDays($payload['validity'] ?? 90);
            if ($expiryDate && $expiryDate < now()) {
                return [
                    'valid' => false,
                    'error' => 'eVisa has expired',
                    'expired_on' => $expiryDate->format('Y-m-d'),
                ];
            }

            return [
                'valid' => true,
                'type' => 'evisa',
                'reference_number' => $application->reference_number,
                'passport_number' => $payload['passport'] ?? null, // Include for verification
                'holder_name' => $application->first_name . ' ' . $application->last_name,
                'nationality' => $application->nationality,
                'visa_type' => $application->visaType?->name,
                'entry_type' => $payload['entry'],
                'issued_date' => $application->decided_at?->format('Y-m-d'),
                'valid_until' => $expiryDate?->format('Y-m-d'),
            ];
        } catch (\Exception $e) {
            return ['valid' => false, 'error' => 'Failed to decode QR data'];
        }
    }

    /**
     * Verify ETA QR code (legacy JSON format).
     * 
     * This supports old QR codes that don't include passport numbers.
     * New QR codes use the simple pipe-delimited format.
     */
    protected function verifyEtaQr(string $qrData): array
    {
        try {
            $encoded = substr($qrData, 7); // Remove 'GH-ETA:'
            $payload = json_decode(base64_decode($encoded), true);

            if (!$payload || !isset($payload['eta'])) {
                return ['valid' => false, 'error' => 'Invalid QR data'];
            }

            $eta = EtaApplication::where('eta_number', $payload['eta'])
                ->where('status', 'approved')
                ->first();

            if (!$eta) {
                return ['valid' => false, 'error' => 'ETA not found'];
            }

            // Check expiry
            if ($eta->expires_at && $eta->expires_at < now()) {
                return [
                    'valid' => false,
                    'error' => 'ETA has expired',
                    'expired_on' => $eta->expires_at->format('Y-m-d'),
                ];
            }

            return [
                'valid' => true,
                'type' => 'eta',
                'eta_number' => $eta->eta_number,
                'reference_number' => $eta->reference_number,
                'passport_number' => null, // Legacy format doesn't include passport
                'holder_name' => Crypt::decryptString($eta->first_name_encrypted) . ' ' . Crypt::decryptString($eta->last_name_encrypted),
                'nationality' => Crypt::decryptString($eta->nationality_encrypted),
                'entry_type' => $eta->entry_type,
                'issued_date' => $eta->approved_at?->format('Y-m-d'),
                'valid_until' => $eta->expires_at?->format('Y-m-d'),
                'requires_manual_passport_check' => true, // Flag for border officers
            ];
        } catch (\Exception $e) {
            return ['valid' => false, 'error' => 'Failed to decode QR data'];
        }
    }

    /**
     * Verify ETA QR code (new simple format: ETA_NUMBER|PASSPORT_NUMBER).
     * 
     * This format enables offline passport binding verification.
     * Border officers can instantly verify the passport matches without network access.
     */
    protected function verifyEtaSimpleFormat(string $qrData): array
    {
        try {
            $parts = explode('|', $qrData);
            
            if (count($parts) !== 2) {
                return ['valid' => false, 'error' => 'Invalid ETA QR format'];
            }

            [$etaNumber, $passportNumber] = $parts;
            $etaNumber = trim($etaNumber);
            $passportNumber = strtoupper(trim($passportNumber));

            // Find ETA
            $eta = EtaApplication::where('eta_number', $etaNumber)
                ->where('status', 'approved')
                ->first();

            if (!$eta) {
                return ['valid' => false, 'error' => 'ETA not found'];
            }

            // Verify passport binding
            $storedPassport = strtoupper(Crypt::decryptString($eta->passport_number_encrypted));
            if ($storedPassport !== $passportNumber) {
                return [
                    'valid' => false,
                    'error' => 'Passport binding verification failed',
                    'passport_mismatch' => true,
                ];
            }

            // Check expiry
            if ($eta->expires_at && $eta->expires_at < now()) {
                return [
                    'valid' => false,
                    'error' => 'ETA has expired',
                    'expired_on' => $eta->expires_at->format('Y-m-d'),
                ];
            }

            // Check if already consumed (single-entry)
            if ($eta->entry_type === 'single' && $eta->entry_consumed) {
                return [
                    'valid' => false,
                    'error' => 'ETA already used for entry',
                    'entry_date' => $eta->entry_date?->format('Y-m-d'),
                    'port_of_entry' => $eta->port_of_entry_used,
                ];
            }

            return [
                'valid' => true,
                'type' => 'eta',
                'eta_number' => $eta->eta_number,
                'reference_number' => $eta->reference_number,
                'passport_number' => $passportNumber, // Included for verification
                'passport_verified' => true, // Passport binding verified
                'holder_name' => Crypt::decryptString($eta->first_name_encrypted) . ' ' . Crypt::decryptString($eta->last_name_encrypted),
                'nationality' => Crypt::decryptString($eta->nationality_encrypted),
                'entry_type' => $eta->entry_type,
                'entry_consumed' => $eta->entry_consumed,
                'issued_date' => $eta->approved_at?->format('Y-m-d'),
                'valid_until' => $eta->expires_at?->format('Y-m-d'),
            ];
        } catch (\Exception $e) {
            return ['valid' => false, 'error' => 'Failed to verify ETA QR code'];
        }
    }

    /**
     * Generate verification hash for eVisa.
     */
    protected function generateVerificationHash(Application $application): string
    {
        // Get passport number (handle both encrypted and plain fields)
        $passportNumber = $application->passport_number_encrypted 
            ? Crypt::decryptString($application->passport_number_encrypted)
            : $application->passport_number;

        $data = $application->reference_number . 
                $passportNumber . 
                $application->decided_at?->format('Ymd');

        $secret = config('services.qr.evisa_secret');

        return hash_hmac('sha256', $data, $secret);
    }

    /**
     * Generate verification hash for ETA.
     */
    protected function generateEtaVerificationHash(EtaApplication $eta): string
    {
        $data = $eta->eta_number . 
                $eta->reference_number . 
                $eta->approved_at?->format('Ymd');

        $secret = config('services.qr.eta_secret');

        return hash_hmac('sha256', $data, $secret);
    }
}
