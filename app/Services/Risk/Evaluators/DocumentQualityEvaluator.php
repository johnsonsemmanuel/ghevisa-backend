<?php

namespace App\Services\Risk\Evaluators;

use App\Models\Application;
use Illuminate\Support\Facades\Log;

/**
 * Evaluates document completeness and quality.
 * 
 * Checks for:
 * - Missing required documents
 * - Unreadable documents
 * - Partial page uploads
 * - Invalid file formats
 * 
 * Maximum contribution: 10 points
 */
class DocumentQualityEvaluator implements RiskEvaluatorInterface
{
    protected const MAX_POINTS = 10;
    protected const CATEGORY_NAME = 'Document Quality & Completeness';
    
    /**
     * Evaluate document quality and completeness risks.
     * 
     * @param Application $application
     * @return array Category evaluation results
     */
    public function evaluate(Application $application): array
    {
        $triggeredRules = [];
        
        // Check missing documents
        if ($rule = $this->checkMissingDocuments($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Check document readability
        if ($rule = $this->checkDocumentReadability($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Check partial pages
        if ($rule = $this->checkPartialPages($application)) {
            $triggeredRules[] = $rule;
        }
        
        // Check file formats
        if ($rule = $this->checkFileFormats($application)) {
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
     * Check for missing required documents (15 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkMissingDocuments(Application $application): ?array
    {
        // Get required documents for visa type
        $visaTypeName = strtolower($application->visaType->name ?? '');
        
        // Find matching visa type in config
        $requiredDocs = [];
        $configDocs = config('risk_scoring.required_documents_by_visa_type', []);
        
        foreach ($configDocs as $type => $docs) {
            if (str_contains($visaTypeName, $type)) {
                $requiredDocs = $docs;
                break;
            }
        }
        
        if (empty($requiredDocs)) {
            // Default required documents if visa type not found
            $requiredDocs = ['passport', 'photo'];
        }
        
        // Check which required documents are missing
        $uploadedDocTypes = $application->documents()
            ->pluck('document_type')
            ->map(fn($type) => strtolower($type))
            ->toArray();
        
        $missingDocs = [];
        foreach ($requiredDocs as $requiredDoc) {
            $found = false;
            foreach ($uploadedDocTypes as $uploadedType) {
                if (str_contains($uploadedType, $requiredDoc) || 
                    str_contains($requiredDoc, $uploadedType)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missingDocs[] = $requiredDoc;
            }
        }
        
        if (!empty($missingDocs)) {
            return [
                'rule_id' => 'document.missing_required',
                'category' => self::CATEGORY_NAME,
                'description' => config('risk_scoring.categories.document.rules.missing_required.description'),
                'points' => config('risk_scoring.categories.document.rules.missing_required.points'),
                'severity' => 'medium',
                'field_checked' => 'required_documents',
                'actual_value' => 'Missing: ' . implode(', ', $missingDocs),
            ];
        }
        
        return null;
    }
    
    /**
     * Check for blurry or unreadable documents (10 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkDocumentReadability(Application $application): ?array
    {
        // Check for documents with verification status rejected or unreadable
        $unreadableDocs = $application->documents()
            ->whereIn('verification_status', ['rejected', 'unreadable', 'failed'])
            ->get();
        
        if ($unreadableDocs->isNotEmpty()) {
            $docTypes = $unreadableDocs->pluck('document_type')->unique()->toArray();
            
            return [
                'rule_id' => 'document.unreadable',
                'category' => self::CATEGORY_NAME,
                'description' => config('risk_scoring.categories.document.rules.unreadable.description'),
                'points' => config('risk_scoring.categories.document.rules.unreadable.points'),
                'severity' => 'medium',
                'field_checked' => 'document_readability',
                'actual_value' => 'Unreadable: ' . implode(', ', $docTypes),
            ];
        }
        
        return null;
    }
    
    /**
     * Check for partial page uploads (5 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkPartialPages(Application $application): ?array
    {
        // Check for documents flagged as incomplete or partial
        $partialDocs = $application->documents()
            ->where(function ($query) {
                $query->where('verification_status', 'incomplete')
                      ->orWhere('verification_status', 'partial')
                      ->orWhereRaw('LOWER(rejection_reason) LIKE ?', ['%partial%'])
                      ->orWhereRaw('LOWER(rejection_reason) LIKE ?', ['%incomplete%']);
            })
            ->get();
        
        if ($partialDocs->isNotEmpty()) {
            $docTypes = $partialDocs->pluck('document_type')->unique()->toArray();
            
            return [
                'rule_id' => 'document.partial_pages',
                'category' => self::CATEGORY_NAME,
                'description' => config('risk_scoring.categories.document.rules.partial_pages.description'),
                'points' => config('risk_scoring.categories.document.rules.partial_pages.points'),
                'severity' => 'low',
                'field_checked' => 'document_completeness',
                'actual_value' => 'Partial: ' . implode(', ', $docTypes),
            ];
        }
        
        return null;
    }
    
    /**
     * Check for invalid file formats (5 points).
     * 
     * @param Application $application
     * @return array|null Rule data if triggered, null otherwise
     */
    protected function checkFileFormats(Application $application): ?array
    {
        // Define accepted file formats
        $acceptedFormats = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx'];
        
        // Check for documents with invalid formats
        $invalidDocs = $application->documents()
            ->get()
            ->filter(function ($doc) use ($acceptedFormats) {
                if (empty($doc->stored_path)) {
                    return false;
                }
                
                $extension = strtolower(pathinfo($doc->stored_path, PATHINFO_EXTENSION));
                return !in_array($extension, $acceptedFormats);
            });
        
        if ($invalidDocs->isNotEmpty()) {
            $docTypes = $invalidDocs->pluck('document_type')->unique()->toArray();
            
            return [
                'rule_id' => 'document.invalid_format',
                'category' => self::CATEGORY_NAME,
                'description' => config('risk_scoring.categories.document.rules.invalid_format.description'),
                'points' => config('risk_scoring.categories.document.rules.invalid_format.points'),
                'severity' => 'low',
                'field_checked' => 'file_format',
                'actual_value' => 'Invalid format: ' . implode(', ', $docTypes),
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
