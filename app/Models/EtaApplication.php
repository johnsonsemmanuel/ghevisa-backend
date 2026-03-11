<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EtaApplication extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'reference_number',
        'taid',
        'user_id',
        'first_name_encrypted',
        'last_name_encrypted',
        'date_of_birth',
        'gender',
        'nationality_encrypted',
        'passport_number_encrypted',
        'passport_issue_date',
        'passport_expiry_date',
        'passport_issuing_authority',
        'passport_scan_path',
        'photo_path',
        'email_encrypted',
        'phone_encrypted',
        'residential_address_encrypted',
        'intended_arrival_date',
        'port_of_entry',
        'airline',
        'flight_number',
        'address_in_ghana_encrypted',
        'host_name',
        'host_phone',
        'hotel_booking_path',
        'denied_entry_before',
        'criminal_conviction',
        'previous_ghana_visa',
        'travel_history',
        'eta_number',
        'qr_code',
        'status',
        'validity_days',
        'entry_type',
        'fee_amount',
        'payment_status',
        'payment_reference',
        'approved_at',
        'expires_at',
        'valid_from',
        'valid_until',
        'passport_verification_status',
        'passport_verification_source',
        'passport_verification_at',
        'entry_consumed',
        'entry_date',
        'port_of_entry_used',
        'entry_officer_id',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'passport_issue_date' => 'date',
            'passport_expiry_date' => 'date',
            'intended_arrival_date' => 'date',
            'denied_entry_before' => 'boolean',
            'criminal_conviction' => 'boolean',
            'previous_ghana_visa' => 'boolean',
            'fee_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'expires_at' => 'datetime',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'validity_days' => 'integer',
            'entry_consumed' => 'boolean',
            'entry_date' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entryOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entry_officer_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'completed';
    }

    public function isConsumed(): bool
    {
        return $this->entry_consumed === true;
    }

    public function canBeUsedForEntry(): bool
    {
        return $this->isApproved() 
            && !$this->isExpired() 
            && !$this->isConsumed()
            && $this->entry_type === 'single';
    }

    public function isMultipleEntry(): bool
    {
        return $this->entry_type === 'multiple';
    }

    /**
     * Generate unique ETA reference number
     */
    public static function generateReferenceNumber(): string
    {
        $prefix = 'GH-ETA';
        $year = date('Y');
        $random = strtoupper(substr(md5(uniqid()), 0, 6));
        return "{$prefix}-{$year}-{$random}";
    }

    /**
     * Generate ETA number upon approval
     * Format: GH-ETA-YYYYMMDD-XXXX (per specification)
     */
    public function generateEtaNumber(): string
    {
        $date = date('Ymd');
        $random = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));
        $this->eta_number = "GH-ETA-{$date}-{$random}";
        
        // Set validity period (90 days from approval)
        $this->valid_from = now();
        $this->valid_until = now()->addDays(90);
        $this->expires_at = $this->valid_until; // Keep expires_at for backward compatibility
        
        $this->save();
        return $this->eta_number;
    }
}
