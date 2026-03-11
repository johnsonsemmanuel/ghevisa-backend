<?php

namespace App\Services\RiskEvaluators;

use App\Models\Application;
use App\Models\Watchlist;
use Illuminate\Support\Facades\Log;

/**
 * Evaluates immigration history and compliance.
 * 
 * Checks for:
 * - Prior visa refusals
 * - Prior overstays
 * - Deportation records
 * - Watchlist matches
 * - Inconsistent declarations
 * 
 * Maximum contribution: 20 points (watchlist can add up to 50)
 */
class ImmigrationHistoryEvaluator implements RiskEvaluatorInterface
{
    protected const MAX_POINTS = 20;
    protected const CATEGORY_NAME = 'Immigration History';
    
    /**
     * Evaluate immigration history risks.
     * 
     * @param Application $application
     * @return array Category evaluation results
     */
    public function evaluate(Application $application): array
    {
        $triggeredRules = [];
        
        // Check prior refusal
        if ($rule = $this->checkPriorRefusal($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Check prior overstay
        if ($rule = $this->checkPriorOverstay($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Check deportation record
        if ($rule = $this->checkDeportationRecord($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Check watchlist match
        if ($rule = $this->checkWatchlistMatch($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Check declaration consistency
        if ($rule = $this->checkDeclarationConsistency($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Calculate total points
        // Note: We don't cap at MAX_POINTS for this category because watchlist can add up to 50
        $pointsEarned = array_sum(array_column($triggeredRules, 'points'));
        
        return [
            'category' => self::CATEGORY_NAME,
            'max_points' => self::MAX_POINTS,
            'points_earned' => $pointsEarned,
            'triggered_rules' => $triggeredRules,
        ];
    }
    
    /**
     * Check for prior visa refusal (20 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkPriorRefusal(Application $application): ?array
    {
        if ($application->entry_denied_before === true) {
            return [
                'rule_id' => 'immigration.prior_refusal',
                'category' => self::CATEGORY_NAME,
                'description' => config('risk_scoring.categories.immigration.rules.prior_refusal.description'),
                'points' => config('risk_scoring.categories.immigration.rules.prior_refusal.points'),
                'severity' => 'high',
                'field_checked' => 'entry_denied_before',
                'actual_value' => 'true',
            ];
        }
        
        return null;
    }
    
    /**
     * Check for prior overstay in Ghana (30 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkPriorOverstay(Application $application): ?array
    {
        if ($application->overstayed_before === true) {
            return [
                'rule_id' => 'immigration.prior_overstay',
                'category' => self::CATEGORY_NAME,
                'description' => config('risk_scoring.categories.immigration.rules.prior_overstay.description'),
                'points' => config('risk_scoring.categories.immigration.rules.prior_overstay.points'),
                'severity' => 'critical',
                'field_checked' => 'overstayed_before',
                'actual_value' => 'true',
            ];
        }
        
        return null;
    }
    
    /**
     * Check for prior deportation record (40 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkDeportationRecord(Application $application): ?array
    {
        try {
            // Check watchlist for deportation records
            $matches = Watchlist::checkMatch(
                $application->first_name ?? '',
                $application->last_name ?? '',
                $application->passport_number,
                $application->nationality,
                $application->date_of_birth ? new \DateTime($application->date_of_birth) : null
            );
            
            // Look for deportation-specific entries
            foreach ($matches as $match) {
                if ($match['list_type'] === 'deportation' || 
                    str_contains(strtolower($match['reason'] ?? ''), 'deport')) {
                    return [
                        'rule_id' => 'immigration.deportation',
                        'category' => self::CATEGORY_NAME,
                        'description' => config('risk_scoring.categories.immigration.rules.deportation.description'),
                        'points' => config('risk_scoring.categories.immigration.rules.deportation.points'),
                        'severity' => 'critical',
                        'field_checked' => 'watchlist_deportation',
                        'actual_value' => "Match score: {$match['match_score']}, Reason: {$match['reason']}",
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::error('Error checking deportation record', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
        }
        
        return null;
    }
    
    /**
     * Check for sanctions or watchlist proximity (50 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkWatchlistMatch(Application $application): ?array
    {
        try {
            $matches = Watchlist::checkMatch(
                $application->first_name ?? '',
                $application->last_name ?? '',
                $application->passport_number,
                $application->nationality,
                $application->date_of_birth ? new \DateTime($application->date_of_birth) : null
            );
            
            // If there are any matches, trigger the rule
            if (!empty($matches)) {
                $highestMatch = $matches[0]; // Already sorted by score
                
                return [
                    'rule_id' => 'immigration.watchlist_match',
                    'category' => self::CATEGORY_NAME,
                    'description' => config('risk_scoring.categories.immigration.rules.watchlist_match.description'),
                    'points' => config('risk_scoring.categories.immigration.rules.watchlist_match.points'),
                    'severity' => $highestMatch['severity'] ?? 'critical',
                    'field_checked' => 'watchlist',
                    'actual_value' => "Match score: {$highestMatch['match_score']}, Type: {$highestMatch['list_type']}, Matched: " . implode(', ', $highestMatch['matched_fields']),
                ];
            }
        } catch (\Exception $e) {
            Log::error('Error checking watchlist match', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
            
            // If watchlist service fails, skip this rule
            return null;
        }
        
        return null;
    }
    
    /**
     * Check for inconsistent immigration declarations (25 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkDeclarationConsistency(Application $application): ?array
    {
        $inconsistencies = [];
        
        // Check if visited Ghana before but no previous visa number
        if ($application->visited_ghana_before === true && 
            empty($application->previous_visa_number)) {
            $inconsistencies[] = 'Visited Ghana before but no previous visa number provided';
        }
        
        // Check if has previous visa but says never visited
        if (!empty($application->previous_visa_number) && 
            $application->visited_ghana_before === false) {
            $inconsistencies[] = 'Has previous visa number but claims never visited Ghana';
        }
        
        // Check if denied before but no explanation in purpose details
        if ($application->entry_denied_before === true && 
            empty($application->purpose_details)) {
            $inconsistencies[] = 'Prior visa refusal declared but no explanation provided';
        }
        
        // Check if overstayed before but visited Ghana is false
        if ($application->overstayed_before === true && 
            $application->visited_ghana_before === false) {
            $inconsistencies[] = 'Overstayed before but claims never visited Ghana';
        }
        
        if (!empty($inconsistencies)) {
            return [
                'rule_id' => 'immigration.inconsistent_declarations',
                'category' => self::CATEGORY_NAME,
                'description' => config('risk_scoring.categories.immigration.rules.inconsistent_declarations.description'),
                'points' => config('risk_scoring.categories.immigration.rules.inconsistent_declarations.points'),
                'severity' => 'high',
                'field_checked' => 'immigration_declarations',
                'actual_value' => implode('; ', $inconsistencies),
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
