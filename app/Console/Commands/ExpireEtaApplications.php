<?php

namespace App\Console\Commands;

use App\Models\EtaApplication;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Expire ETA Applications Command
 * 
 * Automatically marks ETA applications as expired when their expiry date passes.
 * Should run daily via cron.
 */
class ExpireEtaApplications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'eta:expire
                          {--dry-run : Run without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark expired ETA applications as expired';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        
        $this->info('Checking for expired ETA applications...');
        
        // Find approved ETAs that have passed their expiry date
        $expiredEtas = EtaApplication::where('status', 'approved')
            ->where('expires_at', '<', now())
            ->get();
        
        if ($expiredEtas->isEmpty()) {
            $this->info('No expired ETAs found.');
            return Command::SUCCESS;
        }
        
        $this->info("Found {$expiredEtas->count()} expired ETA(s)");
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->table(
                ['ETA Number', 'Expired At', 'Days Overdue'],
                $expiredEtas->map(function ($eta) {
                    return [
                        $eta->eta_number,
                        $eta->expires_at->format('Y-m-d H:i:s'),
                        now()->diffInDays($eta->expires_at) . ' days',
                    ];
                })
            );
            return Command::SUCCESS;
        }
        
        $updated = 0;
        foreach ($expiredEtas as $eta) {
            try {
                $eta->update(['status' => 'expired']);
                $updated++;
                
                Log::info('ETA expired automatically', [
                    'eta_number' => $eta->eta_number,
                    'expired_at' => $eta->expires_at,
                    'user_id' => $eta->user_id,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to expire ETA', [
                    'eta_number' => $eta->eta_number,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        $this->info("Successfully expired {$updated} ETA application(s)");
        
        return Command::SUCCESS;
    }
}
