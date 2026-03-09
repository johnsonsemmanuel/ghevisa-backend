<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\User;
use App\Services\SlaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    public function __construct(
        protected SlaService $slaService,
    ) {}

    /**
     * Get overall analytics overview.
     */
    public function overview(): JsonResponse
    {
        return response()->json([
            'applications' => [
                'total'        => Application::count(),
                'draft'        => Application::where('status', 'draft')->count(),
                'submitted'    => Application::where('status', 'submitted')->count(),
                'under_review' => Application::where('status', 'under_review')->count(),
                'approved'     => Application::where('status', 'approved')->count(),
                'denied'       => Application::where('status', 'denied')->count(),
                'escalated'    => Application::where('status', 'escalated')->count(),
            ],
            'payments' => [
                'total_collected' => Payment::where('status', 'completed')->sum('amount'),
                'completed'       => Payment::where('status', 'completed')->count(),
                'failed'          => Payment::where('status', 'failed')->count(),
                'pending'         => Payment::where('status', 'pending')->count(),
            ],
            'sla'   => $this->slaService->getStats(),
            'users' => [
                'total_applicants'  => User::where('role', 'applicant')->count(),
                'total_officers'    => User::whereIn('role', ['gis_officer', 'gis_reviewer', 'gis_approver', 'gis_admin', 'mfa_reviewer', 'mfa_approver', 'mfa_admin', 'admin'])->count(),
                'active_officers'   => User::whereIn('role', ['gis_officer', 'gis_reviewer', 'gis_approver', 'gis_admin', 'mfa_reviewer', 'mfa_approver', 'mfa_admin', 'admin'])->where('is_active', true)->count(),
            ],
        ]);
    }

    /**
     * Get application volume over time (daily for last 30 days).
     */
    public function applicationVolume(Request $request): JsonResponse
    {
        $days = $request->query('days', 30);

        $data = Application::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json(['volume' => $data]);
    }

    /**
     * Get a single application with all details (admin view).
     */
    public function showApplication(Application $application): JsonResponse
    {
        $application->load([
            'visaType',
            'documents',
            'payment',
            'statusHistory',
            'internalNotes.user:id,first_name,last_name',
            'user:id,first_name,last_name,email',
            'assignedOfficer:id,first_name,last_name',
            'reviewingOfficer:id,first_name,last_name,email',
            'approvalOfficer:id,first_name,last_name,email',
            'riskAssessment',
        ]);

        // SEC-04: Audit log data access
        $application->logAccess('viewed_by_admin');

        return response()->json([
            'application' => $application,
        ]);
    }

    /**
     * Download or view a document (admin view).
     */
    public function downloadDocument(Application $application, \App\Models\ApplicationDocument $document)
    {
        if ($document->application_id !== $application->id) {
            abort(404, 'Document not found for this application.');
        }

        if (!Storage::disk('local')->exists($document->stored_path)) {
            abort(404, __('document.not_found'));
        }

        return Storage::disk('local')->download(
            $document->stored_path,
            $document->original_filename,
            ['Content-Type' => $document->mime_type]
        );
    }

    /**
     * Get payment report.
     */
    public function paymentReport(Request $request): JsonResponse
    {
        $days = $request->query('days', 30);

        $data = Payment::selectRaw('DATE(created_at) as date, SUM(amount) as total, COUNT(*) as count, status')
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('date', 'status')
            ->orderBy('date')
            ->get();

        return response()->json(['payments' => $data]);
    }

    /**
     * Get SLA compliance report.
     */
    public function slaReport(): JsonResponse
    {
        $stats = $this->slaService->getStats();
        $approaching = $this->slaService->getApproachingBreach(12);
        $breached = $this->slaService->getBreached();

        return response()->json([
            'stats'      => $stats,
            'approaching' => $approaching->map(fn($a) => [
                'reference'      => $a->reference_number,
                'tier'           => $a->tier,
                'agency'         => $a->assigned_agency,
                'hours_remaining'=> $a->slaHoursRemaining(),
            ]),
            'breached' => $breached->map(fn($a) => [
                'reference'     => $a->reference_number,
                'tier'          => $a->tier,
                'agency'        => $a->assigned_agency,
                'breached_by'   => abs($a->slaHoursRemaining()) . ' hours',
            ]),
        ]);
    }

    /**
     * Get audit log entries with filters.
     */
    public function auditLogs(Request $request): JsonResponse
    {
        $query = AuditLog::with('user:id,first_name,last_name,role');

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($action = $request->query('action')) {
            $query->where('action', 'like', '%' . addcslashes($action, '%_') . '%');
        }

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to);
        }

        $logs = $query->orderBy('created_at', 'desc')->paginate(50);

        return response()->json($logs);
    }

    /**
     * Get all applications with pagination and filters.
     */
    public function applications(Request $request): JsonResponse
    {
        $query = Application::with(['visaType', 'payment', 'user:id,first_name,last_name,email']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('reference_number', 'like', "%{$search}%")
                  ->orWhere('first_name_encrypted', 'like', "%{$search}%")
                  ->orWhere('last_name_encrypted', 'like', "%{$search}%");
            });
        }

        if ($agency = $request->query('agency')) {
            $query->where('assigned_agency', $agency);
        }

        $applications = $query->orderBy('created_at', 'desc')->paginate($request->query('per_page', 20));

        return response()->json($applications);
    }

    /**
     * Get all payments with pagination and filters.
     */
    public function payments(Request $request): JsonResponse
    {
        $query = Payment::with(['application:id,reference_number,first_name_encrypted,last_name_encrypted']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $payments = $query->orderBy('created_at', 'desc')->paginate($request->query('per_page', 20));

        return response()->json($payments);
    }
}
