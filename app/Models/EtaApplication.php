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
        'passport_verification_status',
        'passport_verification_source',
        'passport_verification_at',
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
            'validity_days' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     */
    public function generateEtaNumber(): string
    {
        $this->eta_number = 'ETA' . date('Ymd') . str_pad($this->id, 6, '0', STR_PAD_LEFT);
        $this->save();
        return $this->eta_number;
    }
}
