<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register Rule-Based Risk Engine Service
        $this->app->singleton(\App\Services\RuleBasedRiskEngineService::class, function ($app) {
            return new \App\Services\RuleBasedRiskEngineService(
                new \App\Services\RiskEvaluators\IdentityRiskEvaluator(),
                new \App\Services\RiskEvaluators\TravelPatternEvaluator(),
                new \App\Services\RiskEvaluators\FinancialRiskEvaluator(),
                new \App\Services\RiskEvaluators\ImmigrationHistoryEvaluator(),
                new \App\Services\RiskEvaluators\DocumentQualityEvaluator(),
                new \App\Services\RiskReasonGenerator()
            );
        });
        
        // Alias for backward compatibility
        $this->app->alias(\App\Services\RuleBasedRiskEngineService::class, 'risk.engine');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
