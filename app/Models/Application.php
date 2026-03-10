<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\EncryptsPii;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Application extends Model
{
    use HasFactory, SoftDeletes, Auditable, EncryptsPii;

    /**
     * Queue names for officer workflow.
     * These align with the operational spec (review vs approval queues)
     * and are referenced by both backend services and frontend dashboards.
     */
    public const QUEUE_REVIEW   = 'review_queue';
    public const QUEUE_APPROVAL = 'approval_queue';
    public const QUEUE_COMPLETED = 'completed';

    /**
     * Canonical internal status values for the application lifecycle.
     * These correspond to the spec:
     * DRAFT → SUBMITTED/PAID → UNDER_REVIEW → PENDING_APPROVAL → APPROVED/DENIED → ISSUED.
     */
    public const STATUS_DRAFT               = 'draft';
    public const STATUS_SUBMITTED           = 'submitted';
    public const STATUS_PENDING_PAYMENT     = 'pending_payment';
    public const STATUS_UNDER_REVIEW        = 'under_review';
    public const STATUS_PENDING_APPROVAL    = 'pending_approval';
    public const STATUS_ESCALATED           = 'escalated';
    public const STATUS_ADDITIONAL_INFO     = 'additional_info_requested';
    public const STATUS_APPROVED            = 'approved';
    public const STATUS_DENIED              = 'denied';
    public const STATUS_ISSUED              = 'issued';
    public const STATUS_CANCELLED           = 'cancelled';

    /**
     * Passport verification statuses.
     */
    public const PASSPORT_VERIFICATION_PENDING = 'pending';
    public const PASSPORT_VERIFICATION_PASSED  = 'passed';
    public const PASSPORT_VERIFICATION_FAILED  = 'failed';
    public const PASSPORT_VERIFICATION_REVIEW  = 'manual_review';

    protected $encryptedFields = [
        'first_name_encrypted',
        'last_name_encrypted',
        'date_of_birth_encrypted',
        'passport_number_encrypted',
        'nationality_encrypted',
        'email_encrypted',
        'phone_encrypted',
        'profession_encrypted',
    ];

    protected $fillable = [
        'reference_number',
        'user_id',
        'visa_type_id',
        'visa_channel',
        'entry_type',
        'authorization_type',
        'eta_number',
        'mfa_mission_id',
        'current_queue',
        'first_name_encrypted',
        'last_name_encrypted',
        'date_of_birth_encrypted',
        'passport_number_encrypted',
        'nationality_encrypted',
        'email_encrypted',
        'phone_encrypted',
        'gender',
        'marital_status',
        'profession_encrypted',
        'country_of_birth',
        'passport_issue_date',
        'passport_expiry',
        'passport_issuing_authority',
        'intended_arrival',
        'duration_days',
        'eta_validity_days',
        'entry_type_granted',
        'address_in_ghana',
        'port_of_entry',
        'airline',
        'flight_number',
        'host_name',
        'host_phone',
        'hotel_booking_reference',
        'purpose_of_visit',
        'visited_country_1',
        'visited_country_2',
        'visited_country_3',
        'previous_ghana_visa',
        'entry_denied_before',
        'criminal_conviction',
        'travel_history',
        'status',
        'tier',
        'assigned_agency',
        'assigned_officer_id',
        'current_step',
        'submitted_at',
        'sla_deadline',
        'decided_at',
        'decision_notes',
        'evisa_file_path',
        'processing_tier',
        'risk_screening_status',
        'risk_screening_notes',
        'evisa_qr_code',
        'reviewed_by_id',
        'reviewed_at',
        'risk_score',
        'risk_level',
        'watchlist_flagged',
        'risk_assessed_at',
        'service_tier_id',
        'total_fee',
        'government_fee',
        'platform_fee',
        'processing_fee',
        'health_good_condition',
        'health_recent_illness',
        'health_contact_infectious',
        'health_yellow_fever_vaccinated',
        'health_chronic_conditions',
        'health_condition_details',
        'owner_mission_id',
        'current_queue',
        'reviewing_officer_id',
        'approval_officer_id',
        'review_started_at',
        'review_completed_at',
        'approval_started_at',
        'approval_completed_at',
        'passport_verification_status',
        'passport_verification_source',
        'passport_verification_at',
    ];

    protected function casts(): array
    {
        return [
            'intended_arrival' => 'date',
            'passport_issue_date' => 'date',
            'passport_expiry' => 'date',
            'submitted_at'     => 'datetime',
            'sla_deadline'     => 'datetime',
            'decided_at'       => 'datetime',
            'risk_assessed_at' => 'datetime',
            'review_started_at' => 'datetime',
            'review_completed_at' => 'datetime',
            'approval_started_at' => 'datetime',
            'approval_completed_at' => 'datetime',
            'watchlist_flagged' => 'boolean',
            'previous_ghana_visa' => 'boolean',
            'entry_denied_before' => 'boolean',
            'criminal_conviction' => 'boolean',
            'total_fee' => 'decimal:2',
            'government_fee' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'processing_fee' => 'decimal:2',
        ];
    }

    protected $appends = [
        'first_name',
        'last_name',
        'date_of_birth',
        'passport_number',
        'nationality',
        'email',
        'phone',
        'profession',
    ];

    protected $hidden = [
        'first_name_encrypted',
        'last_name_encrypted',
        'date_of_birth_encrypted',
        'passport_number_encrypted',
        'nationality_encrypted',
        'email_encrypted',
        'phone_encrypted',
        'profession_encrypted',
    ];

    // ── Decrypted PII Accessors ──────────────────────────────

    public function getFirstNameAttribute(): ?string
    {
        return $this->first_name_encrypted;
    }

    public function getLastNameAttribute(): ?string
    {
        return $this->last_name_encrypted;
    }

    public function getDateOfBirthAttribute(): ?string
    {
        return $this->date_of_birth_encrypted;
    }

    public function getPassportNumberAttribute(): ?string
    {
        return $this->passport_number_encrypted;
    }

    public function getNationalityAttribute(): ?string
    {
        return $this->nationality_encrypted;
    }

    public function getEmailAttribute(): ?string
    {
        return $this->email_encrypted;
    }

    public function getPhoneAttribute(): ?string
    {
        return $this->phone_encrypted;
    }

    public function getProfessionAttribute(): ?string
    {
        return $this->profession_encrypted;
    }

    // ── Relationships ─────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function visaType(): BelongsTo
    {
        return $this->belongsTo(VisaType::class);
    }

    public function serviceTier(): BelongsTo
    {
        return $this->belongsTo(ServiceTier::class);
    }

    public function riskAssessment(): HasOne
    {
        return $this->hasOne(RiskAssessment::class);
    }

    public function assignedOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_officer_id');
    }

    public function reviewingOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewing_officer_id');
    }

    public function approvalOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approval_officer_id');
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(MfaMission::class, 'mfa_mission_id');
    }

    public function ownerMission(): BelongsTo
    {
        return $this->belongsTo(MfaMission::class, 'owner_mission_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class)->orderBy('created_at', 'desc');
    }

    public function internalNotes(): HasMany
    {
        return $this->hasMany(InternalNote::class)->orderBy('created_at', 'desc');
    }

    // ── Helpers ───────────────────────────────────────────

    public static function generateReferenceNumber(): string
    {
        $date = date('Ymd');
        $random = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        return "GH-EV-{$date}-{$random}";
    }

    public function isPaid(): bool
    {
        return $this->payment && $this->payment->status === 'completed';
    }

    public function isWithinSla(): bool
    {
        if (!$this->sla_deadline) {
            return true;
        }
        return now()->lt($this->sla_deadline);
    }

    public function slaHoursRemaining(): ?float
    {
        if (!$this->sla_deadline) {
            return null;
        }
        return max(0, now()->diffInHours($this->sla_deadline, false));
    }
}
