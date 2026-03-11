<?php

namespace App\Http\Controllers\Api\Border;

use App\Http\Controllers\Controller;
use App\Models\BorderCrossing;
use App\Models\BoardingAuthorization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class OperationsController extends Controller
{
    /**
     * Get operations dashboard statistics
     */
    public function getStats(Request $request): JsonResponse
    {
        $timeRange = $request->input('time_range', 'today');
        
        $startDate = match($timeRange) {
            'today' => Carbon::today(),
            'week' => Carbon::now()->startOfWeek(),
            'month' => Carbon::now()->startOfMonth(),
            default => Carbon::today(),
        };

        // Get border crossing statistics
        $stats = BorderCrossing::where('created_at', '>=', $startDate)
            ->where('crossing_type', 'entry')
            ->selectRaw('
                COUNT(*) as total_verifications,
                SUM(CASE WHEN verification_status = "valid" THEN 1 ELSE 0 END) as authorized,
                SUM(CASE WHEN verification_status != "valid" THEN 1 ELSE 0 END) as denied,
                AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as avg_processing_time
            ')
            ->first();

        // Get active officers count
        $activeOfficers = User::whereIn('role', ['border_officer', 'border_supervisor'])
            ->where('is_active', true)
            ->whereHas('borderCrossings', function($query) {
                $query->where('created_at', '>=', Carbon::now()->subHours(2));
            })
            ->count();

        // Get active ports count
        $activePorts = BorderCrossing::where('created_at', '>=', Carbon::now()->subHours(2))
            ->distinct('port_of_entry')
            ->count('port_of_entry');

        return response()->json([
            'total_verifications' => $stats->total_verifications ?? 0,
            'authorized' => $stats->authorized ?? 0,
            'denied' => $stats->denied ?? 0,
            'avg_processing_time' => $stats->avg_processing_time ? round($stats->avg_processing_time) . 's' : '0s',
            'active_officers' => $activeOfficers,
            'ports_active' => $activePorts,
        ]);
    }

    /**
     * Get recent activity
     */
    public function getRecentActivity(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 10);

        $activity = BorderCrossing::with(['officer:id,first_name,last_name'])
            ->where('crossing_type', 'entry')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($crossing) {
                return [
                    'id' => $crossing->id,
                    'time' => $crossing->created_at->diffForHumans(),
                    'officer' => 'Officer ' . $crossing->officer->last_name,
                    'port' => $crossing->port_of_entry,
                    'action' => $crossing->verification_status === 'valid' ? 'Entry Authorized' : 'Entry Denied',
                    'status' => $crossing->verification_status === 'valid' ? 'success' : 'danger',
                ];
            });

        return response()->json($activity);
    }

    /**
     * Get port activity statistics
     */
    public function getPortActivity(Request $request): JsonResponse
    {
        $timeRange = $request->input('time_range', 'today');
        
        $startDate = match($timeRange) {
            'today' => Carbon::today(),
            'week' => Carbon::now()->startOfWeek(),
            'month' => Carbon::now()->startOfMonth(),
            default => Carbon::today(),
        };

        $portActivity = BorderCrossing::where('created_at', '>=', $startDate)
            ->where('crossing_type', 'entry')
            ->groupBy('port_of_entry')
            ->selectRaw('
                port_of_entry as port,
                COUNT(*) as verifications,
                SUM(CASE WHEN verification_status = "valid" THEN 1 ELSE 0 END) as authorized,
                SUM(CASE WHEN verification_status != "valid" THEN 1 ELSE 0 END) as denied,
                MAX(created_at) > ? as active
            ', [Carbon::now()->subHours(1)])
            ->orderBy('verifications', 'desc')
            ->get();

        return response()->json($portActivity);
    }
}
