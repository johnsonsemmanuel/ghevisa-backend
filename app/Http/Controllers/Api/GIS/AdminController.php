<?php

namespace App\Http\Controllers\Api\GIS;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\EtaApplication;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $applications = Application::query()->where('assigned_agency', 'gis');

        $metrics = [
            'total_applications' => (clone $applications)->count(),
            'review_queue' => (clone $applications)->where('current_queue', 'review_queue')->count(),
            'approval_queue' => (clone $applications)->where('current_queue', 'approval_queue')->count(),
            'under_review' => (clone $applications)->where('status', 'under_review')->count(),
            'pending_approval' => (clone $applications)->where('status', 'pending_approval')->count(),
            'approved' => (clone $applications)->whereIn('status', ['approved', 'issued'])->count(),
            'denied' => (clone $applications)->where('status', 'denied')->count(),
        ];

        $metrics['completed_payments'] = Payment::where('status', 'completed')
            ->whereHas('application', function ($query) {
                $query->where('assigned_agency', 'gis');
            })
            ->sum('amount');

        $metrics['active_officers'] = User::whereIn('role', ['gis_officer', 'gis_reviewer', 'gis_approver', 'gis_admin'])
            ->where('is_active', true)
            ->count();

        return response()->json(['metrics' => $metrics]);
    }

    public function applicants(Request $request): JsonResponse
    {
        $query = User::query()
            ->whereIn('role', ['applicant', 'APPLICANT'])
            ->whereHas('applications', function ($query) {
                $query->where('assigned_agency', 'gis');
            })
            ->withCount(['applications as gis_applications_count' => function ($query) {
                $query->where('assigned_agency', 'gis');
            }])
            ->with(['applications' => function ($query) {
                $query->select('id', 'user_id', 'reference_number', 'status', 'assigned_agency', 'created_at')
                    ->where('assigned_agency', 'gis')
                    ->latest();
            }]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->query('per_page', 15);
        $applicants = $query->orderByDesc('updated_at')->paginate($perPage);

        return response()->json($applicants);
    }

    public function officers(Request $request): JsonResponse
    {
        $query = User::query()
            ->whereIn('role', ['gis_officer', 'gis_reviewer', 'gis_approver', 'gis_admin'])
            ->withCount(['assignedApplications as assigned_cases_count' => function ($query) {
                $query->where('assigned_agency', 'gis');
            }]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $officers = $query->orderBy('role')->orderBy('first_name')->paginate((int) $request->query('per_page', 15));

        return response()->json($officers);
    }

    public function applications(Request $request): JsonResponse
    {
        $query = Application::with(['visaType', 'user:id,first_name,last_name,email'])
            ->where('assigned_agency', 'gis');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($queue = $request->query('queue')) {
            $query->where('current_queue', $queue);
        }

        if ($search = $request->query('search')) {
            $query->where('reference_number', 'like', "%{$search}%");
        }

        $applications = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 15));

        return response()->json($applications);
    }

    /**
     * Get high-level ETA metrics for GIS admins.
     * Read-only visibility of all ETA applications (not part of GIS review queues).
     */
    public function etaOverview(Request $request): JsonResponse
    {
        $eta = EtaApplication::query();

        $metrics = [
            'total_eta'    => (clone $eta)->count(),
            'pending'      => (clone $eta)->where('status', 'pending')->count(),
            'approved'     => (clone $eta)->where('status', 'approved')->count(),
            'rejected'     => (clone $eta)->where('status', 'rejected')->count(),
            'expired'      => (clone $eta)->where('status', 'approved')->whereNotNull('expires_at')->where('expires_at', '<', now())->count(),
            'completed_payments' => (clone $eta)->where('payment_status', 'completed')->sum('fee_amount'),
        ];

        return response()->json(['metrics' => $metrics]);
    }

    /**
     * List ETA applications for GIS admins.
     * This does not affect or use GIS review/approval queues.
     */
    public function etaApplications(Request $request): JsonResponse
    {
        $query = EtaApplication::query();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($paymentStatus = $request->query('payment_status')) {
            $query->where('payment_status', $paymentStatus);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $etas = $query
            ->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 15));

        return response()->json($etas);
    }
}
