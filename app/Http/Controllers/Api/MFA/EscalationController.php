<?php

namespace App\Http\Controllers\Api\MFA;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\InternalNote;
use App\Models\MfaMission;
use App\Models\ReasonCode;
use App\Services\ApplicationRoutingService;
use App\Services\ApplicationService;
use App\Services\EVisaPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EscalationController extends Controller
{
    public function __construct(
        protected ApplicationService $applicationService,
        protected ApplicationRoutingService $routingService,
        protected EVisaPdfService $pdfService,
    ) {}

    /**
     * Build a base query scoped to MFA applications.
     * If the officer is assigned to a specific mission, only show that mission's applications.
     * Admins see all MFA applications.
     */
    protected function mfaBaseQuery(?Request $request = null, ?string $queue = null): \Illuminate\Database\Eloquent\Builder
    {
        $query = Application::where('assigned_agency', 'mfa');

        $user = $request?->user() ?? auth()->user();
        
        // Mission-based filtering for non-admin MFA officers
        if ($user && $user->mfa_mission_id && !$user->isMfaAdmin()) {
            $query->where('owner_mission_id', $user->mfa_mission_id);
        }

        // Queue filtering
        if ($queue) {
            $query->where('current_queue', $queue);
        }

        return $query;
    }

    /**
     * Ensure the current MFA officer has access to this application's mission.
     * Returns a 403 response if denied, or null if access is granted.
     */
    protected function ensureMissionAccess(Request $request, Application $application): ?JsonResponse
    {
        if ($application->assigned_agency !== 'mfa') {
            return response()->json(['message' => __('case.not_mfa_case')], 422);
        }

        $user = $request->user();
        
        // Admin users can access all missions
        if ($user->isMfaAdmin() || $user->isAdmin()) {
            return null;
        }
        
        // Check mission access for MFA officers
        if ($user->mfa_mission_id && $application->owner_mission_id !== $user->mfa_mission_id) {
            return response()->json(['message' => 'This application is not assigned to your mission'], 403);
        }

        return null;
    }

    /**
     * Get the MFA escalation inbox.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->mfaBaseQuery($request)
            ->with(['visaType', 'assignedOfficer:id,first_name,last_name', 'riskAssessment', 'payment']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($queue = $request->query('queue')) {
            $query->where('current_queue', $queue);
        }

        if ($mission = $request->query('mission')) {
            $query->where('mfa_mission_id', $mission);
        }

        $applications = $query->orderByRaw("
            CASE
                WHEN status = '" . \App\Models\Application::STATUS_PENDING_APPROVAL . "' THEN 1
                WHEN status = '" . \App\Models\Application::STATUS_ESCALATED . "' THEN 2
                WHEN status = '" . \App\Models\Application::STATUS_UNDER_REVIEW . "' THEN 3
                ELSE 4
            END
        ")
        ->orderByRaw("
            CASE 
                WHEN sla_deadline IS NULL THEN 999999999
                ELSE julianday(sla_deadline) - julianday('now')
            END ASC
        ")
        ->orderByRaw("
            CASE 
                WHEN EXISTS (
                    SELECT 1 FROM risk_assessments ra 
                    WHERE ra.application_id = applications.id 
                    AND ra.risk_score IS NOT NULL
                ) THEN (
                    SELECT ra.risk_score FROM risk_assessments ra 
                    WHERE ra.application_id = applications.id 
                    ORDER BY ra.created_at DESC LIMIT 1
                )
                ELSE 0
            END DESC
        ")
        ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($applications);
    }

    /**
     * Get a single escalated case with full details.
     * FIX #8: Added tier clearance verification.
     */
    public function show(Request $request, Application $application): JsonResponse
    {
        if ($denied = $this->ensureMissionAccess($request, $application)) return $denied;

        // FIX #8: Tier clearance check
        if (!$request->user()->hasTierClearance($application->tier ?? 1)) {
            return response()->json([
                'message' => 'You do not have clearance to view this tier ' . ($application->tier ?? 1) . ' application'
            ], 403);
        }

        $application->load([
            'visaType',
            'documents',
            'statusHistory.changedByUser',
            'internalNotes.user',
            'payment',
            'user:id,first_name,last_name,email',
            'assignedOfficer:id,first_name,last_name,email',
            'reviewingOfficer:id,first_name,last_name,email',
            'approvalOfficer:id,first_name,last_name,email',
            'riskAssessment',
        ]);

        // SEC-04: Audit log data access
        $application->logAccess('viewed_by_mfa_officer', [
            'officer_id' => $request->user()->id,
            'officer_name' => $request->user()->full_name,
            'mission_id' => $request->user()->mfa_mission_id,
            'tier' => $application->tier,
        ]);

        return response()->json([
            'application'    => $application,
            'sla_hours_left' => $application->slaHoursRemaining(),
            'is_within_sla'  => $application->isWithinSla(),
        ]);
    }

    /**
     * Submit application for approval (two-step process for MFA).
     * Reviewer submits, then Senior Approver approves.
     * Requires: applications.review permission
     * FIX #8: Added tier clearance check.
     */
    public function submitForApproval(Request $request, Application $application): JsonResponse
    {
        // FIX #8: Use new canReview() method with mission access
        if (!$request->user()->canReview($application)) {
            return response()->json([
                'message' => 'You do not have permission to review this application. Check your mission assignment and tier clearance.'
            ], 403);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        if ($denied = $this->ensureMissionAccess($request, $application)) return $denied;

        if (!in_array($application->status, [\App\Models\Application::STATUS_ESCALATED, \App\Models\Application::STATUS_UNDER_REVIEW])) {
            return response()->json(['message' => __('case.invalid_status_for_approval')], 422);
        }

        $application->update([
            'reviewed_by_id' => $request->user()->id,
            'reviewed_at' => now(),
            'reviewing_officer_id' => $request->user()->id,
            'current_queue' => \App\Models\Application::QUEUE_APPROVAL,
        ]);

        $this->applicationService->changeStatus(
            $application, 
            \App\Models\Application::STATUS_PENDING_APPROVAL, 
            $validated['notes'] ?? 'Submitted for approval by MFA reviewer'
        );

        return response()->json([
            'message'     => __('case.submitted_for_approval'),
            'application' => $application->fresh(),
        ]);
    }

    /**
     * Approve an application (final approval).
     * Requires: applications.approve permission (Approval Officers only)
     * FIX #8: Added tier clearance check.
     */
    public function approve(Request $request, Application $application): JsonResponse
    {
        // FIX #8: Use new canApprove() method with tier clearance + mission access
        if (!$request->user()->canApprove($application)) {
            return response()->json([
                'message' => 'You do not have permission to approve this application. Check your mission assignment and tier clearance.'
            ], 403);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        if ($denied = $this->ensureMissionAccess($request, $application)) return $denied;

        // Only pending_approval applications can be approved (two-step enforcement)
        if ($application->status !== \App\Models\Application::STATUS_PENDING_APPROVAL) {
            return response()->json(['message' => __('case.invalid_status_for_approval')], 422);
        }

        $application->update([
            'approval_officer_id' => $request->user()->id,
            'approval_started_at' => $application->approval_started_at ?? now(),
            'approval_completed_at' => now(),
        ]);

        $this->applicationService->changeStatus($application, \App\Models\Application::STATUS_APPROVED, $validated['notes'] ?? 'Approved by MFA');

        // FIX-17/ARCH-03: Queue PDF generation instead of synchronous
        \App\Jobs\GenerateEVisaPdf::dispatch($application);

        // Notification is already dispatched by changeStatus()

        return response()->json([
            'message'     => __('case.approved'),
            'application' => $application->fresh(),
        ]);
    }

    /**
     * Deny an application.
     * Requires: applications.deny permission (Approval Officers only)
     * FIX #8: Added tier clearance check.
     */
    public function deny(Request $request, Application $application): JsonResponse
    {
        // FIX #8: Use new canApprove() method with tier clearance + mission access
        if (!$request->user()->canApprove($application)) {
            return response()->json([
                'message' => 'You do not have permission to deny this application. Check your mission assignment and tier clearance.'
            ], 403);
        }

        $validated = $request->validate([
            'reason_codes' => 'required|array|min:1',
            'reason_codes.*' => 'required|string|exists:reason_codes,code',
            'notes' => 'required|string|max:2000',
        ]);

        if ($denied = $this->ensureMissionAccess($request, $application)) return $denied;

        // Only pending_approval applications can be denied (two-step enforcement)
        if ($application->status !== \App\Models\Application::STATUS_PENDING_APPROVAL) {
            return response()->json(['message' => 'Application must be in pending_approval status to deny'], 422);
        }

        $application->update([
            'approval_officer_id' => $request->user()->id,
            'approval_started_at' => $application->approval_started_at ?? now(),
            'approval_completed_at' => now(),
        ]);

        // Explicit audit log for denial action
        $application->logAccess('denied_by_officer', [
            'officer_id' => $request->user()->id,
            'officer_name' => $request->user()->full_name,
            'reason_codes' => $validated['reason_codes'],
            'notes' => $validated['notes'],
        ]);

        $this->applicationService->changeStatus($application, \App\Models\Application::STATUS_DENIED, $validated['notes']);

        return response()->json([
            'message'     => __('case.denied'),
            'application' => $application->fresh(),
        ]);
    }

    /**
     * Issue a visa for an approved application.
     * Transition: APPROVED → ISSUED
     * Requires: applications.approve permission (Approval Officers only)
     */
    public function issueVisa(Request $request, Application $application): JsonResponse
    {
        if (!$request->user()->canApproveApplications()) {
            return response()->json(['message' => 'You do not have permission to issue visas'], 403);
        }

        if ($denied = $this->ensureMissionAccess($request, $application)) return $denied;

        if ($application->status !== \App\Models\Application::STATUS_APPROVED) {
            return response()->json(['message' => 'Application must be approved before issuing visa'], 422);
        }

        // FIX-17/ARCH-03: Queue PDF generation if not already generated
        if (!$application->evisa_file_path) {
            \App\Jobs\GenerateEVisaPdf::dispatch($application);
        }

        $this->applicationService->changeStatus($application, \App\Models\Application::STATUS_ISSUED, 'Visa issued');

        return response()->json([
            'message'     => 'Visa issued successfully',
            'application' => $application->fresh(),
        ]);
    }

    /**
     * Return an application back to GIS for further review.
     */
    public function returnToGis(Request $request, Application $application): JsonResponse
    {
        if ($denied = $this->ensureMissionAccess($request, $application)) return $denied;

        $validated = $request->validate([
            'notes' => 'required|string|max:2000',
        ]);

        $this->routingService->returnToGis($application);

        InternalNote::create([
            'application_id' => $application->id,
            'user_id'        => $request->user()->id,
            'content'        => 'Returned to GIS: ' . $validated['notes'],
            'is_private'     => false,
        ]);

        return response()->json([
            'message'     => __('case.returned'),
            'application' => $application->fresh(),
        ]);
    }

    /**
     * Assign an escalated case to the current MFA reviewer.
     */
    public function assignToSelf(Request $request, Application $application): JsonResponse
    {
        if ($denied = $this->ensureMissionAccess($request, $application)) return $denied;

        $application->update(['assigned_officer_id' => $request->user()->id]);

        return response()->json([
            'message'     => __('case.assigned'),
            'application' => $application->fresh(),
        ]);
    }

    /**
     * Get MFA dashboard metrics.
     */
    public function metrics(Request $request): JsonResponse
    {
        $base = $this->mfaBaseQuery($request);

        return response()->json([
            'total_escalated'   => (clone $base)->count(),
            'pending_decision'  => (clone $base)->whereIn('status', [\App\Models\Application::STATUS_ESCALATED, \App\Models\Application::STATUS_UNDER_REVIEW])->count(),
            'pending_approval'  => (clone $base)->where('status', \App\Models\Application::STATUS_PENDING_APPROVAL)->count(),
            'approved_today'    => (clone $base)->whereIn('status', [\App\Models\Application::STATUS_APPROVED, \App\Models\Application::STATUS_ISSUED])->whereDate('decided_at', today())->count(),
            'denied_today'      => (clone $base)->where('status', \App\Models\Application::STATUS_DENIED)->whereDate('decided_at', today())->count(),
            'issued_today'      => (clone $base)->where('status', \App\Models\Application::STATUS_ISSUED)->whereDate('updated_at', today())->count(),
            'total_approved'    => (clone $base)->whereIn('status', [\App\Models\Application::STATUS_APPROVED, \App\Models\Application::STATUS_ISSUED])->count(),
            'total_denied'      => (clone $base)->where('status', \App\Models\Application::STATUS_DENIED)->count(),
            'sla_breaches'      => (clone $base)->whereNotNull('sla_deadline')
                ->whereNotIn('status', [\App\Models\Application::STATUS_APPROVED, \App\Models\Application::STATUS_DENIED, \App\Models\Application::STATUS_ISSUED, \App\Models\Application::STATUS_CANCELLED])
                ->where('sla_deadline', '<', now())->count(),
            'review_queue'      => (clone $base)->where(function ($q) {
                $q->where('current_queue', \App\Models\Application::QUEUE_REVIEW)
                  ->orWhere(function ($q2) {
                      $q2->whereNull('current_queue')
                         ->whereIn('status', [\App\Models\Application::STATUS_ESCALATED, \App\Models\Application::STATUS_UNDER_REVIEW]);
                  });
            })->count(),
            'approval_queue'    => (clone $base)->where(function ($q) {
                $q->where('current_queue', \App\Models\Application::QUEUE_APPROVAL)
                  ->orWhere(function ($q2) {
                      $q2->whereNull('current_queue')
                         ->where('status', \App\Models\Application::STATUS_PENDING_APPROVAL);
                  });
            })->count(),
        ]);
    }

    /**
     * Get list of MFA missions for filtering.
     */
    public function missions(): JsonResponse
    {
        $missions = MfaMission::active()
            ->select('id', 'code', 'name', 'city', 'country_name')
            ->orderBy('name')
            ->get();

        return response()->json(['missions' => $missions]);
    }

    /**
     * Get reason codes for officer decisions.
     */
    public function reasonCodes(Request $request): JsonResponse
    {
        $query = ReasonCode::active();

        if ($actionType = $request->query('action_type')) {
            $query->forAction($actionType);
        }

        return response()->json([
            'reason_codes' => $query->orderBy('sort_order')->get(),
        ]);
    }

    /**
     * Download/preview a document for an escalated application.
     */
    public function downloadDocument(Request $request, Application $application, \App\Models\ApplicationDocument $document): \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\JsonResponse
    {
        if ($denied = $this->ensureMissionAccess($request, $application)) return $denied;

        if ($document->application_id !== $application->id) {
            return response()->json(['message' => __('case.document_not_found')], 404);
        }

        $path = storage_path('app/' . $document->stored_path);
        
        if (!file_exists($path)) {
            return response()->json(['message' => 'Document file not found'], 404);
        }

        return response()->streamDownload(function () use ($path) {
            readfile($path);
        }, $document->original_filename, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'inline; filename="' . $document->original_filename . '"',
        ]);
    }

    /**
     * Request additional information from applicant.
     * SECURITY: Verify application belongs to MFA queue.
     */
    public function requestInfo(Request $request, Application $application): JsonResponse
    {
        if ($denied = $this->ensureMissionAccess($request, $application)) return $denied;

        if (!in_array($application->status, [\App\Models\Application::STATUS_ESCALATED, \App\Models\Application::STATUS_UNDER_REVIEW, \App\Models\Application::STATUS_PENDING_APPROVAL])) {
            return response()->json(['message' => 'Cannot request info for applications in this status'], 422);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'reason_code' => 'nullable|string|exists:reason_codes,code',
        ]);

        // Build the notes with reason code if provided
        $notes = $validated['message'];
        if (!empty($validated['reason_code'])) {
            $reasonCode = ReasonCode::where('code', $validated['reason_code'])->first();
            if ($reasonCode) {
                $notes = "[{$reasonCode->code}] {$reasonCode->reason}\n\n{$notes}";
            }
        }

        $this->applicationService->changeStatus(
            $application,
            \App\Models\Application::STATUS_ADDITIONAL_INFO,
            $notes
        );

        return response()->json([
            'message'     => __('case.info_requested'),
            'application' => $application->fresh(),
        ]);
    }

    /**
     * Add or update a note on an escalated case.
     */
    public function addNote(Request $request, Application $application): JsonResponse
    {
        if ($denied = $this->ensureMissionAccess($request, $application)) return $denied;

        $validated = $request->validate([
            'content' => 'required|string|max:2000',
            'is_private' => 'boolean',
        ]);

        $note = InternalNote::create([
            'application_id' => $application->id,
            'user_id'        => $request->user()->id,
            'content'        => $validated['content'],
            'is_private'     => $validated['is_private'] ?? false,
        ]);

        return response()->json([
            'message' => 'Note added successfully',
            'note'    => $note->load('user:id,first_name,last_name'),
        ], 201);
    }

    /**
     * Update an existing note.
     */
    public function updateNote(Request $request, Application $application, InternalNote $note): JsonResponse
    {
        if ($denied = $this->ensureMissionAccess($request, $application)) return $denied;

        if ($note->application_id !== $application->id) {
            return response()->json(['message' => 'Note not found'], 404);
        }

        if ($note->user_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only edit your own notes'], 403);
        }

        $validated = $request->validate([
            'content' => 'required|string|max:2000',
        ]);

        $note->update(['content' => $validated['content']]);

        return response()->json([
            'message' => 'Note updated successfully',
            'note'    => $note->fresh()->load('user:id,first_name,last_name'),
        ]);
    }

    /**
     * Reverse/revert an approved or denied decision.
     */
    public function revertDecision(Request $request, Application $application): JsonResponse
    {
        if ($denied = $this->ensureMissionAccess($request, $application)) return $denied;

        if (!in_array($application->status, [\App\Models\Application::STATUS_APPROVED, \App\Models\Application::STATUS_DENIED, \App\Models\Application::STATUS_PENDING_APPROVAL])) {
            return response()->json(['message' => 'Can only revert approved, denied, or pending approval applications'], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $previousStatus = $application->status;
        
        $this->applicationService->changeStatus(
            $application,
            \App\Models\Application::STATUS_ESCALATED,
            "Decision reverted from {$previousStatus}: {$validated['reason']}"
        );

        $application->update([
            'decided_at' => null,
            'decision_notes' => null,
        ]);

        return response()->json([
            'message'     => 'Decision reverted successfully',
            'application' => $application->fresh(),
        ]);
    }
}
