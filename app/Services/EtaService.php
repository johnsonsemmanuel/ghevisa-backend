<?php

namespace App\Services;

use App\Models\Application;
use App\Models\CountryVisaEligibility;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Illuminate\Support\Facades\Storage;

class EtaService
{
    /**
     * Generate a unique ETA number
     */
    public function generateEtaNumber(Application $application): string
    {
        $prefix = 'GH-ETA';
        $date = now()->format('Ymd');
        $random = strtoupper(Str::random(6));
        
        return "{$prefix}-{$date}-{$random}";
    }

    /**
     * Check if an application is ETA eligible based on nationality
     */
    public function isEtaEligible(string $nationalityCode): bool
    {
        return CountryVisaEligibility::isEtaEligible($nationalityCode);
    }

    /**
     * Get the authorization type for a nationality
     */
    public function getAuthorizationType(string $nationalityCode): string
    {
        return CountryVisaEligibility::getAuthorizationType($nationalityCode);
    }

    /**
     * Get the fee for a nationality
     */
    public function getFee(string $nationalityCode): float
    {
        return CountryVisaEligibility::getFee($nationalityCode);
    }

    /**
     * Process ETA approval - generate ETA number and QR code
     */
    public function processEtaApproval(Application $application): Application
    {
        // Generate ETA number if not already set
        if (!$application->eta_number) {
            $application->eta_number = $this->generateEtaNumber($application);
        }

        // Set ETA validity (90 days from approval)
        $application->eta_validity_days = 90;
        $application->entry_type_granted = $application->entry_type ?? 'single';

        // Generate QR code
        $qrCodePath = $this->generateQrCode($application);
        if ($qrCodePath) {
            $application->evisa_qr_code = $qrCodePath;
        }

        $application->save();

        return $application;
    }

    /**
     * Generate QR code for ETA
     */
    public function generateQrCode(Application $application): ?string
    {
        try {
            $qrData = json_encode([
                'type' => 'ETA',
                'eta_number' => $application->eta_number,
                'reference' => $application->reference_number,
                'name' => $application->first_name . ' ' . $application->last_name,
                'nationality' => $application->nationality,
                'passport' => substr($application->passport_number, 0, 3) . '***' . substr($application->passport_number, -3),
                'valid_until' => now()->addDays($application->eta_validity_days ?? 90)->format('Y-m-d'),
                'entry_type' => $application->entry_type_granted ?? 'single',
                'issued' => now()->format('Y-m-d H:i:s'),
            ]);

            $qrCode = QrCode::format('png')
                ->size(300)
                ->margin(2)
                ->generate($qrData);

            $filename = 'eta-qr-' . $application->reference_number . '.png';
            $path = 'qrcodes/' . $filename;

            Storage::disk('public')->put($path, $qrCode);

            return $path;
        } catch (\Exception $e) {
            \Log::error('Failed to generate ETA QR code: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get ETA processing time in hours based on nationality
     */
    public function getProcessingTimeHours(string $nationalityCode): int
    {
        $eligibility = CountryVisaEligibility::getByCountryCode($nationalityCode);
        
        if (!$eligibility) {
            return 72; // Default 3 days for unknown countries
        }

        // ECOWAS countries get fastest processing
        if ($eligibility->bloc === 'ECOWAS') {
            return 24;
        }

        // AU countries
        if ($eligibility->bloc === 'AU') {
            return 48;
        }

        // Caribbean countries
        if ($eligibility->bloc === 'CARICOM') {
            return 24;
        }

        // eVisa required countries
        return 72;
    }

    /**
     * Determine if application should be auto-approved
     * (Only for low-risk ETA applications)
     */
    public function canAutoApprove(Application $application): bool
    {
        // Only ETA applications can be auto-approved
        if ($application->authorization_type !== 'eta') {
            return false;
        }

        // Check risk level
        if ($application->risk_level === 'high' || $application->watchlist_flagged) {
            return false;
        }

        // Check security declarations
        if ($application->entry_denied_before || $application->criminal_conviction) {
            return false;
        }

        // Check nationality eligibility
        $eligibility = CountryVisaEligibility::getByCountryCode($application->nationality);
        if (!$eligibility || $eligibility->authorization_type !== 'eta') {
            return false;
        }

        return true;
    }

    /**
     * Get ETA summary for display
     */
    public function getEtaSummary(Application $application): array
    {
        return [
            'eta_number' => $application->eta_number,
            'reference_number' => $application->reference_number,
            'applicant_name' => $application->first_name . ' ' . $application->last_name,
            'nationality' => $application->nationality,
            'passport_number' => $application->passport_number,
            'authorization_type' => $application->authorization_type,
            'entry_type' => $application->entry_type_granted ?? $application->entry_type,
            'validity_days' => $application->eta_validity_days ?? 90,
            'valid_from' => $application->decided_at?->format('Y-m-d'),
            'valid_until' => $application->decided_at?->addDays($application->eta_validity_days ?? 90)->format('Y-m-d'),
            'port_of_entry' => $application->port_of_entry,
            'intended_arrival' => $application->intended_arrival?->format('Y-m-d'),
            'qr_code_url' => $application->evisa_qr_code 
                ? Storage::disk('public')->url($application->evisa_qr_code) 
                : null,
            'status' => $application->status,
        ];
    }
}
