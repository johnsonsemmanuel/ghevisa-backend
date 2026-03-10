<?php

namespace App\Console\Commands;

use App\Models\EtaApplication;
use Illuminate\Console\Command;

class ExpireEtaApplications extends Command
{
    protected $signature = 'eta:expire';

    protected $description = 'Mark approved ETAs as expired when past their validity date';

    public function handle(): int
    {
        $count = EtaApplication::where('status', 'approved')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);

        $this->info("Expired {$count} ETA application(s).");

        return Command::SUCCESS;
    }
}

