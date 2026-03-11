<?php

namespace App\Console\Commands;

use App\Services\TaidService;
use Illuminate\Console\Command;

class BackfillTaids extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'taid:backfill
                            {--dry-run : Run without making changes}
                            {--limit= : Limit number of records to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill TAIDs for existing applications and ETA records';

    /**
     * Execute the console command.
     */
    public function handle(TaidService $taidService): int
    {
        $this->info('Starting TAID backfill process...');
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Show current statistics
        $this->showStatistics();
        $this->newLine();

        if (!$this->confirm('Do you want to proceed with the backfill?', true)) {
            $this->info('Backfill cancelled.');
            return Command::SUCCESS;
        }

        $this->newLine();
        $this->info('Processing records...');

        if ($this->option('dry-run')) {
            $this->info('Dry run completed. No changes were made.');
            return Command::SUCCESS;
        }

        // Perform actual backfill
        $stats = $taidService->backfillExistingRecords();

        $this->newLine();
        $this->info('Backfill completed!');
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Applications Updated', $stats['applications_updated']],
                ['ETA Applications Updated', $stats['eta_updated']],
                ['Errors', $stats['errors']],
            ]
        );

        if ($stats['errors'] > 0) {
            $this->warn('Some records failed to update. Check logs for details.');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Show current statistics
     */
    protected function showStatistics(): void
    {
        $applicationsWithoutTaid = \App\Models\Application::whereNull('taid')->count();
        $applicationsWithTaid = \App\Models\Application::whereNotNull('taid')->count();
        $etaWithoutTaid = \App\Models\EtaApplication::whereNull('taid')->count();
        $etaWithTaid = \App\Models\EtaApplication::whereNotNull('taid')->count();

        $this->info('Current TAID Statistics:');
        $this->table(
            ['Type', 'With TAID', 'Without TAID', 'Total'],
            [
                [
                    'Applications',
                    $applicationsWithTaid,
                    $applicationsWithoutTaid,
                    $applicationsWithTaid + $applicationsWithoutTaid,
                ],
                [
                    'ETA Applications',
                    $etaWithTaid,
                    $etaWithoutTaid,
                    $etaWithTaid + $etaWithoutTaid,
                ],
            ]
        );
    }
}
