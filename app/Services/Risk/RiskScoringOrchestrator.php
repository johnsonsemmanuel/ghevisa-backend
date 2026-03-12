<?php

namespace App\Services\Risk;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\RiskAssessment;
use App\Services\Risk\RuleBasedRiskEngine;
use Illuminate\Support\Facades\Log;

/**
 * Risk Scoring Orchestrator
 * 
 * Manages when and how risk scores are calculated and saved.
 * Triggers risk assessments based on application lifecycle events.
 */
class RiskScoringOrchestrator
{
    public function __construct(
        protected RuleBasedRiskEngine $riskEngine
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
            // Calculate risk score using rule-based engine
            $result = $this->riskEngine->assessRisk($application);

            Log::info("Risk score calculated and saved", [
                'application_id' => $application->id,
                'reference_number' => $application->reference_number,
                'trigger' => $trigger,
                'risk_score' => $result['score'],
                'risk_level' => $result['level'],
                'assessment_id' => $result['assessment_id'],
            ]);

            return [
                'success' => true,
                'risk_score' => $result['score'],
                'risk_level' => $result['level'],
                'risk_reasons' => $result['risk_reasons'],
                'assessment_id' => $result['assessment_id'],
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

    /**
     * Mark an application as cleared after manual screening.
     */
    public function markCleared(Application $application, string $notes = null): Application
    {
        $application->update([
            'risk_screening_status' => 'cleared',
            'risk_screening_notes' => $notes ?? 'Manual screening completed. No flags.',
        ]);

        Log::info("Application {$application->reference_number} cleared risk screening");

        return $application;
    }

    /**
     * Mark an application as flagged during screening.
     */
    public function markFlagged(Application $application, string $reason): Application
    {
        $application->update([
            'risk_screening_status' => 'flagged',
            'risk_screening_notes' => $reason,
        ]);

        Log::warning("Application {$application->reference_number} flagged: {$reason}");

        return $application;
    }

    /**
     * Get applications pending risk screening.
     */
    public function getPendingScreening(int $limit = 50)
    {
        return Application::where('risk_screening_status', 'pending')
            ->whereIn('status', ['under_review', 'escalated'])
            ->orderBy('submitted_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get applications flagged for manual review.
     */
    public function getFlaggedApplications()
    {
        return Application::where('risk_screening_status', 'flagged')
            ->whereNotIn('status', ['approved', 'denied', 'cancelled'])
            ->orderBy('submitted_at', 'asc')
            ->get();
    }

    /**
     * Check if an application can be approved based on screening status.
     */
    public function canApprove(Application $application): bool
    {
        return !in_array($application->risk_screening_status, ['flagged']);
    }

    /**
     * Get screening statistics for admin dashboard.
     */
    public function getStatistics(): array
    {
        return [
            'pending' => Application::where('risk_screening_status', 'pending')
                ->whereNotIn('status', ['approved', 'denied', 'cancelled'])->count(),
            'cleared' => Application::where('risk_screening_status', 'cleared')->count(),
            'flagged' => Application::where('risk_screening_status', 'flagged')
                ->whereNotIn('status', ['denied'])->count(),
            'in_progress' => Application::where('risk_screening_status', 'in_progress')->count(),
        ];
    }

    /**
     * Batch process pending applications for risk screening.
     */
    public function batchProcessPending(int $limit = 50): array
    {
        $applications = $this->getPendingScreening($limit);
        $results = ['processed' => 0, 'flagged' => 0, 'cleared' => 0, 'errors' => 0];

        foreach ($applications as $application) {
            try {
                $result = $this->riskEngine->assessRisk($application);
                $results['processed']++;
                
                if ($result['level'] === 'critical' || ($application->watchlist_flagged ?? false)) {
                    $results['flagged']++;
                } else {
                    $results['cleared']++;
                }
            } catch (\Exception $e) {
                Log::error("Risk assessment failed for {$application->reference_number}: {$e->getMessage()}");
                $results['errors']++;
            }
        }

        return $results;
    }
}
