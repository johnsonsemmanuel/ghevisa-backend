<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Carbon\Carbon;

class BoardingAuthorization extends Model
{
    use HasFactory;

    protected $fillable = [
        'authorization_code',
        'passport_number_encrypted',
        'nationality',
        'authorization_type',
        'eta_number',
        'visa_id',
        'verification_timestamp',
        'expiry_timestamp',
        'verified_by_user_id',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'verification_timestamp' => 'datetime',
        'expiry_timestamp' => 'datetime',
    ];

    protected $hidden = [
        'passport_number_encrypted',
    ];

    public function visa(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'visa_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /**
     * Check if the BAC is still valid (not expired)
     */
    public function isValid(): bool
    {
        return Carbon::now()->lessThan($this->expiry_timestamp);
    }

    /**
     * Check if the BAC has expired
     */
    public function isExpired(): bool
    {
        return !$this->isValid();
    }

    /**
     * Get decrypted passport number
     */
    public function getPassportNumber(): string
    {
        return Crypt::decryptString($this->passport_number_encrypted);
    }

    /**
     * Get masked passport number for display
     */
    public function getMaskedPassportAttribute(): string
    {
        $passport = $this->getPassportNumber();
        return substr($passport, 0, 3) . '****' . substr($passport, -2);
    }

    /**
     * Scope to get only valid (non-expired) BACs
     */
    public function scopeValid($query)
    {
        return $query->where('expiry_timestamp', '>', Carbon::now());
    }

    /**
     * Scope to get expired BACs
     */
    public function scopeExpired($query)
    {
        return $query->where('expiry_timestamp', '<=', Carbon::now());
    }

    /**
     * Scope to filter by authorization type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('authorization_type', strtoupper($type));
    }
}
