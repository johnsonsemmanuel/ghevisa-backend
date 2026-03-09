<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;

class HealthController extends Controller
{
    /**
     * System health check with queue monitoring.
     */
    public function index(): JsonResponse
    {
        $queueSize = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();

        // Simple thresholds
        $queueHealthy = $queueSize < 100;
        $failedHealthy = $failedJobs < 5;

        $status = $queueHealthy && $failedHealthy ? 'healthy' : 'degraded';

        return response()->json([
            'status' => $status,
            'timestamp' => now()->toISOString(),
            'checks' => [
                'queue' => [
                    'size' => $queueSize,
                    'healthy' => $queueHealthy,
                    'threshold' => 100,
                ],
                'failed_jobs' => [
                    'count' => $failedJobs,
                    'healthy' => $failedHealthy,
                    'threshold' => 5,
                ],
            ],
        ]);
    }
}
