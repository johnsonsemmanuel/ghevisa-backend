<?php

namespace App\Services\RiskEvaluators;

use App\Models\Application;

/**
 * Interface for risk category evaluators.
 * 
 * Each evaluator assesses a specific risk category (Identity, Travel, Financial, etc.)
 * and returns triggered rules with their point contributions.
 */
interface RiskEvaluatorInterface
{
    /**
     * Evaluate risk for this category.
     * 
     * @param Application $application The application to evaluate
     * @return array [
     *   'category' => string,
     *   'max_points' => int,
     *   'points_earned' => int,
     *   'triggered_rules' => array [
     *     [
     *       'rule_id' => string,
     *       'description' => string,
     *       'points' => int,
     *       'severity' => string (low|medium|high|critical)
     *     ]
     *   ]
     * ]
     */
    public function evaluate(Application $application): array;
    
    /**
     * Get the maximum points this category can contribute.
     * 
     * @return int Maximum points for this category
     */
    public function getMaxPoints(): int;
    
    /**
     * Get the category name.
     * 
     * @return string Category name (e.g., 'Identity & Passport')
     */
    public function getCategoryName(): string;
}
