<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\VerificationPerformanceService;
use Illuminate\Http\JsonResponse;

/**
 * Verification Statistics Controller
 * 
 * Provides performance metrics and statistics for the verification system.
 * Admin-only access.
 */
class VerificationStatsController extends Controller
{
    public function __construct(
        protected VerificationPerformanceService $performanceService
    ) {}

    /**
     * Get current hour statistics
     */
    public function currentHour(): JsonResponse
    {
        $stats = $this->performanceService->getCurrentHourStats();
        
        return response()->json([
            'period' => 'current_hour',
            'timestamp' => now()->toIso8601String(),
            'stats' => $stats,
        ]);
    }

    /**
     * Get last 24 hours statistics
     */
    public function last24Hours(): JsonResponse
    {
        $stats = $this->performanceService->getLast24HoursStats();
        
        return response()->json([
            'period' => 'last_24_hours',
            'timestamp' => now()->toIso8601String(),
            'hourly_stats' => $stats,
        ]);
    }

    /**
     * Get dashboard summary
     */
    public function dashboard(): JsonResponse
    {
        $currentHour = $this->performanceService->getCurrentHourStats();
        $last24Hours = $this->performanceService->getLast24HoursStats();
        
        // Calculate 24-hour totals
        $total24h = array_reduce($last24Hours, function ($carry, $hour) {
            $carry['total_requests'] += $hour['total_requests'];
            return $carry;
        }, ['total_requests' => 0]);
        
        return response()->json([
            'current_hour' => $currentHour,
            'last_24_hours_summary' => [
                'total_requests' => $total24h['total_requests'],
                'hourly_breakdown' => $last24Hours,
            ],
            'performance_status' => $this->getPerformanceStatus($currentHour),
        ]);
    }

    /**
     * Determine performance status
     */
    protected function getPerformanceStatus(array $stats): array
    {
        $avgTime = $stats['average_response_time'];
        $slowPercentage = $stats['slow_request_percentage'];
        
        if ($avgTime < 1.0 && $slowPercentage < 5) {
            $status = 'excellent';
            $message = 'System performing optimally';
        } elseif ($avgTime < 1.5 && $slowPercentage < 10) {
            $status = 'good';
            $message = 'System performing well';
        } elseif ($avgTime < 2.0 && $slowPercentage < 20) {
            $status = 'acceptable';
            $message = 'System meeting requirements';
        } else {
            $status = 'degraded';
            $message = 'System performance degraded - investigation needed';
        }
        
        return [
            'status' => $status,
            'message' => $message,
            'average_response_time' => $avgTime,
            'slow_request_percentage' => $slowPercentage,
            'meets_sla' => $avgTime < 2.0, // < 2 seconds requirement
        ];
    }
}
