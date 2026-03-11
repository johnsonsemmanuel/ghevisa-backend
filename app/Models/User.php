<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes, Auditable;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'phone',
        'role',
        'agency',
        'mfa_mission_id',
        'can_review',
        'can_approve',
        'is_active',
        'locale',
        'email_verified_at',
        'email_verification_token',
        'email_verification_expires_at',
        'mfa_token',
        'mfa_expires_at',
        'failed_login_attempts',
        'locked_until',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_verification_expires_at' => 'datetime',
            'mfa_expires_at'    => 'datetime',
            'locked_until'      => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'can_review'        => 'boolean',
            'can_approve'       => 'boolean',
            'failed_login_attempts' => 'integer',
        ];
    }

    // ── Relationships ─────────────────────────────────────

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function assignedApplications(): HasMany
    {
        return $this->hasMany(Application::class, 'assigned_officer_id');
    }

    public function internalNotes(): HasMany
    {
        return $this->hasMany(InternalNote::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(MfaMission::class, 'mfa_mission_id');
    }

    public function borderCrossings(): HasMany
    {
        return $this->hasMany(BorderCrossing::class, 'officer_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(\Illuminate\Notifications\DatabaseNotification::class, 'notifiable_id')
            ->where('notifiable_type', \App\Models\User::class);
    }

    // ── Helpers ───────────────────────────────────────────

    public function isApplicant(): bool
    {
        return $this->role === 'applicant';
    }

    public function isGisOfficer(): bool
    {
        return in_array($this->role, ['gis_officer', 'gis_reviewer', 'gis_approver']);
    }

    public function isGisReviewer(): bool
    {
        return $this->role === 'gis_reviewer' || ($this->role === 'gis_officer' && $this->can_review);
    }

    public function isGisApprover(): bool
    {
        return $this->role === 'gis_approver' || ($this->role === 'gis_officer' && $this->can_approve);
    }

    public function isGisAdmin(): bool
    {
        return $this->role === 'gis_admin';
    }

    public function isMfaReviewer(): bool
    {
        return $this->role === 'mfa_reviewer' || ($this->isMfaOfficer() && $this->can_review);
    }

    public function isMfaApprover(): bool
    {
        return $this->role === 'mfa_approver' || ($this->isMfaOfficer() && $this->can_approve);
    }

    public function isMfaOfficer(): bool
    {
        return in_array($this->role, ['mfa_reviewer', 'mfa_approver']);
    }

    public function isMfaAdmin(): bool
    {
        return $this->role === 'mfa_admin';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function canReviewApplications(): bool
    {
        return $this->can_review || in_array($this->role, ['gis_admin', 'gis_reviewer', 'mfa_admin', 'mfa_reviewer', 'gis_officer', 'admin']);
    }

    public function canApproveApplications(): bool
    {
        return $this->can_approve || in_array($this->role, ['gis_admin', 'gis_approver', 'mfa_admin', 'mfa_approver', 'admin']);
    }

    public function canAccessMission(int $missionId): bool
    {
        // Admins can access all missions
        if ($this->isAdmin() || $this->isMfaAdmin()) {
            return true;
        }

        // MFA officers can only access their assigned mission
        if ($this->isMfaOfficer() || $this->isMfaReviewer() || $this->isMfaApprover()) {
            return $this->mfa_mission_id === $missionId;
        }

        return false;
    }

    /**
     * FIX #8: Check if user has clearance for application tier.
     * SECURITY: Prevents junior officers from approving high-risk applications.
     * 
     * Tier Clearance Levels:
     * - Tier 1 (Low Risk): All officers
     * - Tier 2 (Medium Risk): Reviewers and above
     * - Tier 3 (High Risk): Approvers only
     * - Tier 4 (Critical Risk): Admins only
     */
    public function hasTierClearance(int|string|null $tier): bool
    {
        // Convert string tier to integer (e.g., "tier_1" -> 1)
        if (is_string($tier)) {
            $tier = (int) str_replace('tier_', '', $tier);
        }
        
        // Default to tier 1 if null
        if ($tier === null) {
            $tier = 1;
        }

        // Admins have clearance for all tiers
        if ($this->isAdmin() || $this->isGisAdmin() || $this->isMfaAdmin()) {
            return true;
        }

        // Tier 4 (Critical): Admins only
        if ($tier >= 4) {
            return false;
        }

        // Tier 3 (High Risk): Approvers and above
        if ($tier >= 3) {
            return $this->canApproveApplications();
        }

        // Tier 2 (Medium Risk): Reviewers and above
        if ($tier >= 2) {
            return $this->canReviewApplications();
        }

        // Tier 1 (Low Risk): All officers
        return $this->isGisOfficer() || $this->isMfaOfficer();
    }

    /**
     * FIX #8: Check if user can approve a specific application.
     * SECURITY: Combines permission check + tier clearance + mission access.
     */
    public function canApprove(Application $application): bool
    {
        // Must have approval permission
        if (!$this->canApproveApplications()) {
            return false;
        }

        // Must have tier clearance
        if (!$this->hasTierClearance($application->tier ?? 1)) {
            return false;
        }

        // MFA officers: Must have mission access
        if ($this->isMfaOfficer() && $application->owner_mission_id) {
            if (!$this->canAccessMission($application->owner_mission_id)) {
                return false;
            }
        }

        // GIS officers: Can only approve GIS applications
        if ($this->isGisOfficer() && $application->assigned_agency !== 'gis') {
            return false;
        }

        // MFA officers: Can only approve MFA applications
        if ($this->isMfaOfficer() && $application->assigned_agency !== 'mfa') {
            return false;
        }

        return true;
    }

    /**
     * FIX #8: Check if user can review a specific application.
     * SECURITY: Combines permission check + mission access.
     */
    public function canReview(Application $application): bool
    {
        // Must have review permission
        if (!$this->canReviewApplications()) {
            return false;
        }

        // MFA officers: Must have mission access
        if ($this->isMfaOfficer() && $application->owner_mission_id) {
            if (!$this->canAccessMission($application->owner_mission_id)) {
                return false;
            }
        }

        // GIS officers: Can only review GIS applications
        if ($this->isGisOfficer() && $application->assigned_agency !== 'gis') {
            return false;
        }

        // MFA officers: Can only review MFA applications
        if ($this->isMfaOfficer() && $application->assigned_agency !== 'mfa') {
            return false;
        }

        return true;
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
