<?php

namespace App\Services;

use App\Models\Application;
use App\Models\EtaApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TAID (Travel Authorization ID) Service
 * 
 * Manages the central Travel Authorization ID system that serves as the
 * master identifier across all authorization types (ETA, Visa, etc.)
 * 
 * TAID Format: TAID-YYYYMMDD-XXXXXX
 * Example: TAID-20260311-A3F92B
 * 
 * Purpose:
 * - Unified tracking across ETA and Visa applications
 * - Central reference for border control
 * - Audit trail linkage
 * - Future entry/exit record correlation
 */
class TaidService
{
    /**
     * Generate a unique TAID
     * 
     * Format: GH-TA-YYYYMMDD-XXXX (per official specification)
     * - GH-TA: Ghana Travel Authorization prefix
     * - YYYYMMDD: Date of generation
     * - XXXX: 4-character alphanumeric unique code (random, non-sequential)
     * 
     * @return string
     */
    public function generate(): string
    {
        $date = date('Ymd');
        $attempts = 0;
        $maxAttempts = 10;

        do {
            // Generate 4-character random alphanumeric code (non-sequential)
            $random = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));
            $taid = "GH-TA-{$date}-{$random}";
            $attempts++;

            // Check uniqueness across all tables
            $existsInTravelAuth = \App\Models\TravelAuthorization::where('taid', $taid)->exists();
            $existsInApplications = Application::where('taid', $taid)->exists();
            $existsInEta = EtaApplication::where('taid', $taid)->exists();

            if (!$existsInTravelAuth && !$existsInApplications && !$existsInEta) {
                Log::info('TAID generated', [
                    'taid' => $taid,
                    'attempts' => $attempts,
                ]);
                return $taid;
            }
        } while ($attempts < $maxAttempts);

        // Fallback with timestamp for absolute uniqueness
        $timestamp = now()->format('His');
        $random = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 2));
        $taid = "GH-TA-{$date}-{$timestamp}{$random}";

        Log::warning('TAID generated with timestamp fallback', [
            'taid' => $taid,
            'attempts' => $attempts,
        ]);

        return $taid;
    }

    /**
     * Assign TAID to an application and create central travel_authorizations record
     * 
     * @param Application $application
     * @return Application
     */
    public function assignToApplication(Application $application): Application
    {
        if ($application->taid) {
            Log::info('Application already has TAID', [
                'reference' => $application->reference_number,
                'taid' => $application->taid,
            ]);
            return $application;
        }

        $taid = $this->generate();
        
        // Create central travel_authorizations record
        \App\Models\TravelAuthorization::create([
            'taid' => $taid,
            'passport_number_encrypted' => $application->passport_number_encrypted,
            'nationality' => $application->nationality_encrypted ?? $application->nationality,
            'authorization_type' => 'VISA',
            'status' => 'active',
        ]);
        
        // Assign to application
        $application->taid = $taid;
        $application->save();

        Log::info('TAID assigned to application', [
            'reference' => $application->reference_number,
            'taid' => $taid,
            'type' => 'visa',
        ]);

        return $application;
    }

    /**
     * Assign TAID to an ETA application and create central travel_authorizations record
     * 
     * @param EtaApplication $eta
     * @return EtaApplication
     */
    public function assignToEta(EtaApplication $eta): EtaApplication
    {
        if ($eta->taid) {
            Log::info('ETA already has TAID', [
                'reference' => $eta->reference_number,
                'taid' => $eta->taid,
            ]);
            return $eta;
        }

        $taid = $this->generate();
        
        // Create central travel_authorizations record
        \App\Models\TravelAuthorization::create([
            'taid' => $taid,
            'passport_number_encrypted' => $eta->passport_number_encrypted,
            'nationality' => $eta->nationality_encrypted ?? $eta->nationality,
            'authorization_type' => 'ETA',
            'status' => 'active',
        ]);
        
        // Assign to ETA
        $eta->taid = $taid;
        $eta->save();

        Log::info('TAID assigned to ETA', [
            'reference' => $eta->reference_number,
            'eta_number' => $eta->eta_number,
            'taid' => $taid,
            'type' => 'eta',
        ]);

        return $eta;
    }

    /**
     * Find authorization by TAID
     * 
     * Returns the authorization record (ETA or Visa) associated with the TAID
     * 
     * @param string $taid
     * @return array|null ['type' => 'eta'|'visa', 'record' => Model]
     */
    public function findByTaid(string $taid): ?array
    {
        // Check ETA applications first
        $eta = EtaApplication::where('taid', $taid)->first();
        if ($eta) {
            return [
                'type' => 'eta',
                'record' => $eta,
                'authorization_number' => $eta->eta_number,
                'reference_number' => $eta->reference_number,
            ];
        }

        // Check visa applications
        $application = Application::where('taid', $taid)->first();
        if ($application) {
            return [
                'type' => 'visa',
                'record' => $application,
                'authorization_number' => $application->evisa_number ?? $application->reference_number,
                'reference_number' => $application->reference_number,
            ];
        }

        return null;
    }

    /**
     * Get TAID from reference number
     * 
     * @param string $referenceNumber
     * @return string|null
     */
    public function getTaidFromReference(string $referenceNumber): ?string
    {
        // Try ETA first
        $eta = EtaApplication::where('reference_number', $referenceNumber)->first();
        if ($eta) {
            return $eta->taid;
        }

        // Try visa application
        $application = Application::where('reference_number', $referenceNumber)->first();
        if ($application) {
            return $application->taid;
        }

        return null;
    }

    /**
     * Validate TAID format
     * 
     * @param string $taid
     * @return bool
     */
    public function isValidFormat(string $taid): bool
    {
        // Format: GH-TA-YYYYMMDD-XXXX
        // Example: GH-TA-20260311-A3F9
        return (bool) preg_match('/^GH-TA-\d{8}-[A-Z0-9]{4,10}$/', $taid);
    }

    /**
     * Get authorization statistics by TAID
     * 
     * @param string $taid
     * @return array
     */
    public function getAuthorizationStats(string $taid): array
    {
        $authorization = $this->findByTaid($taid);

        if (!$authorization) {
            return [
                'found' => false,
                'taid' => $taid,
            ];
        }

        $record = $authorization['record'];
        $type = $authorization['type'];

        $stats = [
            'found' => true,
            'taid' => $taid,
            'type' => $type,
            'reference_number' => $authorization['reference_number'],
            'authorization_number' => $authorization['authorization_number'],
            'status' => $record->status,
            'created_at' => $record->created_at->toIso8601String(),
        ];

        // Add type-specific data
        if ($type === 'eta') {
            $stats['eta_number'] = $record->eta_number;
            $stats['expires_at'] = $record->expires_at?->toIso8601String();
            $stats['entry_type'] = $record->entry_type;
        } else {
            $stats['visa_type'] = $record->visaType?->name;
            $stats['assigned_agency'] = $record->assigned_agency;
            $stats['decided_at'] = $record->decided_at?->toIso8601String();
        }

        // Get border crossing count
        $stats['border_crossings'] = DB::table('border_crossings')
            ->where('taid', $taid)
            ->count();

        return $stats;
    }

    /**
     * Backfill TAIDs for existing records
     * 
     * This method should be run once to assign TAIDs to existing applications
     * 
     * @return array Statistics of backfill operation
     */
    public function backfillExistingRecords(): array
    {
        $stats = [
            'applications_updated' => 0,
            'eta_updated' => 0,
            'errors' => 0,
        ];

        // Backfill applications
        Application::whereNull('taid')->chunk(100, function ($applications) use (&$stats) {
            foreach ($applications as $application) {
                try {
                    $this->assignToApplication($application);
                    $stats['applications_updated']++;
                } catch (\Exception $e) {
                    Log::error('Failed to assign TAID to application', [
                        'reference' => $application->reference_number,
                        'error' => $e->getMessage(),
                    ]);
                    $stats['errors']++;
                }
            }
        });

        // Backfill ETA applications
        EtaApplication::whereNull('taid')->chunk(100, function ($etas) use (&$stats) {
            foreach ($etas as $eta) {
                try {
                    $this->assignToEta($eta);
                    $stats['eta_updated']++;
                } catch (\Exception $e) {
                    Log::error('Failed to assign TAID to ETA', [
                        'reference' => $eta->reference_number,
                        'error' => $e->getMessage(),
                    ]);
                    $stats['errors']++;
                }
            }
        });

        Log::info('TAID backfill completed', $stats);

        return $stats;
    }
}
