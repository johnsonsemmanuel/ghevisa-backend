<?php

namespace App\Observers;

use App\Models\Application;
use App\Services\RiskScoringTriggerService;

class ApplicationObserver
{
    public function __construct(
        protected RiskScoringTriggerService $riskScoringTriggerService
    ) {}

    /**
     * Handle the Application "updated" event.
     */
    public function updated(Application $application): void
    {
        // Check if risk-relevant fields changed
        $changedFields = array_keys($application->getDirty());
        
        if ($this->riskScoringTriggerService->shouldRecalculateOnFieldChange($application, $changedFields)) {
            // Only recalculate if not already in review (to avoid duplicate calculations)
            if ($application->status !== 'under_review') {
                $this->riskScoringTriggerService->manualRescore(
                    $application,
                    'Application field(s) updated: ' . implode(', ', $changedFields)
                );
            }
        }
    }
}
