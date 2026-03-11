<?php

namespace App\Services\RiskEvaluators;

use App\Models\Application;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Evaluates identity and passport-related risks.
 * 
 * Checks for:
 * - Passport validity period
 * - Name matching between passport and application
 * - Passport OCR readability
 * - Recent passport issuance
 * - Flagged nationalities
 * 
 * Maximum contribution: 30 points
 */
class IdentityRiskEvaluator implements RiskEvaluatorInterface
{
    protected const MAX_POINTS = 30;
    protected const CATEGORY_NAME = 'Identity & Passport';
    
    /**
     * Evaluate identity and passport risks.
     * 
     * @param Application $application
     * @return array Category evaluation results
     */
    public function evaluate(Application $application): array
    {
        $triggeredRules = [];
        
        // Check passport validity
        if ($rule = $this->checkPassportValidity($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Check name match
        if ($rule = $this->checkNameMatch($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Check passport OCR status
        if ($rule = $this->checkPassportOcrStatus($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Check recent passport issuance
        if ($rule = $this->checkRecentPassportIssuance($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Check nationality flag
        if ($rule = $this->checkNationalityFlag($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Calculate total points and cap at maximum
        $pointsEarned = array_sum(array_column($triggeredRules, 'points'));
        $pointsEarned = min($pointsEarned, self::MAX_POINTS);
        
        return [
            'category' => self::CATEGORY_NAME,
            'max_points' => self::MAX_POINTS,
            'points_earned' => $pointsEarned,
            'triggered_rules' => $triggeredRules,
        ];
    }
    
    /**
     * Check if passport expires within 6 months of arrival (25 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkPassportValidity(Application $application): ?array
    {
        try {
            if (!$application->passport_expiry || !$application->intended_arrival) {
                return null;
            }
            
            $passportExpiry = Carbon::parse($application->passport_expiry);
            $intendedArrival = Carbon::parse($application->intended_arrival);
            $sixMonthsAfterArrival = $intendedArrival->copy()->addMonths(6);
            
            if ($passportExpiry->lt($sixMonthsAfterArrival)) {
                return [
                    'rule_id' => 'identity.passport_expiry',
                    'category' => self::CATEGORY_NAME,
                    'description' => config('risk_scoring.categories.identity.rules.passport_expiry.description'),
                    'points' => config('risk_scoring.categories.identity.rules.passport_expiry.points'),
                    'severity' => 'high',
                    'field_checked' => 'passport_expiry',
                    'actual_value' => $passportExpiry->toDateString(),
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Error checking passport validity', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
        }
        
        return null;
    }
    
    /**
     * Check if passport name matches application name (20 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkNameMatch(Application $application): ?array
    {
        // Get passport document
        $passportDoc = $application->documents()
            ->where('document_type', 'passport')
            ->first();
        
        if (!$passportDoc || !isset($passportDoc->ocr_data['name'])) {
            return null;
        }
        
        $passportName = strtolower(trim($passportDoc->ocr_data['name'] ?? ''));
        $applicationName = strtolower(trim($application->first_name . ' ' . $application->last_name));
        
        // Simple name matching - could be enhanced with fuzzy matching
        if ($passportName && $applicationName && $passportName !== $applicationName) {
            // Check if names are similar (allow for minor differences)
            similar_text($passportName, $applicationName, $percent);
            
            if ($percent < 80) { // Less than 80% similar
                return [
                    'rule_id' => 'identity.name_mismatch',
                    'category' => self::CATEGORY_NAME,
                    'description' => config('risk_scoring.categories.identity.rules.name_mismatch.description'),
                    'points' => config('risk_scoring.categories.identity.rules.name_mismatch.points'),
                    'severity' => 'high',
                    'field_checked' => 'name',
                    'actual_value' => "Passport: {$passportName}, Application: {$applicationName}",
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Check if passport OCR is unreadable (10 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkPassportOcrStatus(Application $application): ?array
    {
        $passportDoc = $application->documents()
            ->where('document_type', 'passport')
            ->first();
        
        if (!$passportDoc) {
            return null;
        }
        
        // Check if OCR failed or document is unreadable
        if (isset($passportDoc->ocr_status) && 
            in_array($passportDoc->ocr_status, ['failed', 'unreadable', 'error'])) {
            return [
                'rule_id' => 'identity.ocr_unreadable',
                'category' => self::CATEGORY_NAME,
                'description' => config('risk_scoring.categories.identity.rules.ocr_unreadable.description'),
                'points' => config('risk_scoring.categories.identity.rules.ocr_unreadable.points'),
                'severity' => 'medium',
                'field_checked' => 'ocr_status',
                'actual_value' => $passportDoc->ocr_status,
            ];
        }
        
        return null;
    }
    
    /**
     * Check if passport was issued recently (10 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkRecentPassportIssuance(Application $application): ?array
    {
        try {
            if (!$application->passport_issue_date) {
                return null;
            }
            
            $issueDate = Carbon::parse($application->passport_issue_date);
            $sixMonthsAgo = Carbon::now()->subMonths(6);
            
            if ($issueDate->gt($sixMonthsAgo)) {
                return [
                    'rule_id' => 'identity.recent_issuance',
                    'category' => self::CATEGORY_NAME,
                    'description' => config('risk_scoring.categories.identity.rules.recent_issuance.description'),
                    'points' => config('risk_scoring.categories.identity.rules.recent_issuance.points'),
                    'severity' => 'medium',
                    'field_checked' => 'passport_issue_date',
                    'actual_value' => $issueDate->toDateString(),
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Error checking passport issuance date', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
        }
        
        return null;
    }
    
    /**
     * Check if nationality is flagged for additional review (15 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkNationalityFlag(Application $application): ?array
    {
        if (!$application->nationality) {
            return null;
        }
        
        $highRiskNationalities = config('risk_scoring.flagged_nationalities.high_risk', []);
        $mediumRiskNationalities = config('risk_scoring.flagged_nationalities.medium_risk', []);
        
        $nationality = strtoupper($application->nationality);
        
        if (in_array($nationality, $highRiskNationalities) || 
            in_array($nationality, $mediumRiskNationalities)) {
            return [
                'rule_id' => 'identity.nationality_flag',
                'category' => self::CATEGORY_NAME,
                'description' => config('risk_scoring.categories.identity.rules.nationality_flag.description'),
                'points' => config('risk_scoring.categories.identity.rules.nationality_flag.points'),
                'severity' => in_array($nationality, $highRiskNationalities) ? 'high' : 'medium',
                'field_checked' => 'nationality',
                'actual_value' => $nationality,
            ];
        }
        
        return null;
    }
    
    /**
     * Get the maximum points this category can contribute.
     * 
     * @return int
     */
    public function getMaxPoints(): int
    {
        return self::MAX_POINTS;
    }
    
    /**
     * Get the category name.
     * 
     * @return string
     */
    public function getCategoryName(): string
    {
        return self::CATEGORY_NAME;
    }
}
