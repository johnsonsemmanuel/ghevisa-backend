<?php

namespace App\Console\Commands;

use App\Services\AlertingService;
use Illuminate\Console\Command;

class ProcessAlerts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alerts:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process alert rules and send notifications';

    /**
     * Execute the console command.
     */
    public function handle(AlertingService $alertingService): int
    {
        $this->info('Processing alert rules...');
        
        try {
            $alertingService->processAlerts();
            $this->info('Alert processing completed successfully.');
            return 0;
        } catch (\Exception $e) {
            $this->error('Alert processing failed: ' . $e->getMessage());
            return 1;
        }
    }
}
