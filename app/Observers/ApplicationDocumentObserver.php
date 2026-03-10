<?php

namespace App\Observers;

use App\Models\ApplicationDocument;
use App\Services\RiskScoringTriggerService;

class ApplicationDocumentObserver
{
    public function __construct(
        protected RiskScoringTriggerService $riskScoringTriggerService
    ) {}

    /**
     * Handle the ApplicationDocument "updated" event.
     */
    public function updated(ApplicationDocument $document): void
    {
        // Trigger risk scoring when document verification status changes or file is replaced
        if ($document->wasChanged(['verification_status', 'stored_path'])) {
            $this->riskScoringTriggerService->onDocumentUpdate($document);
        }
    }

    /**
     * Handle the ApplicationDocument "created" event.
     */
    public function created(ApplicationDocument $document): void
    {
        // Trigger risk scoring when new document is uploaded
        $this->riskScoringTriggerService->onDocumentUpdate($document);
    }
}
