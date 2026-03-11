<?php

namespace App\Observers;

use App\Models\EtaApplication;
use App\Services\TaidService;

class EtaApplicationObserver
{
    public function __construct(
        protected TaidService $taidService
    ) {}

    /**
     * Handle the EtaApplication "creating" event.
     */
    public function creating(EtaApplication $eta): void
    {
        // Assign TAID if not already set
        if (!$eta->taid) {
            $eta->taid = $this->taidService->generate();
        }
    }
}
