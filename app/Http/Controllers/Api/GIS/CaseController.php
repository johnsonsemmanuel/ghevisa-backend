<?php

namespace App\Http\Controllers\Api\GIS;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\InternalNote;
use App\Models\ReasonCode;
use App\Services\ApplicationRoutingService;
use App\Services\ApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaseController extends Controller
{
    public function __construct(
        protected ApplicationService $applicationService,
        protected ApplicationRoutingService $routingService,
    ) {}

    /**
     * Get the GIS case queue with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Application::where('assigned_agency', 'gis')
            ->with([
                'visaType', 
                'assignedOfficer:id,first_name,last_name',
                'reviewingOfficer:id,first_name,last_name',
                'approvalOfficer:id,first_name,last_name',
                'riskAssessment',
                'payment',
            ]);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($queue = $request->query('queue')) {
            // Mirror dashboard metrics logic so card counts match case lists
            if ($queue === 'review_queue') {
                $query->where(function ($q) {
                    $q->where('current_queue', 'review_queue')
                      ->orWhere(function ($q2) {
                          $q2->whereNull('current_queue')
                             ->whereIn('status', ['submitted', 'under_review', 'additional_info_requested']);
                      });
                });
            } elseif ($queue === 'approval_queue') {
                $query->where(function ($q) {
                    $q->where('current_queue', 'approval_queue')
                      ->orWhere(function ($q2) {
                          $q2->whereNull('current_queue')
                             ->where('status', 'pending_approval');
                      });
                });
            } else {
                $query->where('current_queue', $queue);
            }
        }

        if ($tier = $request->query('tier')) {
            $query->where('tier', $tier);
        }

        if ($search = $request->query('search')) {
            $query->where('reference_number', 'like', "%{$search}%");
        }

        $applications = $query->orderByRaw("
            CASE
                WHEN status = 'escalated' THEN 1
                WHEN status = 'pending_approval' THEN 2
                WHEN status = 'under_review' THEN 3
                WHEN status = 'submitted' THEN 4
                ELSE 5
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
     * Get a single case for review with all details.
     * SECURITY: Verify application belongs to GIS queue.
     * FIX #8: Added tier clearance verification.
     */
    public function show(Request $request, Application $application): JsonResponse
    {
        // IDOR Protection: Ensure application is in GIS queue
        if ($application->assigned_agency !== 'gis') {
            abort(403, 'This application is not assigned to GIS');
        }

        // FIX #8: Tier clearance check - officers can only view applications within their clearance
        if (!$request->user()->hasTierClearance($application->tier ?? 1)) {
            abort(403, 'You do not have clearance to view this tier ' . ($application->tier ?? 1) . ' application');
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
        $application->logAccess('viewed_by_officer', [
            'officer_id' => $request->user()->id,
            'officer_name' => $request->user()->full_name,
            'tier' => $application->tier,
        ]);

        return response()->json([
            'application'     => $application,
            'sla_hours_left'  => $application->slaHoursRemaining(),
            'is_within_sla'   => $application->isWithinSla(),
        ]);
    }

    /**
     * Assign a case to the current officer.
     */
    public function assignToSelf(Request $request, Application $application): JsonResponse
    {
        if ($application->assigned_agency !== 'gis') {
            return response()->json(['message' => __('case.not_gis_case')], 422);
        }

        $application->update(['assigned_officer_id' => $request->user()->id]);

        return response()->json([
            'message'     => __('case.assigned'),
            'application' => $application->fresh(),
        ]);
    }

    /**
     * Escalate a case to MFA for further review.
     */
    public function escalate(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        if ($application->assigned_agency !== 'gis') {
            return response()->json(['message' => __('case.not_gis_case')], 422);
        }

        $this->routingService->escalateToMfa($application, $validated['reason']);

        // Log the escalation reason as an internal note
        InternalNote::create([
            'application_id' => $application->id,
            'user_id'        => $request->user()->id,
            'content'        => 'Escalated to MFA: ' . $validated['reason'],
            'is_private'     => false,
        ]);

        $this->applicationService->changeStatus(
            $application, 'escalated', 'Escalated to MFA by GIS officer'
        );

        return response()->json([
            'message'     => __('case.escalated'),
            'application' => $application->fresh(),
        ]);
    }

    /**
     * Add an internal note to a case.
     * SECURITY: Verify application belongs to GIS queue.
     */
    public function addNote(Request $request, Application $application): JsonResponse
    {
        if ($application->assigned_agency !== 'gis') {
            return response()->json(['message' => __('case.not_gis_case')], 422);
        }

        $validated = $request->validate([
            'content'    => 'required|string|max:2000',
            'is_private' => 'nullable|boolean',
        ]);

        $note = InternalNote::create([
            'application_id' => $application->id,
            'user_id'        => $request->user()->id,
            'content'        => $validated['content'],
            'is_private'     => $validated['is_private'] ?? false,
        ]);

        return response()->json([
            'message' => __('case.note_added'),
            'note'    => $note->load('user:id,first_name,last_name'),
        ], 201);
    }

    /**
     * Request additional information from applicant.
     * SECURITY: Verify application belongs to GIS queue.
     */
    public function requestInfo(Request $request, Application $application): JsonResponse
    {
        if ($application->assigned_agency !== 'gis') {
            return response()->json(['message' => __('case.not_gis_case')], 422);
        }

        $validated = $request->validate([
            'message' => 'nullable|string|max:2000',
            'reason_code' => 'nullable|string|exists:reason_codes,code',
        ]);

        // Build the notes with reason code if provided
        $notes = $validated['message'];
        if (!empty($validated['reason_code'])) {
            $reasonCode = \App\Models\ReasonCode::where('code', $validated['reason_code'])->first();
            if ($reasonCode) {
                $notes = "[{$reasonCode->code}] {$reasonCode->reason}\n\n{$notes}";
            }
        }

        $this->applicationService->changeStatus(
            $application,
            'additional_info_requested',
            $notes
        );

        return response()->json([
            'message'     => __('case.info_requested'),
            'application' => $application->fresh(),
        ]);
    }

    /**
     * Get dashboard metrics for GIS officers.
     */
    public function metrics(): JsonResponse
    {
        $base = Application::where('assigned_agency', 'gis');

        return response()->json([
            'pending_review'    => (clone $base)->whereIn('status', ['submitted', 'under_review'])->count(),
            'in_review'         => (clone $base)->where('status', 'under_review')->count(),
            'pending_approval'  => (clone $base)->where('status', 'pending_approval')->count(),
            'approved_today'    => (clone $base)->whereIn('status', ['approved', 'issued'])->whereDate('decided_at', today())->count(),
            'issued_today'      => (clone $base)->where('status', 'issued')->whereDate('updated_at', today())->count(),
            'total_approved'    => (clone $base)->whereIn('status', ['approved', 'issued'])->count(),
            'total_denied'      => (clone $base)->where('status', 'denied')->count(),
            'sla_breaches'      => (clone $base)->whereNotNull('sla_deadline')
                ->whereNotIn('status', ['approved', 'denied', 'issued', 'cancelled'])
                ->where('sla_deadline', '<', now())->count(),
            'review_queue'      => (clone $base)->where(function ($q) {
                $q->where('current_queue', 'review_queue')
                  ->orWhere(function ($q2) {
                      $q2->whereNull('current_queue')
                         ->whereIn('status', ['submitted', 'under_review', 'additional_info_requested']);
                  });
            })->count(),
            'approval_queue'    => (clone $base)->where(function ($q) {
                $q->where('current_queue', 'approval_queue')
                  ->orWhere(function ($q2) {
                      $q2->whereNull('current_queue')
                         ->where('status', 'pending_approval');
                  });
            })->count(),
        ]);
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
     * Verify or reject a document.
     */
    public function verifyDocument(Request $request, Application $application, ApplicationDocument $document): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:verified,rejected',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($document->application_id !== $application->id) {
            return response()->json(['message' => __('case.document_not_found')], 404);
        }

        $document->update([
            'verification_status' => $validated['status'],
            'rejection_reason'    => $validated['status'] === 'rejected' ? ($validated['reason'] ?? 'Document rejected by officer') : null,
        ]);

        // Log the action
        InternalNote::create([
            'application_id' => $application->id,
            'user_id'        => $request->user()->id,
            'content'        => "Document '{$document->document_type}' marked as {$validated['status']}" . 
                               ($validated['reason'] ? ": {$validated['reason']}" : ''),
            'is_private'     => true,
        ]);

        return response()->json([
            'message'  => __('case.document_verified'),
            'document' => $document->fresh(),
        ]);
    }

    /**
     * Submit application for approval (two-step process).
     * Reviewer submits, then Approver approves.
     * Requires: applications.review permission
     * FIX #8: Added tier clearance check.
     */
    public function submitForApproval(Request $request, Application $application): JsonResponse
    {
        // FIX #8: Use new canReview() method with tier clearance
        if (!$request->user()->canReview($application)) {
            return response()->json([
                'message' => 'You do not have permission to review this application. Check your tier clearance.'
            ], 403);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        if ($application->assigned_agency !== 'gis') {
            return response()->json(['message' => __('case.not_gis_case')], 422);
        }

        if (!in_array($application->status, ['submitted', 'under_review', 'additional_info_requested'])) {
            return response()->json(['message' => __('case.invalid_status_for_approval')], 422);
        }

        $application->update([
            'reviewed_by_id' => $request->user()->id,
            'reviewed_at' => now(),
            'reviewing_officer_id' => $request->user()->id, // Auto-assign to reviewing officer
            'current_queue' => 'approval_queue',
        ]);

        $this->applicationService->changeStatus(
            $application, 
            'pending_approval', 
            $validated['notes'] ?? 'Submitted for approval by reviewer'
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
                'message' => 'You do not have permission to approve this application. Check your tier clearance and agency assignment.'
            ], 403);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        if ($application->assigned_agency !== 'gis') {
            return response()->json(['message' => __('case.not_gis_case')], 422);
        }

        // Only pending_approval applications can be approved (two-step enforcement)
        if ($application->status !== 'pending_approval') {
            return response()->json(['message' => __('case.invalid_status_for_approval')], 422);
        }

        $application->update([
            'approval_officer_id' => $request->user()->id,
            'approval_started_at' => $application->approval_started_at ?? now(),
            'approval_completed_at' => now(),
        ]);

        // Explicit audit log for approval action
        $application->logAccess('approved_by_officer', [
            'officer_id' => $request->user()->id,
            'officer_name' => $request->user()->full_name,
            'notes' => $validated['notes'] ?? 'Approved',
        ]);

        $this->applicationService->changeStatus($application, 'approved', $validated['notes'] ?? 'Approved');

        // Handle ETA vs eVisa approval differently
        if ($application->authorization_type === 'eta') {
            // ETA: Generate ETA number and QR code
            $etaService = app(\App\Services\EtaService::class);
            $etaService->processEtaApproval($application);
            
            // Queue ETA notification email
            \App\Jobs\SendNotification::dispatch($application, 'eta_approved');
        } else {
            // eVisa: Queue PDF generation
            \App\Jobs\GenerateEVisaPdf::dispatch($application);
        }

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
        // FIX #8: Use new canApprove() method with tier clearance
        if (!$request->user()->canApprove($application)) {
            return response()->json([
                'message' => 'You do not have permission to deny this application. Check your tier clearance and agency assignment.'
            ], 403);
        }

        $validated = $request->validate([
            'reason_codes' => 'required|array|min:1',
            'reason_codes.*' => 'required|string|exists:reason_codes,code',
            'notes' => 'required|string|max:2000',
        ]);

        if ($application->assigned_agency !== 'gis') {
            return response()->json(['message' => __('case.not_gis_case')], 422);
        }

        // Only pending_approval applications can be denied (two-step enforcement)
        if ($application->status !== 'pending_approval') {
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

        $this->applicationService->changeStatus($application, 'denied', $validated['notes']);

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

        if ($application->assigned_agency !== 'gis') {
            return response()->json(['message' => __('case.not_gis_case')], 422);
        }

        if ($application->status !== 'approved') {
            return response()->json(['message' => 'Application must be approved before issuing visa'], 422);
        }

        // FIX-17/ARCH-03: Queue PDF generation if not already generated
        if (!$application->evisa_file_path) {
            \App\Jobs\GenerateEVisaPdf::dispatch($application);
        }

        $this->applicationService->changeStatus($application, 'issued', 'Visa issued');

        return response()->json([
            'message'     => 'Visa issued successfully',
            'application' => $application->fresh(),
        ]);
    }

    /**
     * Download/preview a document for an application.
     */
    public function downloadDocument(Application $application, ApplicationDocument $document): \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\JsonResponse
    {
        if ($application->assigned_agency !== 'gis') {
            return response()->json(['message' => __('case.not_gis_case')], 403);
        }

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
     * Reverse/revert an approved or denied decision.
     */
    public function revertDecision(Request $request, Application $application): JsonResponse
    {
        if ($application->assigned_agency !== 'gis') {
            return response()->json(['message' => __('case.not_gis_case')], 422);
        }

        if (!in_array($application->status, ['approved', 'denied', 'pending_approval'])) {
            return response()->json(['message' => 'Can only revert approved, denied, or pending approval applications'], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $previousStatus = $application->status;
        
        $this->applicationService->changeStatus(
            $application,
            'under_review',
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

    /**
     * Batch assign applications to an officer.
     */
    public function batchAssign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'application_ids' => 'required|array|min:1|max:50',
            'application_ids.*' => 'integer|exists:applications,id',
            'officer_id' => 'required|integer|exists:users,id',
        ]);

        $updated = Application::whereIn('id', $validated['application_ids'])
            ->where('assigned_agency', 'gis')
            ->whereIn('status', ['submitted', 'under_review'])
            ->update(['assigned_officer_id' => $validated['officer_id']]);

        return response()->json([
            'message' => "{$updated} applications assigned successfully",
            'updated_count' => $updated,
        ]);
    }

    /**
     * Batch update application status.
     */
    public function batchUpdateStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'application_ids' => 'required|array|min:1|max:50',
            'application_ids.*' => 'integer|exists:applications,id',
            'status' => 'required|in:under_review,escalated',
            'notes' => 'nullable|string|max:2000',
        ]);

        $applications = Application::whereIn('id', $validated['application_ids'])
            ->where('assigned_agency', 'gis')
            ->get();

        $updated = 0;
        $errors = [];
        foreach ($applications as $application) {
            try {
                if ($validated['status'] === 'escalated') {
                    $this->routingService->escalateToMfa($application, $validated['notes'] ?? 'Batch escalation');
                } else {
                    $this->applicationService->changeStatus(
                        $application,
                        $validated['status'],
                        $validated['notes'] ?? 'Batch status update'
                    );
                }
                $updated++;
            } catch (\Exception $e) {
                $errors[] = [
                    'reference_number' => $application->reference_number,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => "{$updated} applications updated successfully",
            'updated_count' => $updated,
            'errors' => $errors,
        ]);
    }

    /**
     * Batch approve applications.
     * FIX #8: Added per-application authorization check.
     */
    public function batchApprove(Request $request): JsonResponse
    {
        if (!$request->user()->canApproveApplications()) {
            return response()->json(['message' => 'You do not have permission to approve applications'], 403);
        }

        $validated = $request->validate([
            'application_ids' => 'required|array|min:1|max:20',
            'application_ids.*' => 'integer|exists:applications,id',
            'reason_code' => 'nullable|string|exists:reason_codes,code',
            'notes' => 'nullable|string|max:2000',
        ]);

        $applications = Application::whereIn('id', $validated['application_ids'])
            ->where('assigned_agency', 'gis')
            ->where('status', 'pending_approval')
            ->get();

        $approved = 0;
        $errors = [];

        foreach ($applications as $application) {
            try {
                // FIX #8: Check authorization for EACH application (tier clearance + mission access)
                if (!$request->user()->canApprove($application)) {
                    $errors[] = [
                        'reference_number' => $application->reference_number,
                        'error' => 'Insufficient clearance for tier ' . ($application->tier ?? 1) . ' application',
                    ];
                    continue;
                }

                $this->applicationService->changeStatus(
                    $application,
                    'approved',
                    $validated['notes'] ?? 'Batch approval'
                );

                $application->update([
                    'decided_at' => now(),
                    'decision_notes' => $validated['notes'] ?? null,
                    'approval_officer_id' => $request->user()->id,
                ]);

                // Explicit audit log for batch approval
                $application->logAccess('batch_approved_by_officer', [
                    'officer_id' => $request->user()->id,
                    'officer_name' => $request->user()->full_name,
                    'notes' => $validated['notes'] ?? 'Batch approval',
                ]);

                $approved++;
            } catch (\Exception $e) {
                $errors[] = [
                    'reference_number' => $application->reference_number,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => "{$approved} applications approved",
            'approved_count' => $approved,
            'errors' => $errors,
        ]);
    }

    /**
     * Batch request additional information.
     */
    public function batchRequestInfo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'application_ids' => 'required|array|min:1|max:50',
            'application_ids.*' => 'integer|exists:applications,id',
            'reason_codes' => 'required|array|min:1',
            'reason_codes.*' => 'required|string|exists:reason_codes,code',
            'notes' => 'required|string|max:2000',
        ]);

        $applications = Application::whereIn('id', $validated['application_ids'])
            ->where('assigned_agency', 'gis')
            ->whereIn('status', ['submitted', 'under_review'])
            ->get();

        $updated = 0;
        $errors = [];
        foreach ($applications as $application) {
            try {
                $this->applicationService->changeStatus(
                    $application,
                    'additional_info_requested',
                    $validated['notes']
                );
                $updated++;
            } catch (\Exception $e) {
                $errors[] = [
                    'reference_number' => $application->reference_number,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => "{$updated} applications updated - additional info requested",
            'updated_count' => $updated,
            'errors' => $errors,
        ]);
    }

    /**
     * Get batch processing statistics.
     */
    public function batchStats(): JsonResponse
    {
        $stats = [
            'available_for_batch' => Application::where('assigned_agency', 'gis')
                ->whereIn('status', ['submitted', 'under_review'])
                ->count(),
            'by_status' => Application::where('assigned_agency', 'gis')
                ->whereIn('status', ['submitted', 'under_review', 'pending_approval'])
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
            'unassigned' => Application::where('assigned_agency', 'gis')
                ->whereNull('assigned_officer_id')
                ->whereIn('status', ['submitted', 'under_review'])
                ->count(),
        ];

        return response()->json(['stats' => $stats]);
    }
}
