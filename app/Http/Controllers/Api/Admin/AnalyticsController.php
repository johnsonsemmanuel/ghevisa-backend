<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Payment;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function __construct(
        protected AnalyticsService $analyticsService
    ) {}

    /**
     * Get comprehensive dashboard analytics.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $days = $request->query('days', 30);

        return response()->json([
            'analytics' => $this->analyticsService->getDashboardAnalytics((int) $days),
        ]);
    }

    /**
     * Get officer performance metrics.
     */
    public function officerPerformance(Request $request): JsonResponse
    {
        $days = $request->query('days', 30);

        return response()->json([
            'officers' => $this->analyticsService->getOfficerPerformance((int) $days),
        ]);
    }

    /**
     * Financial Reports - Revenue breakdown
     */
    public function financialReports(Request $request): JsonResponse
    {
        $startDate = $request->query('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->query('end_date', now()->toDateString());

        // Total revenue
        $totalRevenue = Payment::where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->sum('amount');

        // Revenue by payment method
        $revenueByMethod = Payment::where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->select('payment_option', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('payment_option')
            ->get();

        // Revenue by day
        $revenueByDay = Payment::where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        // Revenue by visa type
        $revenueByVisaType = Payment::where('payments.status', 'completed')
            ->whereBetween('payments.created_at', [$startDate, $endDate . ' 23:59:59'])
            ->join('applications', 'payments.application_id', '=', 'applications.id')
            ->join('visa_types', 'applications.visa_type_id', '=', 'visa_types.id')
            ->select('visa_types.name', DB::raw('SUM(payments.amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('visa_types.name')
            ->get();

        // Transaction statistics
        $transactionStats = [
            'total_transactions' => Payment::whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])->count(),
            'successful' => Payment::where('status', 'completed')->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])->count(),
            'pending' => Payment::where('status', 'pending')->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])->count(),
            'failed' => Payment::where('status', 'failed')->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])->count(),
        ];

        return response()->json([
            'period' => ['start' => $startDate, 'end' => $endDate],
            'total_revenue' => round($totalRevenue, 2),
            'currency' => 'GHS',
            'revenue_by_method' => $revenueByMethod,
            'revenue_by_day' => $revenueByDay,
            'revenue_by_visa_type' => $revenueByVisaType,
            'transaction_stats' => $transactionStats,
        ]);
    }

    /**
     * Country Analytics - Applicants by country, visa issued vs denied
     */
    public function countryAnalytics(Request $request): JsonResponse
    {
        $startDate = $request->query('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->query('end_date', now()->toDateString());

        // Applications by nationality
        $applicationsByCountry = Application::whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->select('nationality_encrypted as nationality', DB::raw('COUNT(*) as total'))
            ->groupBy('nationality_encrypted')
            ->orderByDesc('total')
            ->limit(50)
            ->get()
            ->map(function ($item) {
                return [
                    'country_code' => $item->nationality,
                    'total' => $item->total,
                ];
            });

        // Visa issued by country
        $issuedByCountry = Application::where('status', 'issued')
            ->whereBetween('decided_at', [$startDate, $endDate . ' 23:59:59'])
            ->select('nationality_encrypted as nationality', DB::raw('COUNT(*) as issued'))
            ->groupBy('nationality_encrypted')
            ->orderByDesc('issued')
            ->limit(50)
            ->get()
            ->map(function ($item) {
                return [
                    'country_code' => $item->nationality,
                    'issued' => $item->issued,
                ];
            });

        // Visa denied by country
        $deniedByCountry = Application::where('status', 'denied')
            ->whereBetween('decided_at', [$startDate, $endDate . ' 23:59:59'])
            ->select('nationality_encrypted as nationality', DB::raw('COUNT(*) as denied'))
            ->groupBy('nationality_encrypted')
            ->orderByDesc('denied')
            ->limit(50)
            ->get()
            ->map(function ($item) {
                return [
                    'country_code' => $item->nationality,
                    'denied' => $item->denied,
                ];
            });

        // Summary stats
        $summary = [
            'total_applications' => Application::whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])->count(),
            'total_issued' => Application::where('status', 'issued')->whereBetween('decided_at', [$startDate, $endDate . ' 23:59:59'])->count(),
            'total_denied' => Application::where('status', 'denied')->whereBetween('decided_at', [$startDate, $endDate . ' 23:59:59'])->count(),
            'total_pending' => Application::whereIn('status', ['submitted', 'under_review', 'pending_approval'])->count(),
            'unique_countries' => Application::whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
                ->distinct('nationality_encrypted')->count('nationality_encrypted'),
        ];

        // Top 10 countries with full breakdown
        $topCountries = Application::whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->select(
                'nationality_encrypted as nationality',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN status = "issued" THEN 1 ELSE 0 END) as issued'),
                DB::raw('SUM(CASE WHEN status = "denied" THEN 1 ELSE 0 END) as denied'),
                DB::raw('SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) as approved'),
                DB::raw('SUM(CASE WHEN status IN ("submitted", "under_review", "pending_approval") THEN 1 ELSE 0 END) as pending')
            )
            ->groupBy('nationality_encrypted')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                $approvalRate = $item->total > 0 
                    ? round((($item->issued + $item->approved) / $item->total) * 100, 1) 
                    : 0;
                return [
                    'country_code' => $item->nationality,
                    'total' => $item->total,
                    'issued' => $item->issued,
                    'approved' => $item->approved,
                    'denied' => $item->denied,
                    'pending' => $item->pending,
                    'approval_rate' => $approvalRate,
                ];
            });

        return response()->json([
            'period' => ['start' => $startDate, 'end' => $endDate],
            'summary' => $summary,
            'top_countries' => $topCountries,
            'applications_by_country' => $applicationsByCountry,
            'issued_by_country' => $issuedByCountry,
            'denied_by_country' => $deniedByCountry,
        ]);
    }

    /**
     * Export financial report to CSV
     */
    public function exportFinancialCsv(Request $request): StreamedResponse
    {
        $startDate = $request->query('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->query('end_date', now()->toDateString());

        $payments = Payment::where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->with('application.visaType')
            ->orderBy('created_at', 'desc')
            ->get();

        $filename = 'financial_report_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($payments) {
            $handle = fopen('php://output', 'w');
            
            // Header
            fputcsv($handle, [
                'Date', 'Reference', 'Application ID', 'Visa Type', 'Amount', 'Currency',
                'Payment Method', 'Bank Reference', 'Status'
            ]);

            foreach ($payments as $payment) {
                fputcsv($handle, [
                    $payment->created_at->format('Y-m-d H:i:s'),
                    $payment->merchant_ref ?? $payment->transaction_reference,
                    $payment->application->reference_number ?? '',
                    $payment->application->visaType->name ?? '',
                    $payment->amount,
                    $payment->currency ?? 'GHS',
                    $payment->payment_option ?? $payment->payment_method ?? '',
                    $payment->bank_ref ?? '',
                    $payment->status,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Export country analytics to CSV
     */
    public function exportCountryCsv(Request $request): StreamedResponse
    {
        $startDate = $request->query('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->query('end_date', now()->toDateString());

        $data = Application::whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->select(
                'nationality_encrypted as nationality',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN status = "issued" THEN 1 ELSE 0 END) as issued'),
                DB::raw('SUM(CASE WHEN status = "denied" THEN 1 ELSE 0 END) as denied'),
                DB::raw('SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) as approved'),
                DB::raw('SUM(CASE WHEN status IN ("submitted", "under_review", "pending_approval") THEN 1 ELSE 0 END) as pending')
            )
            ->groupBy('nationality_encrypted')
            ->orderByDesc('total')
            ->get();

        $filename = 'country_analytics_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');
            
            fputcsv($handle, ['Country Code', 'Total Applications', 'Issued', 'Approved', 'Denied', 'Pending', 'Approval Rate (%)']);

            foreach ($data as $row) {
                $approvalRate = $row->total > 0 
                    ? round((($row->issued + $row->approved) / $row->total) * 100, 1) 
                    : 0;
                fputcsv($handle, [
                    $row->nationality,
                    $row->total,
                    $row->issued,
                    $row->approved,
                    $row->denied,
                    $row->pending,
                    $approvalRate,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Export applications to CSV.
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $filters = $request->only(['start_date', 'end_date', 'status']);

        $csv = $this->analyticsService->exportApplicationsCsv($filters);
        $filename = 'applications_export_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
