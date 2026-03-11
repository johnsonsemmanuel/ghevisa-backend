<?php

namespace App\Services;

use App\Models\Application;
use App\Models\RiskAssessment;
use App\Services\RiskEvaluators\IdentityRiskEvaluator;
use App\Services\RiskEvaluators\TravelPatternEvaluator;
use App\Services\RiskEvaluators\FinancialRiskEvaluator;
use App\Services\RiskEvaluators\ImmigrationHistoryEvaluator;
use App\Services\RiskEvaluators\DocumentQualityEvaluator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rule-based risk assessment engine for visa applications.
 * 
 * Replaces the ML-based risk engine with transparent, auditable rule-based logic.
 * Evaluates applications across five risk categories and provides clear explanations
 * for all risk scores.
 */
class RuleBasedRiskEngineService
{
    protected IdentityRiskEvaluator $identityEvaluator;
    protected TravelPatternEvaluator $travelEvaluator;
    protected FinancialRiskEvaluator $financialEvaluator;
    protected ImmigrationHistoryEvaluator $immigrationEvaluator;
    protected DocumentQualityEvaluator $documentEvaluator;
    protected RiskReasonGenerator $reasonGenerator;
    
    /**
     * Create a new risk engine service instance.
     */
    public function __construct(
        IdentityRiskEvaluator $identityEvaluator,
        TravelPatternEvaluator $travelEvaluator,
        FinancialRiskEvaluator $financialEvaluator,
        ImmigrationHistoryEvaluator $immigrationEvaluator,
        DocumentQualityEvaluator $documentEvaluator,
        RiskReasonGenerator $reasonGenerator
    ) {
        $this->identityEvaluator = $identityEvaluator;
        $this->travelEvaluator = $travelEvaluator;
        $this->financialEvaluator = $financialEvaluator;
        $this->immigrationEvaluator = $immigrationEvaluator;
        $this->documentEvaluator = $documentEvaluator;
        $this->reasonGenerator = $reasonGenerator;
    }
    
    /**
     * Assess risk for an application using rule-based evaluation.
     * 
     * @param Application $application
     * @return array [
     *   'score' => int (0-100),
     *   'level' => string (low|medium|high|critical),
     *   'confidence' => float (always 100.0 for rule-based),
     *   'features' => array (detailed evaluation by category),
     *   'recommendations' => array (human-readable actions),
     *   'assessment_id' => int
     * ]
     */
    public function assessRisk(Application $application): array
    {
        try {
            Log::info('Risk assessment started', [
                'application_id' => $application->id,
                'reference_number' => $application->reference_number,
            ]);
            
            // Eager load required relationships
            $application->load(['documents', 'visaType', 'user']);
            
            // Evaluate all risk categories
            $categoryResults = $this->evaluateAllCategories($application);
            
            // Calculate total risk score
            $totalScore = $this->calculateTotalScore($categoryResults);
            
            // Determine risk level
            $riskLevel = $this->determineRiskLevel($totalScore);
            
            // Generate risk reasons
            $riskReasons = $this->reasonGenerator->generate($categoryResults);
            
            // Generate recommendations
            $recommendations = $this->generateRecommendations($categoryResults, $totalScore, $riskLevel);
            
            // Prepare results
            $results = [
                'score' => $totalScore,
                'level' => $riskLevel,
                'confidence' => 100.0, // Rule-based system has 100% confidence
                'features' => $categoryResults,
                'recommendations' => $recommendations,
                'risk_reasons' => $riskReasons,
            ];
            
            // Store assessment in database
            $assessment = null;
            DB::transaction(function () use ($application, $results, &$assessment) {
                $assessment = $this->storeAssessment($application, $results);
                $this->updateApplication($application, $results);
            });
            
            $results['assessment_id'] = $assessment ? $assessment->id : null;
            
            Log::info('Risk assessment completed', [
                'application_id' => $application->id,
                'risk_score' => $totalScore,
                'risk_level' => $riskLevel,
                'triggered_rules_count' => count($riskReasons),
            ]);
            
            return $results;
            
        } catch (\Exception $e) {
            Log::error('Risk assessment failed', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Return error response
            return [
                'score' => 0,
                'level' => 'unknown',
                'confidence' => 0.0,
                'features' => [],
                'recommendations' => [
                    [
                        'priority' => 'critical',
                        'action' => 'MANUAL_REVIEW_REQUIRED',
                        'reason' => 'Risk assessment failed',
                        'details' => 'Error during risk assessment. Manual review required.',
                    ]
                ],
                'assessment_id' => null,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Evaluate all risk categories and aggregate results.
     * 
     * @param Application $application
     * @return array Category evaluation results
     */
    protected function evaluateAllCategories(Application $application): array
    {
        $results = [];
        
        // Evaluate Identity & Passport
        $identityResult = $this->identityEvaluator->evaluate($application);
        $results[] = $identityResult;
        Log::debug('Category evaluated', [
            'application_id' => $application->id,
            'category' => 'identity',
            'points_earned' => $identityResult['points_earned'],
            'triggered_rules' => count($identityResult['triggered_rules']),
        ]);
        
        // Evaluate Travel Pattern
        $travelResult = $this->travelEvaluator->evaluate($application);
        $results[] = $travelResult;
        Log::debug('Category evaluated', [
            'application_id' => $application->id,
            'category' => 'travel',
            'points_earned' => $travelResult['points_earned'],
            'triggered_rules' => count($travelResult['triggered_rules']),
        ]);
        
        // Evaluate Financial & Sponsorship
        $financialResult = $this->financialEvaluator->evaluate($application);
        $results[] = $financialResult;
        Log::debug('Category evaluated', [
            'application_id' => $application->id,
            'category' => 'financial',
            'points_earned' => $financialResult['points_earned'],
            'triggered_rules' => count($financialResult['triggered_rules']),
        ]);
        
        // Evaluate Immigration History
        $immigrationResult = $this->immigrationEvaluator->evaluate($application);
        $results[] = $immigrationResult;
        Log::debug('Category evaluated', [
            'application_id' => $application->id,
            'category' => 'immigration',
            'points_earned' => $immigrationResult['points_earned'],
            'triggered_rules' => count($immigrationResult['triggered_rules']),
        ]);
        
        // Evaluate Document Quality
        $documentResult = $this->documentEvaluator->evaluate($application);
        $results[] = $documentResult;
        Log::debug('Category evaluated', [
            'application_id' => $application->id,
            'category' => 'document',
            'points_earned' => $documentResult['points_earned'],
            'triggered_rules' => count($documentResult['triggered_rules']),
        ]);
        
        return $results;
    }
    
    /**
     * Calculate total risk score from category evaluations.
     * 
     * @param array $categoryResults
     * @return int Total score (capped at 100)
     */
    protected function calculateTotalScore(array $categoryResults): int
    {
        $totalScore = 0;
        
        foreach ($categoryResults as $result) {
            $totalScore += $result['points_earned'] ?? 0;
        }
        
        // Cap at 100
        return min(100, $totalScore);
    }
    
    /**
     * Determine risk level from score.
     * 
     * @param int $score
     * @return string Risk level (low|medium|high|critical)
     */
    protected function determineRiskLevel(int $score): string
    {
        if ($score >= 0 && $score <= 24) {
            return 'low';
        } elseif ($score >= 25 && $score <= 49) {
            return 'medium';
        } elseif ($score >= 50 && $score <= 74) {
            return 'high';
        } elseif ($score >= 75) {
            return 'critical';
        }
        
        // Default to low if not found
        return 'low';
    }
    
    /**
     * Generate recommendations based on risk level and triggered rules.
     * 
     * @param array $categoryResults
     * @param int $score
     * @param string $level
     * @return array Recommendations
     */
    protected function generateRecommendations(array $categoryResults, int $score, string $level): array
    {
        $recommendations = [];
        
        // Get base recommendation for risk level
        $baseRecommendation = config("risk_scoring.recommendations.{$level}");
        
        if ($baseRecommendation) {
            $recommendations[] = $baseRecommendation;
        }
        
        // Add specific recommendations based on triggered rules
        foreach ($categoryResults as $categoryResult) {
            if (empty($categoryResult['triggered_rules'])) {
                continue;
            }
            
            foreach ($categoryResult['triggered_rules'] as $rule) {
                // Add specific recommendations for high-severity rules
                if (($rule['severity'] ?? '') === 'critical') {
                    $recommendations[] = [
                        'priority' => 'critical',
                        'action' => 'IMMEDIATE_REVIEW',
                        'reason' => $rule['description'],
                        'details' => "Critical issue detected: {$rule['description']}",
                    ];
                }
            }
        }
        
        return $recommendations;
    }
    
    /**
     * Store risk assessment in database.
     * 
     * @param Application $application
     * @param array $results
     * @return RiskAssessment
     */
    protected function storeAssessment(Application $application, array $results): RiskAssessment
    {
        // Check if watchlist was triggered
        $watchlistFlagged = false;
        $watchlistMatches = [];
        
        foreach ($results['features'] as $category) {
            foreach ($category['triggered_rules'] ?? [] as $rule) {
                if ($rule['rule_id'] === 'immigration.watchlist_match') {
                    $watchlistFlagged = true;
                    $watchlistMatches[] = [
                        'rule' => $rule['rule_id'],
                        'description' => $rule['description'],
                        'details' => $rule['actual_value'] ?? '',
                    ];
                }
            }
        }
        
        // Create or update risk assessment
        $assessment = RiskAssessment::updateOrCreate(
            ['application_id' => $application->id],
            [
                'risk_score' => $results['score'],
                'risk_level' => $results['level'],
                'risk_reasons' => $results['risk_reasons'],
                'factors' => $results['features'],
                'watchlist_match' => $watchlistFlagged,
                'watchlist_matches' => $watchlistMatches,
                'status' => 'completed',
                'assessed_at' => now(),
                'risk_last_updated' => now(),
            ]
        );
        
        return $assessment;
    }
    
    /**
     * Update application with risk data.
     * 
     * @param Application $application
     * @param array $results
     * @return void
     */
    protected function updateApplication(Application $application, array $results): void
    {
        // Check if watchlist was triggered or if risk is critical
        $watchlistFlagged = false;
        $isCriticalRisk = $results['level'] === 'critical';
        
        foreach ($results['features'] as $category) {
            foreach ($category['triggered_rules'] ?? [] as $rule) {
                if ($rule['rule_id'] === 'immigration.watchlist_match') {
                    $watchlistFlagged = true;
                    break 2;
                }
            }
        }
        
        // Flag if watchlist match OR critical risk
        $shouldFlag = $watchlistFlagged || $isCriticalRisk;
        
        // Only update fields that exist in the applications table
        $updateData = [
            'risk_score' => $results['score'],
            'risk_screening_status' => $shouldFlag ? 'flagged' : 'cleared',
            'watchlist_flagged' => $watchlistFlagged,
        ];
        
        $application->update($updateData);
    }
}
