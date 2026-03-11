<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PredictiveAnalyticsService
{
    private const CACHE_PREFIX = 'predictive_analytics:';
    private const CACHE_TTL = 1800; // 30 minutes

    /**
     * Predict verification volume for the next hour
     */
    public function predictNextHourVolume(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'next_hour_volume';
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            $currentHour = now()->hour;
            $currentDayOfWeek = now()->dayOfWeek;
            
            // Get historical data for the same hour and day of week
            $historicalData = DB::table('verification_performance_logs')
                ->selectRaw('DATE(created_at) as date, COUNT(*) as volume')
                ->whereRaw('HOUR(created_at) = ?', [$currentHour])
                ->whereRaw('DAYOFWEEK(created_at) = ?', [$currentDayOfWeek + 1]) // MySQL DAYOFWEEK is 1-based
                ->where('created_at', '>=', now()->subDays(30))
                ->groupBy('date')
                ->orderBy('date', 'desc')
                ->limit(10)
                ->get();

            if ($historicalData->isEmpty()) {
                return [
                    'predicted_volume' => 0,
                    'confidence' => 'low',
                    'historical_average' => 0,
                    'trend' => 'stable',
                    'data_points' => 0,
                ];
            }

            $volumes = $historicalData->pluck('volume')->toArray();
            $average = array_sum($volumes) / count($volumes);
            
            // Simple trend analysis
            $recentAvg = count($volumes) >= 3 ? array_sum(array_slice($volumes, 0, 3)) / 3 : $average;
            $olderAvg = count($volumes) >= 6 ? array_sum(array_slice($volumes, -3)) / 3 : $average;
            
            $trendDirection = 'stable';
            if ($recentAvg > $olderAvg * 1.1) {
                $trendDirection = 'increasing';
            } elseif ($recentAvg < $olderAvg * 0.9) {
                $trendDirection = 'decreasing';
            }

            // Apply trend adjustment
            $trendMultiplier = match($trendDirection) {
                'increasing' => 1.1,
                'decreasing' => 0.9,
                default => 1.0,
            };

            $predictedVolume = (int) round($average * $trendMultiplier);
            
            // Determine confidence based on data consistency
            $standardDeviation = $this->calculateStandardDeviation($volumes);
            $coefficientOfVariation = $average > 0 ? $standardDeviation / $average : 1;
            
            $confidence = match(true) {
                $coefficientOfVariation < 0.2 => 'high',
                $coefficientOfVariation < 0.5 => 'medium',
                default => 'low',
            };

            return [
                'predicted_volume' => $predictedVolume,
                'confidence' => $confidence,
                'historical_average' => (int) round($average),
                'trend' => $trendDirection,
                'data_points' => count($volumes),
                'standard_deviation' => round($standardDeviation, 2),
                'coefficient_of_variation' => round($coefficientOfVariation, 2),
            ];
        });
    }

    /**
     * Predict verification volume for the next 24 hours
     */
    public function predictNext24HoursVolume(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'next_24h_volume';
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            $predictions = [];
            $totalPredicted = 0;
            
            for ($i = 0; $i < 24; $i++) {
                $targetHour = now()->addHours($i);
                $hourPrediction = $this->predictHourVolume($targetHour);
                
                $predictions[] = [
                    'hour' => $targetHour->format('Y-m-d-H'),
                    'predicted_volume' => $hourPrediction['volume'],
                    'confidence' => $hourPrediction['confidence'],
                ];
                
                $totalPredicted += $hourPrediction['volume'];
            }

            return [
                'total_predicted_volume' => $totalPredicted,
                'hourly_predictions' => $predictions,
                'peak_hour' => $this->findPeakHour($predictions),
                'low_hour' => $this->findLowHour($predictions),
            ];
        });
    }

    /**
     * Predict peak hours and capacity requirements
     */
    public function predictCapacityRequirements(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'capacity_requirements';
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            $next24Hours = $this->predictNext24HoursVolume();
            $peakVolume = max(array_column($next24Hours['hourly_predictions'], 'predicted_volume'));
            
            // Assume each verification takes 0.5 seconds on average
            $avgProcessingTime = 0.5;
            $safetyMargin = 1.5; // 50% safety margin
            
            $requiredCapacity = ceil($peakVolume * $avgProcessingTime * $safetyMargin);
            
            // Current system capacity (requests per hour)
            $currentCapacity = 10000; // 10k requests/hour
            
            $utilizationPercentage = ($peakVolume / $currentCapacity) * 100;
            
            $recommendation = match(true) {
                $utilizationPercentage > 80 => 'scale_up',
                $utilizationPercentage < 30 => 'scale_down',
                default => 'maintain',
            };

            return [
                'peak_predicted_volume' => $peakVolume,
                'required_capacity' => $requiredCapacity,
                'current_capacity' => $currentCapacity,
                'utilization_percentage' => round($utilizationPercentage, 2),
                'recommendation' => $recommendation,
                'safety_margin' => $safetyMargin,
                'peak_hours' => array_filter($next24Hours['hourly_predictions'], 
                    fn($h) => $h['predicted_volume'] > $peakVolume * 0.8
                ),
            ];
        });
    }

    /**
     * Identify performance degradation trends
     */
    public function identifyPerformanceTrends(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'performance_trends';
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            // Get hourly performance data for the last 7 days
            $performanceData = DB::table('verification_performance_logs')
                ->selectRaw('
                    DATE_FORMAT(created_at, "%Y-%m-%d-%H") as hour,
                    AVG(response_time) as avg_response_time,
                    COUNT(*) as volume,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_requests,
                    SUM(CASE WHEN response_time > 2.0 THEN 1 ELSE 0 END) as slow_requests
                ')
                ->where('created_at', '>=', now()->subDays(7))
                ->groupBy('hour')
                ->orderBy('hour', 'desc')
                ->get();

            if ($performanceData->isEmpty()) {
                return [
                    'trend' => 'insufficient_data',
                    'message' => 'Not enough data to determine trends',
                ];
            }

            $responseTimes = $performanceData->pluck('avg_response_time')->toArray();
            $successRates = $performanceData->map(function ($item) {
                return $item->volume > 0 ? ($item->successful_requests / $item->volume) * 100 : 100;
            })->toArray();

            // Analyze trends
            $responseTimeTrend = $this->analyzeTrend($responseTimes);
            $successRateTrend = $this->analyzeTrend($successRates);

            // Predict if SLA breach is likely
            $currentAvgResponseTime = $responseTimes[0] ?? 0;
            $slaBreachRisk = $this->predictSlaBreachRisk($responseTimes);

            return [
                'response_time_trend' => $responseTimeTrend,
                'success_rate_trend' => $successRateTrend,
                'current_avg_response_time' => round($currentAvgResponseTime, 3),
                'sla_breach_risk' => $slaBreachRisk,
                'data_points' => count($performanceData),
                'analysis_period' => '7 days',
                'recommendations' => $this->generatePerformanceRecommendations(
                    $responseTimeTrend, 
                    $successRateTrend, 
                    $slaBreachRisk
                ),
            ];
        });
    }

    /**
     * Predict hourly volume for a specific hour
     */
    private function predictHourVolume(Carbon $targetHour): array
    {
        $hour = $targetHour->hour;
        $dayOfWeek = $targetHour->dayOfWeek;
        
        // Get historical data for the same hour and day of week
        $historicalVolumes = DB::table('verification_performance_logs')
            ->selectRaw('COUNT(*) as volume')
            ->whereRaw('HOUR(created_at) = ?', [$hour])
            ->whereRaw('DAYOFWEEK(created_at) = ?', [$dayOfWeek + 1])
            ->where('created_at', '>=', now()->subDays(30))
            ->whereRaw('DATE(created_at) != ?', [now()->format('Y-m-d')]) // Exclude today
            ->groupByRaw('DATE(created_at)')
            ->pluck('volume')
            ->toArray();

        if (empty($historicalVolumes)) {
            return ['volume' => 0, 'confidence' => 'low'];
        }

        $average = array_sum($historicalVolumes) / count($historicalVolumes);
        $standardDeviation = $this->calculateStandardDeviation($historicalVolumes);
        $coefficientOfVariation = $average > 0 ? $standardDeviation / $average : 1;
        
        $confidence = match(true) {
            $coefficientOfVariation < 0.3 => 'high',
            $coefficientOfVariation < 0.6 => 'medium',
            default => 'low',
        };

        return [
            'volume' => (int) round($average),
            'confidence' => $confidence,
        ];
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
     * Find peak hour from predictions
     */
    private function findPeakHour(array $predictions): array
    {
        $maxVolume = 0;
        $peakHour = null;

        foreach ($predictions as $prediction) {
            if ($prediction['predicted_volume'] > $maxVolume) {
                $maxVolume = $prediction['predicted_volume'];
                $peakHour = $prediction;
            }
        }

        return $peakHour ?? ['hour' => 'unknown', 'predicted_volume' => 0];
    }

    /**
     * Find low hour from predictions
     */
    private function findLowHour(array $predictions): array
    {
        $minVolume = PHP_INT_MAX;
        $lowHour = null;

        foreach ($predictions as $prediction) {
            if ($prediction['predicted_volume'] < $minVolume) {
                $minVolume = $prediction['predicted_volume'];
                $lowHour = $prediction;
            }
        }

        return $lowHour ?? ['hour' => 'unknown', 'predicted_volume' => 0];
    }

    /**
     * Analyze trend direction
     */
    private function analyzeTrend(array $values): array
    {
        if (count($values) < 3) {
            return ['direction' => 'unknown', 'strength' => 'weak'];
        }

        // Simple linear regression to determine trend
        $n = count($values);
        $x = range(1, $n);
        $y = array_reverse($values); // Reverse to get chronological order

        $sumX = array_sum($x);
        $sumY = array_sum($y);
        $sumXY = array_sum(array_map(fn($i) => $x[$i] * $y[$i], range(0, $n - 1)));
        $sumX2 = array_sum(array_map(fn($val) => $val * $val, $x));

        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
        
        $direction = match(true) {
            $slope > 0.1 => 'increasing',
            $slope < -0.1 => 'decreasing',
            default => 'stable',
        };

        $strength = match(true) {
            abs($slope) > 0.5 => 'strong',
            abs($slope) > 0.2 => 'moderate',
            default => 'weak',
        };

        return [
            'direction' => $direction,
            'strength' => $strength,
            'slope' => round($slope, 4),
        ];
    }

    /**
     * Predict SLA breach risk
     */
    private function predictSlaBreachRisk(array $responseTimes): array
    {
        if (empty($responseTimes)) {
            return ['risk_level' => 'unknown', 'probability' => 0];
        }

        $recentTimes = array_slice($responseTimes, 0, 6); // Last 6 hours
        $avgRecentTime = array_sum($recentTimes) / count($recentTimes);
        
        $trend = $this->analyzeTrend($recentTimes);
        
        $riskLevel = match(true) {
            $avgRecentTime > 1.8 && $trend['direction'] === 'increasing' => 'high',
            $avgRecentTime > 1.5 && $trend['direction'] === 'increasing' => 'medium',
            $avgRecentTime > 1.2 => 'low',
            default => 'minimal',
        };

        $probability = match($riskLevel) {
            'high' => 0.8,
            'medium' => 0.5,
            'low' => 0.2,
            default => 0.05,
        };

        return [
            'risk_level' => $riskLevel,
            'probability' => $probability,
            'current_avg' => round($avgRecentTime, 3),
            'trend' => $trend,
        ];
    }

    /**
     * Generate performance recommendations
     */
    private function generatePerformanceRecommendations(
        array $responseTimeTrend,
        array $successRateTrend,
        array $slaBreachRisk
    ): array {
        $recommendations = [];

        if ($responseTimeTrend['direction'] === 'increasing' && $responseTimeTrend['strength'] !== 'weak') {
            $recommendations[] = [
                'type' => 'performance',
                'priority' => 'high',
                'message' => 'Response times are increasing. Consider scaling up infrastructure.',
                'action' => 'scale_infrastructure',
            ];
        }

        if ($successRateTrend['direction'] === 'decreasing' && $successRateTrend['strength'] !== 'weak') {
            $recommendations[] = [
                'type' => 'reliability',
                'priority' => 'high',
                'message' => 'Success rates are declining. Investigate system errors.',
                'action' => 'investigate_errors',
            ];
        }

        if ($slaBreachRisk['risk_level'] === 'high') {
            $recommendations[] = [
                'type' => 'sla',
                'priority' => 'critical',
                'message' => 'High risk of SLA breach. Immediate action required.',
                'action' => 'immediate_optimization',
            ];
        }

        if (empty($recommendations)) {
            $recommendations[] = [
                'type' => 'status',
                'priority' => 'info',
                'message' => 'System performance is stable. Continue monitoring.',
                'action' => 'maintain_monitoring',
            ];
        }

        return $recommendations;
    }

    /**
     * Clear predictive analytics cache
     */
    public function clearCache(): void
    {
        $keys = [
            'next_hour_volume',
            'next_24h_volume',
            'capacity_requirements',
            'performance_trends',
        ];

        foreach ($keys as $key) {
            Cache::forget(self::CACHE_PREFIX . $key);
        }
    }
}