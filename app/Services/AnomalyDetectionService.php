<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AnomalyDetectionService
{
    private const CACHE_PREFIX = 'anomaly_detection:';
    private const CACHE_TTL = 900; // 15 minutes

    // Z-score thresholds for anomaly detection
    private const VOLUME_THRESHOLD = 3.0;
    private const RESPONSE_TIME_THRESHOLD = 2.5;
    private const FAILURE_RATE_THRESHOLD = 2.0;

    /**
     * Detect anomalies in current hour data
     */
    public function detectCurrentHourAnomalies(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'current_hour';
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            $currentHour = now()->format('Y-m-d-H');
            $anomalies = [];

            // Get current hour stats
            $currentStats = $this->getCurrentHourStats();
            
            // Get historical baseline for comparison
            $baseline = $this->getHistoricalBaseline();

            // Check volume anomalies
            $volumeAnomaly = $this->detectVolumeAnomaly($currentStats['volume'], $baseline['volume']);
            if ($volumeAnomaly) {
                $anomalies[] = $volumeAnomaly;
            }

            // Check response time anomalies
            $responseTimeAnomaly = $this->detectResponseTimeAnomaly(
                $currentStats['avg_response_time'], 
                $baseline['response_time']
            );
            if ($responseTimeAnomaly) {
                $anomalies[] = $responseTimeAnomaly;
            }

            // Check failure rate anomalies
            $failureRateAnomaly = $this->detectFailureRateAnomaly(
                $currentStats['failure_rate'], 
                $baseline['failure_rate']
            );
            if ($failureRateAnomaly) {
                $anomalies[] = $failureRateAnomaly;
            }

            // Check for suspicious patterns
            $patternAnomalies = $this->detectSuspiciousPatterns();
            $anomalies = array_merge($anomalies, $patternAnomalies);

            return [
                'timestamp' => now()->toIso8601String(),
                'hour' => $currentHour,
                'anomalies_detected' => count($anomalies),
                'anomalies' => $anomalies,
                'baseline' => $baseline,
                'current_stats' => $currentStats,
            ];
        });
    }

    /**
     * Detect volume spikes or drops
     */
    private function detectVolumeAnomaly(int $currentVolume, array $volumeBaseline): ?array
    {
        if ($volumeBaseline['count'] < 5) {
            return null; // Not enough historical data
        }

        $zScore = $this->calculateZScore(
            $currentVolume, 
            $volumeBaseline['mean'], 
            $volumeBaseline['std_dev']
        );

        if (abs($zScore) > self::VOLUME_THRESHOLD) {
            $severity = match(true) {
                abs($zScore) > 4.0 => 'critical',
                abs($zScore) > 3.5 => 'high',
                default => 'medium',
            };

            $type = $zScore > 0 ? 'volume_spike' : 'volume_drop';
            $message = $zScore > 0 
                ? "Unusual volume spike detected: {$currentVolume} requests (normal: ~{$volumeBaseline['mean']})"
                : "Unusual volume drop detected: {$currentVolume} requests (normal: ~{$volumeBaseline['mean']})";

            return [
                'type' => $type,
                'severity' => $severity,
                'message' => $message,
                'z_score' => round($zScore, 2),
                'current_value' => $currentVolume,
                'expected_range' => [
                    'min' => max(0, (int) round($volumeBaseline['mean'] - 2 * $volumeBaseline['std_dev'])),
                    'max' => (int) round($volumeBaseline['mean'] + 2 * $volumeBaseline['std_dev']),
                ],
                'detected_at' => now()->toIso8601String(),
            ];
        }

        return null;
    }

    /**
     * Detect response time anomalies
     */
    private function detectResponseTimeAnomaly(float $currentResponseTime, array $responseTimeBaseline): ?array
    {
        if ($responseTimeBaseline['count'] < 5) {
            return null;
        }

        $zScore = $this->calculateZScore(
            $currentResponseTime,
            $responseTimeBaseline['mean'],
            $responseTimeBaseline['std_dev']
        );

        if ($zScore > self::RESPONSE_TIME_THRESHOLD) {
            $severity = match(true) {
                $zScore > 4.0 => 'critical',
                $zScore > 3.5 => 'high',
                default => 'medium',
            };

            return [
                'type' => 'response_time_degradation',
                'severity' => $severity,
                'message' => "Response time degradation detected: {$currentResponseTime}s (normal: ~{$responseTimeBaseline['mean']}s)",
                'z_score' => round($zScore, 2),
                'current_value' => round($currentResponseTime, 3),
                'expected_range' => [
                    'min' => round($responseTimeBaseline['mean'] - 2 * $responseTimeBaseline['std_dev'], 3),
                    'max' => round($responseTimeBaseline['mean'] + 2 * $responseTimeBaseline['std_dev'], 3),
                ],
                'detected_at' => now()->toIso8601String(),
            ];
        }

        return null;
    }

    /**
     * Detect failure rate anomalies
     */
    private function detectFailureRateAnomaly(float $currentFailureRate, array $failureRateBaseline): ?array
    {
        if ($failureRateBaseline['count'] < 5) {
            return null;
        }

        $zScore = $this->calculateZScore(
            $currentFailureRate,
            $failureRateBaseline['mean'],
            $failureRateBaseline['std_dev']
        );

        if ($zScore > self::FAILURE_RATE_THRESHOLD) {
            $severity = match(true) {
                $zScore > 3.5 => 'critical',
                $zScore > 3.0 => 'high',
                default => 'medium',
            };

            return [
                'type' => 'failure_rate_spike',
                'severity' => $severity,
                'message' => "Failure rate spike detected: {$currentFailureRate}% (normal: ~{$failureRateBaseline['mean']}%)",
                'z_score' => round($zScore, 2),
                'current_value' => round($currentFailureRate, 2),
                'expected_range' => [
                    'min' => max(0, round($failureRateBaseline['mean'] - 2 * $failureRateBaseline['std_dev'], 2)),
                    'max' => round($failureRateBaseline['mean'] + 2 * $failureRateBaseline['std_dev'], 2),
                ],
                'detected_at' => now()->toIso8601String(),
            ];
        }

        return null;
    }

    /**
     * Detect suspicious patterns
     */
    private function detectSuspiciousPatterns(): array
    {
        $anomalies = [];

        // Check for repeated passport verification attempts
        $suspiciousPassports = $this->detectSuspiciousPassportActivity();
        if (!empty($suspiciousPassports)) {
            $anomalies[] = [
                'type' => 'suspicious_passport_activity',
                'severity' => 'high',
                'message' => 'Multiple verification attempts detected for same passport(s)',
                'details' => $suspiciousPassports,
                'detected_at' => now()->toIso8601String(),
            ];
        }

        // Check for unusual failure patterns
        $unusualFailures = $this->detectUnusualFailurePatterns();
        if (!empty($unusualFailures)) {
            $anomalies[] = [
                'type' => 'unusual_failure_pattern',
                'severity' => 'medium',
                'message' => 'Unusual failure patterns detected',
                'details' => $unusualFailures,
                'detected_at' => now()->toIso8601String(),
            ];
        }

        // Check for potential brute force attempts
        $bruteForceAttempts = $this->detectBruteForceAttempts();
        if (!empty($bruteForceAttempts)) {
            $anomalies[] = [
                'type' => 'potential_brute_force',
                'severity' => 'critical',
                'message' => 'Potential brute force attack detected',
                'details' => $bruteForceAttempts,
                'detected_at' => now()->toIso8601String(),
            ];
        }

        return $anomalies;
    }

    /**
     * Get current hour statistics
     */
    private function getCurrentHourStats(): array
    {
        $currentHour = now()->format('Y-m-d H:00:00');
        $nextHour = now()->addHour()->format('Y-m-d H:00:00');

        $stats = DB::table('verification_performance_logs')
            ->selectRaw('
                COUNT(*) as volume,
                AVG(response_time) as avg_response_time,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failures,
                COUNT(*) as total
            ')
            ->whereBetween('created_at', [$currentHour, $nextHour])
            ->first();

        $failureRate = $stats->total > 0 ? ($stats->failures / $stats->total) * 100 : 0;

        return [
            'volume' => (int) $stats->volume,
            'avg_response_time' => (float) $stats->avg_response_time,
            'failure_rate' => $failureRate,
            'total_requests' => (int) $stats->total,
        ];
    }

    /**
     * Get historical baseline for comparison
     */
    private function getHistoricalBaseline(): array
    {
        $currentHour = now()->hour;
        $currentDayOfWeek = now()->dayOfWeek;

        // Get same hour, same day of week for last 4 weeks
        $baselineData = DB::table('verification_performance_logs')
            ->selectRaw('
                DATE(created_at) as date,
                COUNT(*) as volume,
                AVG(response_time) as avg_response_time,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failures,
                COUNT(*) as total
            ')
            ->whereRaw('HOUR(created_at) = ?', [$currentHour])
            ->whereRaw('DAYOFWEEK(created_at) = ?', [$currentDayOfWeek + 1])
            ->where('created_at', '>=', now()->subWeeks(4))
            ->where('created_at', '<', now()->startOfHour())
            ->groupBy('date')
            ->get();

        if ($baselineData->isEmpty()) {
            return [
                'volume' => ['mean' => 0, 'std_dev' => 0, 'count' => 0],
                'response_time' => ['mean' => 0, 'std_dev' => 0, 'count' => 0],
                'failure_rate' => ['mean' => 0, 'std_dev' => 0, 'count' => 0],
            ];
        }

        $volumes = $baselineData->pluck('volume')->toArray();
        $responseTimes = $baselineData->pluck('avg_response_time')->toArray();
        $failureRates = $baselineData->map(function ($item) {
            return $item->total > 0 ? ($item->failures / $item->total) * 100 : 0;
        })->toArray();

        return [
            'volume' => [
                'mean' => array_sum($volumes) / count($volumes),
                'std_dev' => $this->calculateStandardDeviation($volumes),
                'count' => count($volumes),
            ],
            'response_time' => [
                'mean' => array_sum($responseTimes) / count($responseTimes),
                'std_dev' => $this->calculateStandardDeviation($responseTimes),
                'count' => count($responseTimes),
            ],
            'failure_rate' => [
                'mean' => array_sum($failureRates) / count($failureRates),
                'std_dev' => $this->calculateStandardDeviation($failureRates),
                'count' => count($failureRates),
            ],
        ];
    }

    /**
     * Calculate Z-score
     */
    private function calculateZScore(float $value, float $mean, float $stdDev): float
    {
        if ($stdDev == 0) {
            return 0;
        }
        
        return ($value - $mean) / $stdDev;
    }

    /**
     * Calculate standard deviation
     */
    private function calculateStandardDeviation(array $values): float
    {
        if (count($values) < 2) {
            return 0;
        }

        $mean = array_sum($values) / count($values);
        $squaredDifferences = array_map(fn($value) => pow($value - $mean, 2), $values);
        $variance = array_sum($squaredDifferences) / count($values);
        
        return sqrt($variance);
    }

    /**
     * Detect suspicious passport activity
     */
    private function detectSuspiciousPassportActivity(): array
    {
        // This would require audit logs with passport info
        // For now, return empty array as we don't store passport numbers in performance logs
        return [];
    }

    /**
     * Detect unusual failure patterns
     */
    private function detectUnusualFailurePatterns(): array
    {
        $recentFailures = DB::table('verification_performance_logs')
            ->select('failure_reason')
            ->where('success', false)
            ->where('created_at', '>=', now()->subHour())
            ->whereNotNull('failure_reason')
            ->get()
            ->groupBy('failure_reason')
            ->map(fn($group) => count($group))
            ->toArray();

        $unusualPatterns = [];
        foreach ($recentFailures as $reason => $count) {
            if ($count > 10) { // More than 10 failures of same type in an hour
                $unusualPatterns[] = [
                    'failure_reason' => $reason,
                    'count' => $count,
                    'threshold' => 10,
                ];
            }
        }

        return $unusualPatterns;
    }

    /**
     * Detect potential brute force attempts
     */
    private function detectBruteForceAttempts(): array
    {
        // Check for high volume of failures in short time period
        $recentFailures = DB::table('verification_performance_logs')
            ->selectRaw('COUNT(*) as failure_count')
            ->where('success', false)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->first();

        if ($recentFailures->failure_count > 50) { // More than 50 failures in 10 minutes
            return [
                'failure_count' => $recentFailures->failure_count,
                'time_window' => '10 minutes',
                'threshold' => 50,
            ];
        }

        return [];
    }

    /**
     * Log anomaly for alerting system
     */
    public function logAnomaly(array $anomaly): void
    {
        Log::warning('Anomaly detected', [
            'type' => $anomaly['type'],
            'severity' => $anomaly['severity'],
            'message' => $anomaly['message'],
            'details' => $anomaly,
        ]);

        // Store in database for historical analysis
        try {
            DB::table('anomaly_logs')->insert([
                'type' => $anomaly['type'],
                'severity' => $anomaly['severity'],
                'message' => $anomaly['message'],
                'details' => json_encode($anomaly),
                'detected_at' => now(),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to store anomaly log', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get anomaly history
     */
    public function getAnomalyHistory(int $hours = 24): array
    {
        return DB::table('anomaly_logs')
            ->select('type', 'severity', 'message', 'detected_at')
            ->where('detected_at', '>=', now()->subHours($hours))
            ->orderBy('detected_at', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Clear anomaly detection cache
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_PREFIX . 'current_hour');
    }
}