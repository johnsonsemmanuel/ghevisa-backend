<?php

namespace App\Http\Controllers\Api\MFA;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\EtaApplication;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    protected function scopedApplications(Request $request)
    {
        $query = Application::query()->where('assigned_agency', 'mfa');

        $user = $request->user();
        if ($user && $user->mfa_mission_id && !$user->isMfaAdmin()) {
            $query->where('owner_mission_id', $user->mfa_mission_id);
        }

        return $query;
    }

    public function overview(Request $request): JsonResponse
    {
        $applications = $this->scopedApplications($request);

        $metrics = [
            'total_applications' => (clone $applications)->count(),
            'review_queue' => (clone $applications)->where('current_queue', 'review_queue')->count(),
            'approval_queue' => (clone $applications)->where('current_queue', 'approval_queue')->count(),
            'under_review' => (clone $applications)->where('status', 'under_review')->count(),
            'pending_approval' => (clone $applications)->where('status', 'pending_approval')->count(),
            'escalated' => (clone $applications)->where('status', 'escalated')->count(),
            'approved' => (clone $applications)->whereIn('status', ['approved', 'issued'])->count(),
            'denied' => (clone $applications)->where('status', 'denied')->count(),
        ];

        $metrics['completed_payments'] = Payment::where('status', 'completed')
            ->whereHas('application', function ($query) use ($request) {
                $query->where('assigned_agency', 'mfa');
                $user = $request->user();
                if ($user && $user->mfa_mission_id && !$user->isMfaAdmin()) {
                    $query->where('owner_mission_id', $user->mfa_mission_id);
                }
            })
            ->sum('amount');

        $metrics['active_officers'] = User::whereIn('role', ['mfa_reviewer', 'mfa_approver', 'mfa_admin'])
            ->where('is_active', true)
            ->when($request->user()?->mfa_mission_id && !$request->user()->isMfaAdmin(), function ($query) use ($request) {
                $query->where('mfa_mission_id', $request->user()->mfa_mission_id);
            })
            ->count();

        return response()->json(['metrics' => $metrics]);
    }

    public function applicants(Request $request): JsonResponse
    {
        $query = User::query()
            ->whereIn('role', ['applicant', 'APPLICANT'])
            ->whereHas('applications', function ($query) use ($request) {
                $query->where('assigned_agency', 'mfa');
                $user = $request->user();
                if ($user && $user->mfa_mission_id && !$user->isMfaAdmin()) {
                    $query->where('owner_mission_id', $user->mfa_mission_id);
                }
            })
            ->withCount(['applications as mfa_applications_count' => function ($query) use ($request) {
                $query->where('assigned_agency', 'mfa');
                $user = $request->user();
                if ($user && $user->mfa_mission_id && !$user->isMfaAdmin()) {
                    $query->where('owner_mission_id', $user->mfa_mission_id);
                }
            }])
            ->with(['applications' => function ($query) use ($request) {
                $query->select('id', 'user_id', 'reference_number', 'status', 'assigned_agency', 'owner_mission_id', 'created_at')
                    ->where('assigned_agency', 'mfa')
                    ->latest();
                $user = $request->user();
                if ($user && $user->mfa_mission_id && !$user->isMfaAdmin()) {
                    $query->where('owner_mission_id', $user->mfa_mission_id);
                }
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
            ->whereIn('role', ['mfa_reviewer', 'mfa_approver', 'mfa_admin'])
            ->withCount(['assignedApplications as assigned_cases_count' => function ($query) use ($request) {
                $query->where('assigned_agency', 'mfa');
                $user = $request->user();
                if ($user && $user->mfa_mission_id && !$user->isMfaAdmin()) {
                    $query->where('owner_mission_id', $user->mfa_mission_id);
                }
            }])
            ->when($request->user()?->mfa_mission_id && !$request->user()->isMfaAdmin(), function ($query) use ($request) {
                $query->where('mfa_mission_id', $request->user()->mfa_mission_id);
            });

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

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
        $query = $this->scopedApplications($request)
            ->with(['visaType', 'user:id,first_name,last_name,email', 'payment']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($queue = $request->query('queue')) {
            $query->where('current_queue', $queue);
        }

        if ($search = $request->query('search')) {
            $query->where('reference_number', 'like', "%{$search}%");
        }

        if ($missionId = $request->query('mission_id')) {
            $query->where('owner_mission_id', $missionId);
        }

        $applications = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 15));

        return response()->json($applications);
    }

    /**
     * Get high-level ETA metrics for MFA admins.
     * This provides read-only visibility; ETA is not part of MFA review queues.
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
     * List ETA applications for MFA admins.
     * These are ETA-only records, separate from full visa applications.
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
