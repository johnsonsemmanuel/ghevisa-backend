<?php

namespace App\Observers;

use App\Models\Application;
use App\Services\RiskScoringTriggerService;
use App\Services\TaidService;

class ApplicationObserver
{
    public function __construct(
        protected RiskScoringTriggerService $riskScoringTriggerService,
        protected TaidService $taidService
    ) {}

    /**
     * Handle the Application "creating" event.
     */
    public function creating(Application $application): void
    {
        // Assign TAID if not already set
        if (!$application->taid) {
            $application->taid = $this->taidService->generate();
        }
    }

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
