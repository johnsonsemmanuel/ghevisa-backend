<?php

namespace Database\Seeders;

use App\Services\AlertingService;
use Illuminate\Database\Seeder;

class DefaultAlertRulesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $alertingService = app(AlertingService::class);
        $defaultRules = config('alerting.default_rules');

        foreach ($defaultRules as $rule) {
            // Check if rule already exists
            $existing = \DB::table('alert_rules')
                ->where('name', $rule['name'])
                ->first();

            if (!$existing) {
                $alertingService->createAlertRule($rule);
                $this->command->info("Created alert rule: {$rule['name']}");
            } else {
                $this->command->info("Alert rule already exists: {$rule['name']}");
            }
        }
    }
}
