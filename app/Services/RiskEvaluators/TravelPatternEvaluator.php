<?php

namespace App\Services\RiskEvaluators;

use App\Models\Application;
use Illuminate\Support\Facades\Log;

/**
 * Evaluates travel pattern consistency and legitimacy.
 * 
 * Checks for:
 * - Duration consistency with visa type
 * - Return ticket documentation
 * - Accommodation information
 * - High-risk region travel
 * - Purpose consistency with documents
 * 
 * Maximum contribution: 20 points
 */
class TravelPatternEvaluator implements RiskEvaluatorInterface
{
    protected const MAX_POINTS = 20;
    protected const CATEGORY_NAME = 'Travel Pattern';
    
    /**
     * Evaluate travel pattern risks.
     * 
     * @param Application $application
     * @return array Category evaluation results
     */
    public function evaluate(Application $application): array
    {
        $triggeredRules = [];
        
        // Check duration consistency
        if ($rule = $this->checkDurationConsistency($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Check return ticket
        if ($rule = $this->checkReturnTicket($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Check accommodation info
        if ($rule = $this->checkAccommodationInfo($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Check high-risk travel
        if ($rule = $this->checkHighRiskTravel($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Check purpose consistency
        if ($rule = $this->checkPurposeConsistency($application)) {
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
     * Check if stay duration is inconsistent with visa type (15 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkDurationConsistency(Application $application): ?array
    {
        if (!$application->duration_days || !$application->visaType) {
            return null;
        }
        
        $visaTypeName = strtolower($application->visaType->name ?? '');
        $duration = $application->duration_days;
        
        // Get duration limits from config
        $limits = config('risk_scoring.visa_type_duration_limits');
        
        // Find matching visa type
        $matchedType = null;
        foreach ($limits as $type => $range) {
            if (str_contains($visaTypeName, $type)) {
                $matchedType = $type;
                break;
            }
        }
        
        if ($matchedType) {
            $min = $limits[$matchedType]['min'];
            $max = $limits[$matchedType]['max'];
            
            if ($duration < $min || $duration > $max) {
                return [
                    'rule_id' => 'travel.duration_inconsistent',
                    'category' => self::CATEGORY_NAME,
                    'description' => config('risk_scoring.categories.travel.rules.duration_inconsistent.description'),
                    'points' => config('risk_scoring.categories.travel.rules.duration_inconsistent.points'),
                    'severity' => 'medium',
                    'field_checked' => 'duration_days',
                    'actual_value' => "{$duration} days (expected {$min}-{$max} for {$matchedType})",
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Check if return ticket document is missing (10 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkReturnTicket(Application $application): ?array
    {
        // Check if return ticket document exists
        $hasReturnTicket = $application->documents()
            ->whereIn('document_type', ['return_ticket', 'flight_ticket', 'onward_ticket'])
            ->exists();
        
        if (!$hasReturnTicket && !$application->return_date) {
            return [
                'rule_id' => 'travel.no_return_ticket',
                'category' => self::CATEGORY_NAME,
                'description' => config('risk_scoring.categories.travel.rules.no_return_ticket.description'),
                'points' => config('risk_scoring.categories.travel.rules.no_return_ticket.points'),
                'severity' => 'medium',
                'field_checked' => 'return_ticket',
                'actual_value' => 'missing',
            ];
        }
        
        return null;
    }
    
    /**
     * Check if accommodation information is missing (10 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkAccommodationInfo(Application $application): ?array
    {
        $hasAccommodation = !empty($application->accommodation_type) ||
                           !empty($application->hotel_name) ||
                           !empty($application->host_name) ||
                           !empty($application->address_in_ghana);
        
        if (!$hasAccommodation) {
            return [
                'rule_id' => 'travel.no_accommodation',
                'category' => self::CATEGORY_NAME,
                'description' => config('risk_scoring.categories.travel.rules.no_accommodation.description'),
                'points' => config('risk_scoring.categories.travel.rules.no_accommodation.points'),
                'severity' => 'medium',
                'field_checked' => 'accommodation',
                'actual_value' => 'missing',
            ];
        }
        
        return null;
    }
    
    /**
     * Check for frequent recent travel to high-risk regions (20 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkHighRiskTravel(Application $application): ?array
    {
        if ($application->high_risk_travel === true) {
            return [
                'rule_id' => 'travel.high_risk_regions',
                'category' => self::CATEGORY_NAME,
                'description' => config('risk_scoring.categories.travel.rules.high_risk_regions.description'),
                'points' => config('risk_scoring.categories.travel.rules.high_risk_regions.points'),
                'severity' => 'high',
                'field_checked' => 'high_risk_travel',
                'actual_value' => 'true',
            ];
        }
        
        return null;
    }
    
    /**
     * Check if travel purpose matches supporting documents (15 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkPurposeConsistency(Application $application): ?array
    {
        $purpose = strtolower($application->purpose_of_visit ?? '');
        
        if (empty($purpose)) {
            return null;
        }
        
        // Check for business purpose without business documents
        if (str_contains($purpose, 'business')) {
            $hasBusinessDocs = $application->documents()
                ->whereIn('document_type', ['invitation_letter', 'company_registration', 'business_letter'])
                ->exists();
            
            if (!$hasBusinessDocs && empty($application->host_company_name)) {
                return [
                    'rule_id' => 'travel.purpose_mismatch',
                    'category' => self::CATEGORY_NAME,
                    'description' => config('risk_scoring.categories.travel.rules.purpose_mismatch.description'),
                    'points' => config('risk_scoring.categories.travel.rules.purpose_mismatch.points'),
                    'severity' => 'medium',
                    'field_checked' => 'purpose_of_visit',
                    'actual_value' => 'Business purpose without supporting documents',
                ];
            }
        }
        
        // Check for tourism purpose without accommodation
        if (str_contains($purpose, 'tourism') || str_contains($purpose, 'tourist')) {
            $hasAccommodation = !empty($application->hotel_name) || 
                              !empty($application->host_name) ||
                              $application->documents()
                                  ->whereIn('document_type', ['hotel_booking', 'accommodation'])
                                  ->exists();
            
            if (!$hasAccommodation) {
                return [
                    'rule_id' => 'travel.purpose_mismatch',
                    'category' => self::CATEGORY_NAME,
                    'description' => config('risk_scoring.categories.travel.rules.purpose_mismatch.description'),
                    'points' => config('risk_scoring.categories.travel.rules.purpose_mismatch.points'),
                    'severity' => 'medium',
                    'field_checked' => 'purpose_of_visit',
                    'actual_value' => 'Tourism purpose without accommodation details',
                ];
            }
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
