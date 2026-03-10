<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\RiskAssessment;
use App\Services\RiskScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RiskScoringController extends Controller
{
    public function __construct(
        protected RiskScoringService $riskScoringService
    ) {}

    /**
     * Calculate risk score for a specific application
     */
    public function calculate(Application $application): JsonResponse
    {
        $result = $this->riskScoringService->calculateRisk($application);

        // Update or create risk assessment
        $assessment = RiskAssessment::updateOrCreate(
            ['application_id' => $application->id],
            [
                'risk_score' => $result['risk_score'],
                'risk_level' => $result['risk_level'],
                'risk_reasons' => $result['risk_reasons'],
                'factors' => [
                    'triggered_rules' => $result['triggered_rules'],
                    'total_rules_checked' => count($this->riskScoringService->rules),
                ],
                'status' => $result['risk_level'] === 'critical' ? 'manual_review' : 'completed',
                'assessed_at' => now(),
                'risk_last_updated' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'application_id' => $application->id,
                'reference_number' => $application->reference_number,
                'risk_score' => $result['risk_score'],
                'risk_level' => $result['risk_level'],
                'risk_reasons' => $result['risk_reasons'],
                'triggered_rules_count' => count($result['triggered_rules']),
                'assessment_id' => $assessment->id,
            ]
        ]);
    }

    /**
     * Batch calculate risk scores for multiple applications
     */
    public function batchCalculate(Request $request): JsonResponse
    {
        $request->validate([
            'application_ids' => 'required|array',
            'application_ids.*' => 'integer|exists:applications,id',
        ]);

        $results = [];
        $processed = 0;
        $errors = 0;

        foreach ($request->application_ids as $applicationId) {
            try {
                $application = Application::findOrFail($applicationId);
                $result = $this->riskScoringService->calculateRisk($application);

                // Update risk assessment
                RiskAssessment::updateOrCreate(
                    ['application_id' => $application->id],
                    [
                        'risk_score' => $result['risk_score'],
                        'risk_level' => $result['risk_level'],
                        'risk_reasons' => $result['risk_reasons'],
                        'factors' => [
                            'triggered_rules' => $result['triggered_rules'],
                            'total_rules_checked' => count($this->riskScoringService->rules),
                        ],
                        'status' => $result['risk_level'] === 'critical' ? 'manual_review' : 'completed',
                        'assessed_at' => now(),
                        'risk_last_updated' => now(),
                    ]
                );

                $results[] = [
                    'application_id' => $application->id,
                    'reference_number' => $application->reference_number,
                    'risk_score' => $result['risk_score'],
                    'risk_level' => $result['risk_level'],
                    'success' => true,
                ];
                $processed++;
            } catch (\Exception $e) {
                $results[] = [
                    'application_id' => $applicationId,
                    'error' => $e->getMessage(),
                    'success' => false,
                ];
                $errors++;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'processed' => $processed,
                'errors' => $errors,
                'results' => $results,
            ]
        ]);
    }

    /**
     * Get risk scoring statistics
     */
    public function statistics(): JsonResponse
    {
        $stats = RiskAssessment::selectRaw('
            COUNT(*) as total_assessments,
            COUNT(CASE WHEN risk_level = "low" THEN 1 END) as low_risk,
            COUNT(CASE WHEN risk_level = "medium" THEN 1 END) as medium_risk,
            COUNT(CASE WHEN risk_level = "high" THEN 1 END) as high_risk,
            COUNT(CASE WHEN risk_level = "critical" THEN 1 END) as critical_risk,
            AVG(risk_score) as average_score,
            MAX(risk_score) as highest_score,
            MIN(risk_score) as lowest_score
        ')->first();

        $recent = RiskAssessment::with('application')
            ->orderBy('risk_last_updated', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($assessment) {
                return [
                    'reference_number' => $assessment->application->reference_number,
                    'risk_score' => $assessment->risk_score,
                    'risk_level' => $assessment->risk_level,
                    'updated_at' => $assessment->risk_last_updated,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'statistics' => $stats,
                'recent_assessments' => $recent,
            ]
        ]);
    }

    /**
     * Get risk assessment details for an application
     */
    public function show(Application $application): JsonResponse
    {
        $assessment = $application->riskAssessment;

        if (!$assessment) {
            return response()->json([
                'success' => false,
                'message' => 'No risk assessment found for this application'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'application_id' => $application->id,
                'reference_number' => $application->reference_number,
                'risk_score' => $assessment->risk_score,
                'risk_level' => $assessment->risk_level,
                'risk_reasons' => $assessment->risk_reasons,
                'factors' => $assessment->factors,
                'status' => $assessment->status,
                'assessed_at' => $assessment->assessed_at,
                'risk_last_updated' => $assessment->risk_last_updated,
            ]
        ]);
    }

    /**
     * Manual re-score request by an officer
     */
    public function manualRescore(Request $request, Application $application): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $result = $this->riskScoringService->calculateRisk($application);

        // Update risk assessment
        $assessment = RiskAssessment::updateOrCreate(
            ['application_id' => $application->id],
            [
                'risk_score' => $result['risk_score'],
                'risk_level' => $result['risk_level'],
                'risk_reasons' => $result['risk_reasons'],
                'factors' => [
                    'triggered_rules' => $result['triggered_rules'],
                    'total_rules_checked' => count($this->riskScoringService->rules),
                    'trigger' => 'manual_request',
                    'trigger_context' => [
                        'reason' => $request->reason,
                        'requested_by' => auth()->user()?->id,
                        'requested_at' => now()->toISOString(),
                    ],
                ],
                'status' => $result['risk_level'] === 'critical' ? 'manual_review' : 'completed',
                'assessed_by_id' => auth()->id(),
                'assessed_at' => now(),
                'risk_last_updated' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'application_id' => $application->id,
                'reference_number' => $application->reference_number,
                'risk_score' => $result['risk_score'],
                'risk_level' => $result['risk_level'],
                'risk_reasons' => $result['risk_reasons'],
                'assessment_id' => $assessment->id,
                'trigger' => 'manual_request',
            ]
        ]);
    }

    /**
     * Add officer notes to risk assessment
     */
    public function addNotes(Request $request, Application $application): JsonResponse
    {
        $request->validate([
            'notes' => 'required|string|max:2000',
        ]);

        $assessment = $application->riskAssessment;
        
        if (!$assessment) {
            return response()->json([
                'success' => false,
                'message' => 'No risk assessment found for this application'
            ], 404);
        }

        $assessment->update([
            'notes' => $request->notes,
            'assessed_by_id' => auth()->id(),
            'risk_last_updated' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'notes' => $assessment->notes,
                'risk_last_updated' => $assessment->risk_last_updated,
            ]
        ]);
    }

    /**
     * Override risk assessment flag
     */
    public function override(Request $request, Application $application): JsonResponse
    {
        $request->validate([
            'override_flag' => 'required|boolean',
            'override_note' => 'required_if:override_flag,true|string|max:1000',
        ]);

        $assessment = $application->riskAssessment;
        
        if (!$assessment) {
            return response()->json([
                'success' => false,
                'message' => 'No risk assessment found for this application'
            ], 404);
        }

        $updateData = [
            'override_flag' => $request->override_flag,
            'risk_last_updated' => now(),
        ];

        if ($request->override_flag) {
            $updateData['override_note'] = $request->override_note;
            $updateData['override_by_id'] = auth()->id();
            $updateData['override_timestamp'] = now();
        } else {
            $updateData['override_note'] = null;
            $updateData['override_by_id'] = null;
            $updateData['override_timestamp'] = null;
        }

        $assessment->update($updateData);

        return response()->json([
            'success' => true,
            'data' => [
                'override_flag' => $assessment->override_flag,
                'override_note' => $assessment->override_note,
                'override_by' => $assessment->overrideBy?->only(['first_name', 'last_name']),
                'override_timestamp' => $assessment->override_timestamp,
            ]
        ]);
    }

    /**
     * Update risk assessment status (for manual review)
     */
    public function updateStatus(Request $request, Application $application): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:pending,in_progress,completed,manual_review',
            'notes' => 'nullable|string',
        ]);

        $assessment = $application->riskAssessment;
        
        if (!$assessment) {
            return response()->json([
                'success' => false,
                'message' => 'No risk assessment found for this application'
            ], 404);
        }

        $assessment->update([
            'status' => $request->status,
            'notes' => $request->notes,
            'assessed_by_id' => auth()->id(),
            'risk_last_updated' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'assessment_id' => $assessment->id,
                'status' => $assessment->status,
                'risk_last_updated' => $assessment->risk_last_updated,
            ]
        ]);
    }
}
