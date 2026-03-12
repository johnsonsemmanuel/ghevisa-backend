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
        $this->app->singleton(\App\Services\Risk\RuleBasedRiskEngine::class, function ($app) {
            return new \App\Services\Risk\RuleBasedRiskEngine(
                new \App\Services\Risk\Evaluators\IdentityRiskEvaluator(),
                new \App\Services\Risk\Evaluators\TravelPatternEvaluator(),
                new \App\Services\Risk\Evaluators\FinancialRiskEvaluator(),
                new \App\Services\Risk\Evaluators\ImmigrationHistoryEvaluator(),
                new \App\Services\Risk\Evaluators\DocumentQualityEvaluator(),
                new \App\Services\Risk\RiskReasonGenerator()
            );
        });
        
        // Alias for backward compatibility
        $this->app->alias(\App\Services\Risk\RuleBasedRiskEngine::class, 'risk.engine');
        
        // Register Risk Scoring Orchestrator
        $this->app->singleton(\App\Services\Risk\RiskScoringOrchestrator::class);
        
        // Register Payment Services
        $this->app->singleton(\App\Services\Payment\PaymentOrchestrator::class);
        $this->app->singleton(\App\Services\Payment\Providers\PaystackProvider::class);
        $this->app->singleton(\App\Services\Payment\Providers\GcbProvider::class);
        
        // Backward compatibility aliases for payment services
        $this->app->alias(\App\Services\Payment\PaymentOrchestrator::class, \App\Services\MultiPaymentService::class);
        $this->app->alias(\App\Services\Payment\Providers\PaystackProvider::class, \App\Services\PaystackService::class);
        $this->app->alias(\App\Services\Payment\Providers\GcbProvider::class, \App\Services\GcbPaymentService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
