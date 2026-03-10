<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\RiskAssessment;
use Illuminate\Support\Facades\Log;

/**
 * Risk Scoring Trigger Service
 * 
 * Manages when and how risk scores are calculated and saved
 */
class RiskScoringTriggerService
{
    public function __construct(
        protected RiskScoringService $riskScoringService
    ) {}

    /**
     * Trigger risk scoring when application moves to IN_REVIEW
     */
    public function onStatusChangeToInReview(Application $application): void
    {
        if ($application->status === 'under_review') {
            $this->calculateAndSaveRiskScore($application, 'status_change_to_in_review');
        }
    }

    /**
     * Trigger risk scoring when a document is updated or re-uploaded
     */
    public function onDocumentUpdate(ApplicationDocument $document): void
    {
        // Only trigger if document verification status changes or file is replaced
        if ($document->wasChanged(['verification_status', 'stored_path'])) {
            $this->calculateAndSaveRiskScore(
                $document->application,
                'document_update',
                ['document_type' => $document->document_type]
            );
        }
    }

    /**
     * Manual re-score request by an officer
     */
    public function manualRescore(Application $application, string $reason = null): array
    {
        return $this->calculateAndSaveRiskScore($application, 'manual_request', [
            'reason' => $reason,
            'requested_by' => auth()->user()?->id,
            'requested_at' => now()->toISOString(),
        ]);
    }

    /**
     * Calculate and save risk score to application
     */
    protected function calculateAndSaveRiskScore(Application $application, string $trigger, array $context = []): array
    {
        try {
            // Calculate risk score
            $result = $this->riskScoringService->calculateRisk($application);

            // Update or create risk assessment
            $assessment = RiskAssessment::updateOrCreate(
                ['application_id' => $application->id],
                [
                    'risk_score' => $result['risk_score'],
                    'risk_level' => $result['risk_level'],
                    'risk_reasons' => $result['risk_reasons'],
                    'factors' => array_merge($result['triggered_rules'] ?? [], [
                        'trigger' => $trigger,
                        'trigger_context' => $context,
                        'calculated_at' => now()->toISOString(),
                    ]),
                    'status' => $result['risk_level'] === 'critical' ? 'manual_review' : 'completed',
                    'assessed_at' => now(),
                    'risk_last_updated' => now(),
                ]
            );

            // Update application with risk info (for quick access)
            // Note: We don't store risk fields directly on applications anymore
            // They are accessed via the riskAssessment relationship

            Log::info("Risk score calculated and saved", [
                'application_id' => $application->id,
                'reference_number' => $application->reference_number,
                'trigger' => $trigger,
                'risk_score' => $result['risk_score'],
                'risk_level' => $result['risk_level'],
                'assessment_id' => $assessment->id,
            ]);

            return [
                'success' => true,
                'risk_score' => $result['risk_score'],
                'risk_level' => $result['risk_level'],
                'risk_reasons' => $result['risk_reasons'],
                'assessment_id' => $assessment->id,
                'trigger' => $trigger,
            ];

        } catch (\Exception $e) {
            Log::error("Failed to calculate risk score", [
                'application_id' => $application->id,
                'reference_number' => $application->reference_number,
                'trigger' => $trigger,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'trigger' => $trigger,
            ];
        }
    }

    /**
     * Check if risk score should be recalculated based on field changes
     */
    public function shouldRecalculateOnFieldChange(Application $application, array $changedFields): bool
    {
        $riskRelevantFields = [
            'first_name_encrypted',
            'last_name_encrypted',
            'passport_number_encrypted',
            'nationality_encrypted',
            'date_of_birth',
            'intended_arrival',
            'duration_days',
            'address_in_ghana',
            'purpose_of_visit',
            'visa_type_id',
        ];

        // Check if any risk-relevant fields changed
        foreach ($riskRelevantFields as $field) {
            if (in_array($field, $changedFields)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Batch recalculate risk scores for applications
     */
    public function batchRecalculate(array $applicationIds, string $reason = null): array
    {
        $results = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'details' => [],
        ];

        foreach ($applicationIds as $applicationId) {
            $application = Application::find($applicationId);
            if (!$application) {
                $results['failed']++;
                $results['details'][] = [
                    'application_id' => $applicationId,
                    'error' => 'Application not found',
                ];
                continue;
            }

            $result = $this->manualRescore($application, $reason);
            $results['processed']++;

            if ($result['success']) {
                $results['success']++;
                $results['details'][] = [
                    'application_id' => $applicationId,
                    'risk_score' => $result['risk_score'],
                    'risk_level' => $result['risk_level'],
                ];
            } else {
                $results['failed']++;
                $results['details'][] = [
                    'application_id' => $applicationId,
                    'error' => $result['error'],
                ];
            }
        }

        Log::info("Batch risk scoring completed", $results);

        return $results;
    }
}
