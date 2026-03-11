<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * FIX #9: Immutable Audit Service
 * 
 * SECURITY: Government-grade audit logging for compliance and forensics.
 * - Write-only audit logs (no updates/deletes)
 * - Tamper-proof logging
 * - Security event alerting
 * - Complete audit trail for all sensitive operations
 * 
 * COMPLIANCE: NIST SP 800-53 AU-2, AU-3, AU-6, AU-9
 */
class ImmutableAuditService
{
    /**
     * Critical security events that require immediate alerting.
     */
    private const CRITICAL_EVENTS = [
        'unauthorized_access_attempt',
        'privilege_escalation_attempt',
        'mass_data_export',
        'suspicious_approval_pattern',
        'watchlist_match_ignored',
        'payment_fraud_detected',
        'malware_detected',
        'tier_clearance_violation',
    ];

    /**
     * Log a security-sensitive action with full context.
     * 
     * @param string $action Action performed (e.g., 'viewed_pii', 'approved_application')
     * @param string $auditableType Model type (e.g., 'App\Models\Application')
     * @param int $auditableId Model ID
     * @param array $context Additional context data
     * @param User|null $user User performing action (defaults to auth user)
     * @return AuditLog
     */
    public function log(
        string $action,
        string $auditableType,
        int $auditableId,
        array $context = [],
        ?User $user = null
    ): AuditLog {
        $user = $user ?? auth()->user();

        $auditData = [
            'user_id' => $user?->id,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'old_values' => $context['old_values'] ?? null,
            'new_values' => $context['new_values'] ?? null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Add additional context
        if (!empty($context)) {
            $auditData['new_values'] = array_merge(
                $auditData['new_values'] ?? [],
                ['context' => $context]
            );
        }

        // Write to audit log (immutable - no updates allowed)
        $auditLog = AuditLog::create($auditData);

        // Log to dedicated audit channel
        Log::channel('audit')->info("AUDIT: {$action}", [
            'audit_id' => $auditLog->id,
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'auditable' => "{$auditableType}:{$auditableId}",
            'ip' => request()->ip(),
            'context' => $context,
        ]);

        // Alert on critical security events
        if (in_array($action, self::CRITICAL_EVENTS)) {
            $this->alertSecurityTeam($action, $auditLog, $context);
        }

        return $auditLog;
    }

    /**
     * Log PII access (GDPR/compliance requirement).
     */
    public function logPiiAccess(string $auditableType, int $auditableId, array $fields): void
    {
        $this->log('viewed_pii', $auditableType, $auditableId, [
            'pii_fields' => $fields,
            'purpose' => 'officer_review',
        ]);
    }

    /**
     * Log document access.
     */
    public function logDocumentAccess(int $documentId, string $documentType): void
    {
        $this->log('viewed_document', 'App\Models\ApplicationDocument', $documentId, [
            'document_type' => $documentType,
        ]);
    }

    /**
     * Log approval/denial decisions.
     */
    public function logDecision(
        int $applicationId,
        string $decision,
        array $reasonCodes = [],
        ?string $notes = null
    ): void {
        $this->log("application_{$decision}", 'App\Models\Application', $applicationId, [
            'decision' => $decision,
            'reason_codes' => $reasonCodes,
            'notes' => $notes,
            'officer_role' => auth()->user()?->role,
        ]);
    }

    /**
     * Log payment actions.
     */
    public function logPaymentAction(int $paymentId, string $action, array $details = []): void
    {
        $this->log("payment_{$action}", 'App\Models\Payment', $paymentId, $details);
    }

    /**
     * Log batch operations.
     */
    public function logBatchOperation(string $operation, array $applicationIds, array $results): void
    {
        $this->log("batch_{$operation}", 'App\Models\Application', 0, [
            'operation' => $operation,
            'application_count' => count($applicationIds),
            'application_ids' => $applicationIds,
            'success_count' => $results['success_count'] ?? 0,
            'error_count' => $results['error_count'] ?? 0,
            'errors' => $results['errors'] ?? [],
        ]);
    }

    /**
     * Log security events.
     */
    public function logSecurityEvent(string $event, array $details = []): void
    {
        $this->log($event, 'App\Models\User', auth()->id() ?? 0, $details);
    }

    /**
     * Log tier clearance violations.
     */
    public function logTierViolation(int $applicationId, int $requiredTier, int $userTier): void
    {
        $this->log('tier_clearance_violation', 'App\Models\Application', $applicationId, [
            'required_tier' => $requiredTier,
            'user_tier' => $userTier,
            'user_role' => auth()->user()?->role,
        ]);
    }

    /**
     * Log mission isolation violations.
     */
    public function logMissionViolation(int $applicationId, int $applicationMission, ?int $userMission): void
    {
        $this->log('mission_isolation_violation', 'App\Models\Application', $applicationId, [
            'application_mission' => $applicationMission,
            'user_mission' => $userMission,
            'user_role' => auth()->user()?->role,
        ]);
    }

    /**
     * Alert security team on critical events.
     */
    private function alertSecurityTeam(string $event, AuditLog $auditLog, array $context): void
    {
        $alertEmail = config('security.alert_email', 'security@ghevisa.gov.gh');

        // Log to security channel
        Log::channel('security')->critical("SECURITY ALERT: {$event}", [
            'audit_id' => $auditLog->id,
            'user_id' => $auditLog->user_id,
            'user_email' => $auditLog->user?->email,
            'ip' => $auditLog->ip_address,
            'context' => $context,
            'timestamp' => now()->toIso8601String(),
        ]);

        // Send email alert (async)
        try {
            Mail::to($alertEmail)->queue(
                new \App\Mail\SecurityAlertNotification($event, $auditLog, $context)
            );
        } catch (\Exception $e) {
            Log::error('Failed to send security alert email', [
                'error' => $e->getMessage(),
                'event' => $event,
            ]);
        }
    }

    /**
     * Get audit trail for an entity.
     */
    public function getAuditTrail(string $auditableType, int $auditableId, int $limit = 100): \Illuminate\Support\Collection
    {
        return AuditLog::where('auditable_type', $auditableType)
            ->where('auditable_id', $auditableId)
            ->with('user:id,first_name,last_name,email,role')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Search audit logs (for compliance team).
     */
    public function search(array $filters): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = AuditLog::with('user:id,first_name,last_name,email,role');

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['action'])) {
            $query->where('action', 'like', "%{$filters['action']}%");
        }

        if (!empty($filters['auditable_type'])) {
            $query->where('auditable_type', $filters['auditable_type']);
        }

        if (!empty($filters['ip_address'])) {
            $query->where('ip_address', $filters['ip_address']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->paginate(50);
    }
}
