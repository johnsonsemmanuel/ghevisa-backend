<?php

namespace App\Services\Verification;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VerificationPerformanceService
{
    private const CACHE_PREFIX = 'verification_performance:';
    private const CACHE_TTL = 3600; // 1 hour
    private const SLA_THRESHOLD = 2.0; // 2 seconds
    private const SLOW_REQUEST_THRESHOLD = 2.0; // 2 seconds

    /**
     * Record a verification request performance metric
     */
    public function recordVerification(
        string $verificationType,
        float $responseTime,
        bool $success,
        ?string $failureReason = null
    ): void {
        try {
            // Store in database for historical analysis
            DB::table('verification_performance_logs')->insert([
                'verification_type' => $verificationType,
                'response_time' => $responseTime,
                'success' => $success,
                'failure_reason' => $failureReason,
                'created_at' => now(),
            ]);

            // Update hourly cache statistics
            $this->updateHourlyStats($verificationType, $responseTime, $success, $failureReason);
        } catch (\Exception $e) {
            // Don't let performance tracking break the main flow
            Log::warning('Failed to record verification performance', [
                'error' => $e->getMessage(),
                'type' => $verificationType,
            ]);
        }
    }

    /**
     * Update hourly statistics in cache
     */
    private function updateHourlyStats(
        string $verificationType,
        float $responseTime,
        bool $success,
        ?string $failureReason
    ): void {
        $hourKey = $this->getCurrentHourKey();
        $cacheKey = self::CACHE_PREFIX . $hourKey;

        $stats = Cache::get($cacheKey, [
            'total_requests' => 0,
            'successful_requests' => 0,
            'failed_requests' => 0,
            'response_times' => [],
            'by_type' => [],
            'failure_reasons' => [],
        ]);

        // Update totals
        $stats['total_requests']++;
        if ($success) {
            $stats['successful_requests']++;
        } else {
            $stats['failed_requests']++;
            if ($failureReason) {
                $stats['failure_reasons'][$failureReason] = ($stats['failure_reasons'][$failureReason] ?? 0) + 1;
            }
        }

        // Track response times
        $stats['response_times'][] = $responseTime;

        // Update by type
        if (!isset($stats['by_type'][$verificationType])) {
            $stats['by_type'][$verificationType] = [
                'count' => 0,
                'response_times' => [],
            ];
        }
        $stats['by_type'][$verificationType]['count']++;
        $stats['by_type'][$verificationType]['response_times'][] = $responseTime;

        Cache::put($cacheKey, $stats, self::CACHE_TTL);
    }

    /**
     * Get current hour statistics
     */
    public function getCurrentHourStats(): array
    {
        $hourKey = $this->getCurrentHourKey();
        $cacheKey = self::CACHE_PREFIX . $hourKey;

        $stats = Cache::get($cacheKey, [
            'total_requests' => 0,
            'successful_requests' => 0,
            'failed_requests' => 0,
            'response_times' => [],
            'by_type' => [],
            'failure_reasons' => [],
        ]);

        return $this->calculateStats($stats);
    }

    /**
     * Get last 24 hours statistics
     */
    public function getLast24HoursStats(): array
    {
        $hourlyStats = [];
        $now = now();

        for ($i = 23; $i >= 0; $i--) {
            $hour = $now->copy()->subHours($i);
            $hourKey = $hour->format('Y-m-d-H');
            $cacheKey = self::CACHE_PREFIX . $hourKey;

            $stats = Cache::get($cacheKey, [
                'total_requests' => 0,
                'successful_requests' => 0,
                'failed_requests' => 0,
                'response_times' => [],
                'by_type' => [],
                'failure_reasons' => [],
            ]);

            $hourlyStats[] = array_merge(
                ['hour' => $hourKey],
                $this->calculateStats($stats)
            );
        }

        return $hourlyStats;
    }

    /**
     * Get dashboard summary
     */
    public function getDashboardSummary(): array
    {
        $currentHour = $this->getCurrentHourStats();
        $last24Hours = $this->getLast24HoursStats();

        $totalRequests = array_sum(array_column($last24Hours, 'total_requests'));

        return [
            'current_hour' => $currentHour,
            'last_24_hours_summary' => [
                'total_requests' => $totalRequests,
                'hourly_breakdown' => $last24Hours,
            ],
            'performance_status' => $this->getPerformanceStatus($currentHour),
        ];
    }

    /**
     * Calculate statistics from raw data
     */
    private function calculateStats(array $rawStats): array
    {
        $totalRequests = $rawStats['total_requests'];
        $successfulRequests = $rawStats['successful_requests'];
        $failedRequests = $rawStats['failed_requests'];
        $responseTimes = $rawStats['response_times'];

        if ($totalRequests === 0) {
            return [
                'total_requests' => 0,
                'successful_requests' => 0,
                'failed_requests' => 0,
                'success_rate' => 0,
                'average_response_time' => 0,
                'max_response_time' => 0,
                'min_response_time' => 0,
                'slow_requests' => 0,
                'slow_request_percentage' => 0,
                'by_type' => [],
                'failure_reasons' => [],
            ];
        }

        $avgResponseTime = count($responseTimes) > 0 ? array_sum($responseTimes) / count($responseTimes) : 0;
        $maxResponseTime = count($responseTimes) > 0 ? max($responseTimes) : 0;
        $minResponseTime = count($responseTimes) > 0 ? min($responseTimes) : 0;
        $slowRequests = count(array_filter($responseTimes, fn($time) => $time > self::SLOW_REQUEST_THRESHOLD));
        $slowRequestPercentage = ($slowRequests / $totalRequests) * 100;

        // Calculate by type statistics
        $byType = [];
        foreach ($rawStats['by_type'] as $type => $typeData) {
            $typeResponseTimes = $typeData['response_times'];
            $byType[$type] = [
                'count' => $typeData['count'],
                'average_time' => count($typeResponseTimes) > 0 
                    ? array_sum($typeResponseTimes) / count($typeResponseTimes) 
                    : 0,
            ];
        }

        return [
            'total_requests' => $totalRequests,
            'successful_requests' => $successfulRequests,
            'failed_requests' => $failedRequests,
            'success_rate' => ($successfulRequests / $totalRequests) * 100,
            'average_response_time' => $avgResponseTime,
            'max_response_time' => $maxResponseTime,
            'min_response_time' => $minResponseTime,
            'slow_requests' => $slowRequests,
            'slow_request_percentage' => $slowRequestPercentage,
            'by_type' => $byType,
            'failure_reasons' => $rawStats['failure_reasons'],
        ];
    }

    /**
     * Get performance status
     */
    private function getPerformanceStatus(array $stats): array
    {
        $avgResponseTime = $stats['average_response_time'];
        $slowRequestPercentage = $stats['slow_request_percentage'];
        $meetsSla = $avgResponseTime < self::SLA_THRESHOLD;

        if ($avgResponseTime < 1.0 && $slowRequestPercentage < 5) {
            $status = 'excellent';
            $message = 'System performing optimally';
        } elseif ($avgResponseTime < 1.5 && $slowRequestPercentage < 10) {
            $status = 'good';
            $message = 'System performing well';
        } elseif ($avgResponseTime < 2.0 && $slowRequestPercentage < 20) {
            $status = 'acceptable';
            $message = 'System meeting requirements';
        } else {
            $status = 'degraded';
            $message = 'System performance degraded - investigation needed';
        }

        return [
            'status' => $status,
            'message' => $message,
            'average_response_time' => $avgResponseTime,
            'slow_request_percentage' => $slowRequestPercentage,
            'meets_sla' => $meetsSla,
        ];
    }

    /**
     * Get current hour key for cache
     */
    private function getCurrentHourKey(): string
    {
        return now()->format('Y-m-d-H');
    }

    /**
     * Clear old cache entries (older than 48 hours)
     */
    public function clearOldCache(): void
    {
        $now = now();
        
        for ($i = 48; $i < 72; $i++) {
            $hour = $now->copy()->subHours($i);
            $hourKey = $hour->format('Y-m-d-H');
            $cacheKey = self::CACHE_PREFIX . $hourKey;
            Cache::forget($cacheKey);
        }
    }
}