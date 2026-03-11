<?php

namespace App\Services;

/**
 * Generates human-readable risk explanations from triggered rules.
 * 
 * Takes category evaluation results and produces a sorted list of
 * the top 3-5 risk reasons for display to immigration officers.
 */
class RiskReasonGenerator
{
    /**
     * Generate top risk reasons from category evaluations.
     * 
     * @param array $categoryResults Array of category evaluation results
     * @return array Top 3-5 risk reasons sorted by points (descending)
     */
    public function generate(array $categoryResults): array
    {
        // Collect all triggered rules from all categories
        $allRules = [];
        
        foreach ($categoryResults as $categoryResult) {
            if (isset($categoryResult['triggered_rules']) && is_array($categoryResult['triggered_rules'])) {
                foreach ($categoryResult['triggered_rules'] as $rule) {
                    $allRules[] = $rule;
                }
            }
        }
        
        // If no rules triggered, return empty array
        if (empty($allRules)) {
            return [];
        }
        
        // Sort rules by points (descending)
        $sortedRules = $this->sortByPoints($allRules);
        
        // Select top 3-5 reasons (or all if fewer than 5)
        $topRules = array_slice($sortedRules, 0, 5);
        
        // Format each rule as a human-readable reason
        $reasons = [];
        foreach ($topRules as $rule) {
            $reasons[] = $this->formatReason($rule);
        }
        
        return $reasons;
    }
    
    /**
     * Format a triggered rule as a human-readable reason.
     * 
     * @param array $rule Rule data
     * @return string Formatted reason
     */
    protected function formatReason(array $rule): string
    {
        $description = $rule['description'] ?? 'Unknown risk factor';
        $points = $rule['points'] ?? 0;
        
        // Add point value to make it clear how much this contributes
        return "{$description} (+{$points} points)";
    }
    
    /**
     * Sort triggered rules by point contribution (descending).
     * 
     * @param array $rules Array of rule data
     * @return array Sorted rules
     */
    protected function sortByPoints(array $rules): array
    {
        usort($rules, function ($a, $b) {
            $pointsA = $a['points'] ?? 0;
            $pointsB = $b['points'] ?? 0;
            
            // Sort descending (highest points first)
            return $pointsB <=> $pointsA;
        });
        
        return $rules;
    }
}
