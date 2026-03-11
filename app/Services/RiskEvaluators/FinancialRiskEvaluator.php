<?php

namespace App\Services\RiskEvaluators;

use App\Models\Application;
use Illuminate\Support\Facades\Log;

/**
 * Evaluates financial sufficiency and sponsor verification.
 * 
 * Checks for:
 * - Proof of funds documentation
 * - Bank statement verification status
 * - Host verification status
 * - Sponsor details completeness
 * - Business invitation letters
 * 
 * Maximum contribution: 20 points
 */
class FinancialRiskEvaluator implements RiskEvaluatorInterface
{
    protected const MAX_POINTS = 20;
    protected const CATEGORY_NAME = 'Financial & Sponsorship';
    
    /**
     * Evaluate financial and sponsorship risks.
     * 
     * @param Application $application
     * @return array Category evaluation results
     */
    public function evaluate(Application $application): array
    {
        $triggeredRules = [];
        
        // Check proof of funds
        if ($rule = $this->checkProofOfFunds($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Check bank statement status
        if ($rule = $this->checkBankStatementStatus($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Check host verification
        if ($rule = $this->checkHostVerification($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Check sponsor details
        if ($rule = $this->checkSponsorDetails($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Check business invitation
        if ($rule = $this->checkBusinessInvitation($application)) {
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
     * Check if proof of funds documents are missing (20 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkProofOfFunds(Application $application): ?array
    {
        // Check for financial documents
        $hasFinancialDocs = $application->documents()
            ->whereIn('document_type', [
                'bank_statement',
                'financial_proof',
                'proof_of_funds',
                'sponsor_letter',
                'employment_letter'
            ])
            ->exists();
        
        if (!$hasFinancialDocs) {
            return [
                'rule_id' => 'financial.no_proof_of_funds',
                'category' => self::CATEGORY_NAME,
                'description' => config('risk_scoring.categories.financial.rules.no_proof_of_funds.description'),
                'points' => config('risk_scoring.categories.financial.rules.no_proof_of_funds.points'),
                'severity' => 'high',
                'field_checked' => 'financial_documents',
                'actual_value' => 'missing',
            ];
        }
        
        return null;
    }
    
    /**
     * Check if bank statements are under verification (10 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkBankStatementStatus(Application $application): ?array
    {
        // Check if bank statement exists and is pending verification
        $bankStatement = $application->documents()
            ->where('document_type', 'bank_statement')
            ->first();
        
        if ($bankStatement && 
            isset($bankStatement->verification_status) &&
            $bankStatement->verification_status === 'pending') {
            return [
                'rule_id' => 'financial.bank_statement_pending',
                'category' => self::CATEGORY_NAME,
                'description' => config('risk_scoring.categories.financial.rules.bank_statement_pending.description'),
                'points' => config('risk_scoring.categories.financial.rules.bank_statement_pending.points'),
                'severity' => 'medium',
                'field_checked' => 'bank_statement_verification',
                'actual_value' => 'pending',
            ];
        }
        
        return null;
    }
    
    /**
     * Check if host verification is pending (10 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkHostVerification(Application $application): ?array
    {
        // If host name is provided but not verified
        if (!empty($application->host_name)) {
            // Check if host verification document exists
            $hostVerification = $application->documents()
                ->whereIn('document_type', ['host_verification', 'host_letter', 'invitation_letter'])
                ->where('verification_status', 'verified')
                ->exists();
            
            if (!$hostVerification) {
                return [
                    'rule_id' => 'financial.host_verification_pending',
                    'category' => self::CATEGORY_NAME,
                    'description' => config('risk_scoring.categories.financial.rules.host_verification_pending.description'),
                    'points' => config('risk_scoring.categories.financial.rules.host_verification_pending.points'),
                    'severity' => 'medium',
                    'field_checked' => 'host_verification',
                    'actual_value' => 'pending',
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Check if sponsor details are incomplete (10 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkSponsorDetails(Application $application): ?array
    {
        // Check if sponsor is mentioned in documents
        $hasSponsorDoc = $application->documents()
            ->where('document_type', 'sponsor_letter')
            ->exists();
        
        if ($hasSponsorDoc) {
            // If sponsor document exists, check if details are complete
            $sponsorComplete = !empty($application->host_name) &&
                             !empty($application->host_phone) &&
                             !empty($application->host_address);
            
            if (!$sponsorComplete) {
                return [
                    'rule_id' => 'financial.sponsor_incomplete',
                    'category' => self::CATEGORY_NAME,
                    'description' => config('risk_scoring.categories.financial.rules.sponsor_incomplete.description'),
                    'points' => config('risk_scoring.categories.financial.rules.sponsor_incomplete.points'),
                    'severity' => 'medium',
                    'field_checked' => 'sponsor_details',
                    'actual_value' => 'incomplete',
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Check if invitation letter is missing for business visa (15 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkBusinessInvitation(Application $application): ?array
    {
        // Check if this is a business visa
        $visaTypeName = strtolower($application->visaType->name ?? '');
        $purpose = strtolower($application->purpose_of_visit ?? '');
        
        $isBusiness = str_contains($visaTypeName, 'business') || 
                     str_contains($purpose, 'business');
        
        if ($isBusiness) {
            // Check for invitation letter
            $hasInvitation = $application->documents()
                ->whereIn('document_type', ['invitation_letter', 'business_letter', 'company_invitation'])
                ->exists();
            
            if (!$hasInvitation && empty($application->host_company_name)) {
                return [
                    'rule_id' => 'financial.no_business_invitation',
                    'category' => self::CATEGORY_NAME,
                    'description' => config('risk_scoring.categories.financial.rules.no_business_invitation.description'),
                    'points' => config('risk_scoring.categories.financial.rules.no_business_invitation.points'),
                    'severity' => 'medium',
                    'field_checked' => 'business_invitation',
                    'actual_value' => 'missing',
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
