<?php

namespace App\Http\Controllers\Api\Border;

use App\Http\Controllers\Controller;
use App\Models\BorderCrossing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class ReportsController extends Controller
{
    /**
     * Get report statistics
     */
    public function getStats(): JsonResponse
    {
        // Mock data for now - in production, track actual report generation
        return response()->json([
            'reports_generated' => 156,
            'avg_generation_time' => '12s',
            'total_downloads' => 892,
            'scheduled_reports' => 8,
        ]);
    }

    /**
     * Get recent reports
     */
    public function getRecentReports(): JsonResponse
    {
        // Mock data - in production, fetch from reports storage
        $reports = [
            [
                'id' => 1,
                'name' => 'Daily Operations - ' . Carbon::today()->format('F j, Y'),
                'date' => 'Today',
                'size' => '2.4 MB',
                'format' => 'PDF',
                'created_at' => Carbon::today()->toISOString(),
            ],
            [
                'id' => 2,
                'name' => 'Weekly Performance - Week ' . Carbon::now()->weekOfYear,
                'date' => 'Yesterday',
                'size' => '5.1 MB',
                'format' => 'PDF',
                'created_at' => Carbon::yesterday()->toISOString(),
            ],
            [
                'id' => 3,
                'name' => 'Officer Activity - ' . Carbon::now()->subMonth()->format('F Y'),
                'date' => '2 days ago',
                'size' => '3.8 MB',
                'format' => 'Excel',
                'created_at' => Carbon::now()->subDays(2)->toISOString(),
            ],
        ];

        return response()->json($reports);
    }

    /**
     * Generate a report
     */
    public function generateReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report_type' => 'required|string|in:daily,weekly,officer_activity,port_statistics,denial_analysis,processing_time',
            'date_range' => 'required|string|in:today,week,month,custom',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'format' => 'required|string|in:pdf,excel,csv',
        ]);

        // Determine date range
        if ($validated['date_range'] === 'custom') {
            $startDate = Carbon::parse($validated['start_date']);
            $endDate = Carbon::parse($validated['end_date']);
        } else {
            $startDate = match($validated['date_range']) {
                'today' => Carbon::today(),
                'week' => Carbon::now()->startOfWeek(),
                'month' => Carbon::now()->startOfMonth(),
                default => Carbon::today(),
            };
            $endDate = Carbon::now();
        }

        // Get data based on report type
        $data = $this->getReportData($validated['report_type'], $startDate, $endDate);

        // In production, generate actual PDF/Excel/CSV file
        // For now, return success with mock file info
        return response()->json([
            'success' => true,
            'message' => 'Report generated successfully',
            'report' => [
                'name' => $this->getReportName($validated['report_type'], $startDate, $endDate),
                'format' => strtoupper($validated['format']),
                'size' => rand(1, 5) . '.' . rand(0, 9) . ' MB',
                'download_url' => '/api/border/reports/download/' . uniqid(),
                'generated_at' => Carbon::now()->toISOString(),
            ],
            'data' => $data,
        ]);
    }

    /**
     * Download a report
     */
    public function downloadReport(string $reportId): JsonResponse
    {
        // In production, fetch and return actual file
        return response()->json([
            'message' => 'Report download functionality coming soon',
            'report_id' => $reportId,
        ]);
    }

    /**
     * Export data
     */
    public function exportData(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_range' => 'required|string|in:today,week,month,custom',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'format' => 'required|string|in:csv,excel,json',
        ]);

        // Determine date range
        if ($validated['date_range'] === 'custom') {
            $startDate = Carbon::parse($validated['start_date']);
            $endDate = Carbon::parse($validated['end_date']);
        } else {
            $startDate = match($validated['date_range']) {
                'today' => Carbon::today(),
                'week' => Carbon::now()->startOfWeek(),
                'month' => Carbon::now()->startOfMonth(),
                default => Carbon::today(),
            };
            $endDate = Carbon::now();
        }

        // Get all border crossings in range
        $data = BorderCrossing::with(['officer:id,first_name,last_name'])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get()
            ->map(function ($crossing) {
                return [
                    'date' => $crossing->created_at->format('Y-m-d H:i:s'),
                    'officer' => $crossing->officer->first_name . ' ' . $crossing->officer->last_name,
                    'port' => $crossing->port_of_entry,
                    'passport_number' => $crossing->passport_number,
                    'nationality' => $crossing->nationality,
                    'status' => $crossing->status,
                    'authorization_type' => $crossing->authorization_type,
                ];
            });

        return response()->json([
            'success' => true,
            'message' => 'Data exported successfully',
            'format' => strtoupper($validated['format']),
            'records' => $data->count(),
            'download_url' => '/api/border/reports/export/' . uniqid(),
        ]);
    }

    /**
     * Get report data based on type
     */
    private function getReportData(string $reportType, Carbon $startDate, Carbon $endDate): array
    {
        return match($reportType) {
            'daily' => $this->getDailyOperationsData($startDate, $endDate),
            'weekly' => $this->getWeeklyPerformanceData($startDate, $endDate),
            'officer_activity' => $this->getOfficerActivityData($startDate, $endDate),
            'port_statistics' => $this->getPortStatisticsData($startDate, $endDate),
            'denial_analysis' => $this->getDenialAnalysisData($startDate, $endDate),
            'processing_time' => $this->getProcessingTimeData($startDate, $endDate),
            default => [],
        };
    }

    private function getDailyOperationsData(Carbon $startDate, Carbon $endDate): array
    {
        $stats = BorderCrossing::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "authorized" THEN 1 ELSE 0 END) as authorized,
                SUM(CASE WHEN status = "denied" THEN 1 ELSE 0 END) as denied
            ')
            ->first();

        return [
            'total_verifications' => $stats->total ?? 0,
            'authorized' => $stats->authorized ?? 0,
            'denied' => $stats->denied ?? 0,
            'success_rate' => $stats->total > 0 ? round(($stats->authorized / $stats->total) * 100, 2) : 0,
        ];
    }

    private function getWeeklyPerformanceData(Carbon $startDate, Carbon $endDate): array
    {
        return $this->getDailyOperationsData($startDate, $endDate);
    }

    private function getOfficerActivityData(Carbon $startDate, Carbon $endDate): array
    {
        $activity = BorderCrossing::with('officer:id,first_name,last_name')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get()
            ->groupBy('officer_id')
            ->map(function ($crossings, $officerId) {
                $officer = $crossings->first()->officer;
                return [
                    'officer' => $officer->first_name . ' ' . $officer->last_name,
                    'total' => $crossings->count(),
                    'authorized' => $crossings->where('status', 'authorized')->count(),
                    'denied' => $crossings->where('status', 'denied')->count(),
                ];
            })
            ->values();

        return ['officers' => $activity];
    }

    private function getPortStatisticsData(Carbon $startDate, Carbon $endDate): array
    {
        $ports = BorderCrossing::whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('port_of_entry')
            ->selectRaw('
                port_of_entry as port,
                COUNT(*) as total,
                SUM(CASE WHEN status = "authorized" THEN 1 ELSE 0 END) as authorized,
                SUM(CASE WHEN status = "denied" THEN 1 ELSE 0 END) as denied
            ')
            ->get();

        return ['ports' => $ports];
    }

    private function getDenialAnalysisData(Carbon $startDate, Carbon $endDate): array
    {
        $denials = BorderCrossing::whereBetween('created_at', [$startDate, $endDate])
            ->where('status', 'denied')
            ->get()
            ->groupBy('denial_reason')
            ->map(function ($group, $reason) {
                return [
                    'reason' => $reason ?? 'Not specified',
                    'count' => $group->count(),
                ];
            })
            ->values();

        return ['denials' => $denials];
    }

    private function getProcessingTimeData(Carbon $startDate, Carbon $endDate): array
    {
        $times = BorderCrossing::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as avg_time,
                MIN(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as min_time,
                MAX(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as max_time
            ')
            ->first();

        return [
            'avg_processing_time' => round($times->avg_time ?? 0) . 's',
            'min_processing_time' => round($times->min_time ?? 0) . 's',
            'max_processing_time' => round($times->max_time ?? 0) . 's',
        ];
    }

    private function getReportName(string $reportType, Carbon $startDate, Carbon $endDate): string
    {
        $typeName = match($reportType) {
            'daily' => 'Daily Operations',
            'weekly' => 'Weekly Performance',
            'officer_activity' => 'Officer Activity',
            'port_statistics' => 'Port Statistics',
            'denial_analysis' => 'Denial Analysis',
            'processing_time' => 'Processing Time',
            default => 'Report',
        };

        return $typeName . ' - ' . $startDate->format('M j, Y') . ' to ' . $endDate->format('M j, Y');
    }
}
