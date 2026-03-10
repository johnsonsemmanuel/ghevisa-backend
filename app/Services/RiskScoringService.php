<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\RiskAssessment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Visa Risk Scoring Service
 * 
 * Implements the comprehensive risk scoring algorithm with detailed rules
 * across multiple categories: Identity, Travel, Financial, Immigration, Documents.
 */
class RiskScoringService
{
    /**
     * Risk scoring rules with points
     */
    public array $rules = [
        // IDENTITY & PASSPORT (max 30pts)
        'passport_expires_soon' => 25,
        'passport_name_mismatch' => 20,
        'passport_ocr_unreadable' => 10,
        'passport_recently_issued' => 10,
        'nationality_flagged' => 15,

        // TRAVEL PATTERN (max 20pts)
        'duration_inconsistent' => 15,
        'no_return_ticket' => 10,
        'accommodation_unclear' => 10,
        'frequent_high_risk_travel' => 20,
        'purpose_inconsistent' => 15,

        // FINANCIAL / SPONSORSHIP (max 20pts)
        'funds_missing' => 20,
        'bank_statement_pending' => 10,
        'host_verification_pending' => 10,
        'sponsor_incomplete' => 10,
        'invitation_missing_business' => 15,

        // IMMIGRATION HISTORY (max 20pts)
        'prior_refusal' => 20,
        'prior_overstay' => 30,
        'prior_deportation' => 40,
        'watchlist_match' => 50,
        'immigration_inconsistent' => 25,

        // DOCUMENT QUALITY (max 10pts)
        'document_missing' => 15,
        'document_blurry' => 10,
        'document_partial' => 5,
        'document_invalid_format' => 5,
    ];

    /**
     * High-risk nationalities requiring additional review
     */
    protected array $highRiskNationalities = [
        'KP', // North Korea
        'IR', // Iran
        'SY', // Syria
        'CU', // Cuba
        'VE', // Venezuela
        'AF', // Afghanistan
        'IQ', // Iraq
        'LY', // Libya
        'SO', // Somalia
        'YE', // Yemen
    ];

    /**
     * Calculate comprehensive risk score for an application
     */
    public function calculateRisk(Application $application): array
    {
        $score = 0;
        $triggeredRules = [];
        $riskReasons = [];

        // Get decrypted data
        $firstName = $application->first_name_encrypted;
        $lastName = $application->last_name_encrypted;
        $passportNumber = $application->passport_number_encrypted;
        $nationality = $application->nationality_encrypted;
        $dateOfBirth = $application->date_of_birth;
        $intendedArrival = $application->intended_arrival;
        $durationDays = $application->duration_days;
        $purpose = $application->purpose_of_visit;

        // IDENTITY & PASSPORT checks
        $identityScore = $this->checkIdentityPassport(
            $application,
            $firstName,
            $lastName,
            $passportNumber,
            $nationality,
            $dateOfBirth,
            $intendedArrival
        );
        $score += $identityScore['score'];
        $triggeredRules = array_merge($triggeredRules, $identityScore['rules']);
        $riskReasons = array_merge($riskReasons, $identityScore['reasons']);

        // TRAVEL PATTERN checks
        $travelScore = $this->checkTravelPattern(
            $application,
            $intendedArrival,
            $durationDays,
            $purpose
        );
        $score += $travelScore['score'];
        $triggeredRules = array_merge($triggeredRules, $travelScore['rules']);
        $riskReasons = array_merge($riskReasons, $travelScore['reasons']);

        // FINANCIAL checks
        $financialScore = $this->checkFinancialSponsorship($application, $purpose);
        $score += $financialScore['score'];
        $triggeredRules = array_merge($triggeredRules, $financialScore['rules']);
        $riskReasons = array_merge($riskReasons, $financialScore['reasons']);

        // IMMIGRATION HISTORY checks
        $immigrationScore = $this->checkImmigrationHistory($application, $passportNumber, $nationality);
        $score += $immigrationScore['score'];
        $triggeredRules = array_merge($triggeredRules, $immigrationScore['rules']);
        $riskReasons = array_merge($riskReasons, $immigrationScore['reasons']);

        // DOCUMENT QUALITY checks
        $documentScore = $this->checkDocumentQuality($application);
        $score += $documentScore['score'];
        $triggeredRules = array_merge($triggeredRules, $documentScore['rules']);
        $riskReasons = array_merge($riskReasons, $documentScore['reasons']);

        // Cap at 100
        $finalScore = min(100, $score);

        // Determine risk level
        $riskLevel = $this->determineRiskLevel($finalScore);

        // Keep top 3-5 reasons
        $topReasons = array_slice($riskReasons, 0, 5);

        Log::info("Risk score calculated for {$application->reference_number}: {$finalScore} ({$riskLevel})");

        return [
            'risk_score' => $finalScore,
            'risk_level' => $riskLevel,
            'risk_reasons' => $topReasons,
            'triggered_rules' => $triggeredRules,
        ];
    }

    /**
     * Check identity and passport-related risks
     */
    protected function checkIdentityPassport(
        Application $application,
        ?string $firstName,
        ?string $lastName,
        ?string $passportNumber,
        ?string $nationality,
        ?string $dateOfBirth,
        ?string $intendedArrival
    ): array {
        $score = 0;
        $rules = [];
        $reasons = [];

        // Passport expires < 6 months after arrival
        if ($intendedArrival && $passportNumber) {
            try {
                $arrivalDate = new Carbon($intendedArrival);
                $expiryDate = $this->getPassportExpiry($application);
                
                if ($expiryDate && $expiryDate->diffInDays($arrivalDate) < 180) {
                    $score += $this->rules['passport_expires_soon'];
                    $rules[] = 'passport_expires_soon';
                    $reasons[] = "Passport expires less than 6 months after intended arrival";
                }
            } catch (\Exception $e) {
                // Date parsing errors - skip this check
            }
        }

        // Passport name mismatch with application
        if ($firstName && $lastName) {
            $passportName = $this->getPassportName($application);
            if ($passportName && !$this->namesMatch($firstName, $lastName, $passportName)) {
                $score += $this->rules['passport_name_mismatch'];
                $rules[] = 'passport_name_mismatch';
                $reasons[] = "Passport name does not match application name";
            }
        }

        // Passport OCR unreadable (check document verification status)
        $passportDoc = $application->documents()
            ->where('document_type', 'passport')
            ->first();
        
        if ($passportDoc && $passportDoc->verification_status === 'rejected') {
            $score += $this->rules['passport_ocr_unreadable'];
            $rules[] = 'passport_ocr_unreadable';
            $reasons[] = "Passport OCR verification failed - document unreadable";
        }

        // Passport issued < 6 months ago
        $issueDate = $this->getPassportIssueDate($application);
        if ($issueDate && $issueDate->diffInDays(now()) < 180) {
            $score += $this->rules['passport_recently_issued'];
            $rules[] = 'passport_recently_issued';
            $reasons[] = "Passport issued less than 6 months ago";
        }

        // Nationality flagged for additional review
        if ($nationality && in_array(strtoupper($nationality), $this->highRiskNationalities)) {
            $score += $this->rules['nationality_flagged'];
            $rules[] = 'nationality_flagged';
            $reasons[] = "Nationality requires additional security review";
        }

        return ['score' => $score, 'rules' => $rules, 'reasons' => $reasons];
    }

    /**
     * Check travel pattern risks
     */
    protected function checkTravelPattern(
        Application $application,
        ?string $intendedArrival,
        ?int $durationDays,
        ?string $purpose
    ): array {
        $score = 0;
        $rules = [];
        $reasons = [];

        // Stay duration inconsistent with visa type
        if ($durationDays && $application->visaType) {
            $maxDays = $application->visaType->max_duration_days;
            if ($durationDays > $maxDays) {
                $score += $this->rules['duration_inconsistent'];
                $rules[] = 'duration_inconsistent';
                $reasons[] = "Requested stay duration exceeds visa type limit";
            }
        }

        // No return ticket uploaded
        $returnTicket = $application->documents()
            ->where('document_type', 'return_ticket')
            ->first();
        
        if (!$returnTicket) {
            $score += $this->rules['no_return_ticket'];
            $rules[] = 'no_return_ticket';
            $reasons[] = "No return ticket uploaded";
        }

        // Accommodation missing or unclear
        if (!$application->address_in_ghana || strlen($application->address_in_ghana) < 10) {
            $score += $this->rules['accommodation_unclear'];
            $rules[] = 'accommodation_unclear';
            $reasons[] = "Accommodation details missing or insufficient";
        }

        // Frequent recent travel to high-risk regions
        if ($this->hasFrequentHighRiskTravel($application)) {
            $score += $this->rules['frequent_high_risk_travel'];
            $rules[] = 'frequent_high_risk_travel';
            $reasons[] = "Frequent recent travel to high-risk regions detected";
        }

        // Travel purpose inconsistent with documents
        if ($purpose && $application->documents) {
            $requiredDocs = $application->visaType->required_documents ?? [];
            $hasRequiredDocs = true;
            
            if ($purpose === 'Business' && !in_array('invitation_letter', $requiredDocs)) {
                $invitationLetter = $application->documents()
                    ->where('document_type', 'invitation_letter')
                    ->first();
                if (!$invitationLetter) {
                    $score += $this->rules['purpose_inconsistent'];
                    $rules[] = 'purpose_inconsistent';
                    $reasons[] = "Business visa application lacks invitation letter";
                }
            }
        }

        return ['score' => $score, 'rules' => $rules, 'reasons' => $reasons];
    }

    /**
     * Check financial and sponsorship risks
     */
    protected function checkFinancialSponsorship(Application $application, ?string $purpose): array
    {
        $score = 0;
        $rules = [];
        $reasons = [];

        // Proof of funds missing
        $proofOfFunds = $application->documents()
            ->where('document_type', 'bank_statement')
            ->first();
        
        if (!$proofOfFunds) {
            $score += $this->rules['funds_missing'];
            $rules[] = 'funds_missing';
            $reasons[] = "No proof of funds provided";
        }

        // Bank statement under verification
        if ($proofOfFunds && $proofOfFunds->verification_status === 'pending') {
            $score += $this->rules['bank_statement_pending'];
            $rules[] = 'bank_statement_pending';
            $reasons[] = "Bank statement verification pending";
        }

        // Host verification pending
        $hostLetter = $application->documents()
            ->where('document_type', 'host_letter')
            ->first();
        
        if ($hostLetter && $hostLetter->verification_status === 'pending') {
            $score += $this->rules['host_verification_pending'];
            $rules[] = 'host_verification_pending';
            $reasons[] = "Host accommodation verification pending";
        }

        // Sponsor details incomplete
        $sponsorForm = $application->documents()
            ->where('document_type', 'sponsor_form')
            ->first();
        
        if ($sponsorForm && $this->isSponsorIncomplete($sponsorForm)) {
            $score += $this->rules['sponsor_incomplete'];
            $rules[] = 'sponsor_incomplete';
            $reasons[] = "Sponsor details incomplete";
        }

        // Invitation letter missing on business visa
        if ($purpose === 'Business') {
            $invitationLetter = $application->documents()
                ->where('document_type', 'invitation_letter')
                ->first();
            
            if (!$invitationLetter) {
                $score += $this->rules['invitation_missing_business'];
                $rules[] = 'invitation_missing_business';
                $reasons[] = "Business visa requires invitation letter";
            }
        }

        return ['score' => $score, 'rules' => $rules, 'reasons' => $reasons];
    }

    /**
     * Check immigration history risks
     */
    protected function checkImmigrationHistory(
        Application $application,
        ?string $passportNumber,
        ?string $nationality
    ): array {
        $score = 0;
        $rules = [];
        $reasons = [];

        // Prior visa refusal declared
        if ($this->hasPriorRefusal($application)) {
            $score += $this->rules['prior_refusal'];
            $rules[] = 'prior_refusal';
            $reasons[] = "Prior visa refusal detected";
        }

        // Prior Ghana overstay
        if ($this->hasPriorOverstay($passportNumber)) {
            $score += $this->rules['prior_overstay'];
            $rules[] = 'prior_overstay';
            $reasons[] = "Prior overstay in Ghana detected";
        }

        // Prior deportation
        if ($this->hasPriorDeportation($passportNumber)) {
            $score += $this->rules['prior_deportation'];
            $rules[] = 'prior_deportation';
            $reasons[] = "Prior deportation record detected";
        }

        // Sanctions / watchlist match
        if ($this->checkWatchlist($application)) {
            $score += $this->rules['watchlist_match'];
            $rules[] = 'watchlist_match';
            $reasons[] = "Match found on security watchlist";
        }

        // Inconsistent immigration declarations
        if ($this->hasInconsistentDeclarations($application)) {
            $score += $this->rules['immigration_inconsistent'];
            $rules[] = 'immigration_inconsistent';
            $reasons[] = "Inconsistent information in immigration history";
        }

        return ['score' => $score, 'rules' => $rules, 'reasons' => $reasons];
    }

    /**
     * Check document quality risks
     */
    protected function checkDocumentQuality(Application $application): array
    {
        $score = 0;
        $rules = [];
        $reasons = [];

        $requiredDocs = $application->visaType->required_documents ?? [];
        
        foreach ($requiredDocs as $docType) {
            $doc = $application->documents()
                ->where('document_type', $docType)
                ->first();

            if (!$doc) {
                $score += $this->rules['document_missing'];
                $rules[] = 'document_missing';
                $reasons[] = "Required document missing: {$docType}";
                continue;
            }

            // Check document quality
            if ($doc->verification_status === 'rejected') {
                if (str_contains($doc->rejection_reason ?? '', 'blurry') || str_contains($doc->rejection_reason ?? '', 'unreadable')) {
                    $score += $this->rules['document_blurry'];
                    $rules[] = 'document_blurry';
                    $reasons[] = "Document blurry or unreadable: {$docType}";
                }
            }

            // Check for partial pages
            if (str_contains($doc->rejection_reason ?? '', 'partial') || str_contains($doc->rejection_reason ?? '', 'incomplete')) {
                $score += $this->rules['document_partial'];
                $rules[] = 'document_partial';
                $reasons[] = "Document contains partial pages: {$docType}";
            }

            // Check file format
            if (!in_array($doc->file_type, ['pdf', 'jpg', 'jpeg', 'png'])) {
                $score += $this->rules['document_invalid_format'];
                $rules[] = 'document_invalid_format';
                $reasons[] = "Invalid file format for document: {$docType}";
            }
        }

        return ['score' => $score, 'rules' => $rules, 'reasons' => $reasons];
    }

    /**
     * Determine risk level from score
     */
    protected function determineRiskLevel(int $score): string
    {
        if ($score >= 75) {
            return 'critical';
        } elseif ($score >= 50) {
            return 'high';
        } elseif ($score >= 25) {
            return 'medium';
        }
        return 'low';
    }

    // Helper methods (simplified implementations)
    protected function getPassportExpiry(Application $application): ?Carbon
    {
        // Implementation would extract from passport OCR or document data
        return null;
    }

    protected function getPassportName(Application $application): ?string
    {
        // Implementation would extract from passport OCR
        return null;
    }

    protected function namesMatch(string $firstName, string $lastName, string $passportName): bool
    {
        // Simplified name matching logic
        $passportParts = explode(' ', strtoupper($passportName));
        $appFirst = strtoupper($firstName);
        $appLast = strtoupper($lastName);
        
        return in_array($appFirst, $passportParts) && in_array($appLast, $passportParts);
    }

    protected function getPassportIssueDate(Application $application): ?Carbon
    {
        // Implementation would extract from passport
        return null;
    }

    protected function hasFrequentHighRiskTravel(Application $application): bool
    {
        // Implementation would check travel history
        return false;
    }

    protected function isSponsorIncomplete(ApplicationDocument $sponsorForm): bool
    {
        // Implementation would check sponsor form fields
        return false;
    }

    protected function hasPriorRefusal(Application $application): bool
    {
        // Implementation would check previous applications
        return false;
    }

    protected function hasPriorOverstay(?string $passportNumber): bool
    {
        // Implementation would check overstay records
        return false;
    }

    protected function hasPriorDeportation(?string $passportNumber): bool
    {
        // Implementation would check deportation records
        return false;
    }

    protected function checkWatchlist(Application $application): bool
    {
        // Implementation would check against watchlist
        return false;
    }

    protected function hasInconsistentDeclarations(Application $application): bool
    {
        // Implementation would check for inconsistencies
        return false;
    }
}
