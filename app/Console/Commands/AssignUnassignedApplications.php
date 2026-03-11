<?php

namespace App\Console\Commands;

use App\Models\Application;
use Illuminate\Console\Command;

class AssignUnassignedApplications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'applications:assign-unassigned
                            {--dry-run : Show what would be updated without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Assign applications in review statuses to GIS if they don\'t have an assigned agency';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Checking for unassigned applications...');

        $query = Application::whereIn('status', [
                'submitted',
                'under_review',
                'pending_approval',
                'additional_info_requested',
                'escalated',
            ])
            ->where(function ($q) {
                $q->whereNull('assigned_agency')
                  ->orWhere('assigned_agency', '');
            });

        $count = $query->count();

        if ($count === 0) {
            $this->info('✓ No unassigned applications found.');
            return self::SUCCESS;
        }

        $this->warn("Found {$count} unassigned application(s)");

        if ($this->option('dry-run')) {
            $this->table(
                ['ID', 'Reference', 'Status', 'Created At'],
                $query->get(['id', 'reference_number', 'status', 'created_at'])->map(function ($app) {
                    return [
                        $app->id,
                        $app->reference_number,
                        $app->status,
                        $app->created_at->format('Y-m-d H:i:s'),
                    ];
                })->toArray()
            );

            $this->info('Dry run complete. Run without --dry-run to apply changes.');
            return self::SUCCESS;
        }

        if (!$this->confirm('Do you want to assign these applications to GIS?', true)) {
            $this->info('Operation cancelled.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $updated = 0;
        $errors = 0;

        foreach ($query->cursor() as $application) {
            try {
                $application->update([
                    'assigned_agency' => 'gis',
                    'current_queue' => $this->determineQueue($application->status),
                ]);
                $updated++;
            } catch (\Exception $e) {
                $this->error("\nError updating application {$application->reference_number}: {$e->getMessage()}");
                $errors++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✓ Successfully assigned {$updated} application(s) to GIS");
        
        if ($errors > 0) {
            $this->error("✗ Failed to update {$errors} application(s)");
        }

        return self::SUCCESS;
    }

    /**
     * Determine the appropriate queue based on status.
     */
    private function determineQueue(string $status): string
    {
        return match ($status) {
            'pending_approval' => 'approval_queue',
            'submitted', 'under_review', 'additional_info_requested' => 'review_queue',
            default => 'review_queue',
        };
    }
}
