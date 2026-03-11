<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\PredictiveAnalyticsService;
use App\Services\AnomalyDetectionService;
use Illuminate\Http\JsonResponse;

/**
 * Advanced Analytics Controller
 * 
 * Provides predictive analytics, anomaly detection, and trend analysis.
 * Admin-only access.
 */
class AnalyticsController extends Controller
{
    public function __construct(
        protected PredictiveAnalyticsService $predictiveService,
        protected AnomalyDetectionService $anomalyService
    ) {}

    /**
     * Get predictive analytics dashboard
     */
    public function predictive(): JsonResponse
    {
        $nextHour = $this->predictiveService->predictNextHourVolume();
        $next24Hours = $this->predictiveService->predictNext24HoursVolume();
        $capacity = $this->predictiveService->predictCapacityRequirements();
        $trends = $this->predictiveService->identifyPerformanceTrends();

        return response()->json([
            'timestamp' => now()->toIso8601String(),
            'next_hour_prediction' => $nextHour,
            'next_24_hours_prediction' => $next24Hours,
            'capacity_requirements' => $capacity,
            'performance_trends' => $trends,
        ]);
    }

    /**
     * Get anomaly detection results
     */
    public function anomalies(): JsonResponse
    {
        $currentAnomalies = $this->anomalyService->detectCurrentHourAnomalies();
        $history = $this->anomalyService->getAnomalyHistory(24);

        return response()->json([
            'timestamp' => now()->toIso8601String(),
            'current_anomalies' => $currentAnomalies,
            'anomaly_history' => $history,
        ]);
    }

    /**
     * Get trend analysis
     */
    public function trends(): JsonResponse
    {
        $trends = $this->predictiveService->identifyPerformanceTrends();

        return response()->json([
            'timestamp' => now()->toIso8601String(),
            'trends' => $trends,
        ]);
    }

    /**
     * Get capacity forecasting
     */
    public function capacity(): JsonResponse
    {
        $capacity = $this->predictiveService->predictCapacityRequirements();
        $next24Hours = $this->predictiveService->predictNext24HoursVolume();

        return response()->json([
            'timestamp' => now()->toIso8601String(),
            'capacity_requirements' => $capacity,
            'volume_forecast' => $next24Hours,
        ]);
    }

    /**
     * Clear analytics cache
     */
    public function clearCache(): JsonResponse
    {
        $this->predictiveService->clearCache();
        $this->anomalyService->clearCache();

        return response()->json([
            'message' => 'Analytics cache cleared successfully',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}