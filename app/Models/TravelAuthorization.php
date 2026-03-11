<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Travel Authorization Model
 * 
 * Central master record for all travel authorizations (ETA and Visa).
 * TAID serves as the root identifier across the entire system.
 * 
 * @property string $taid Primary key - Format: GH-TA-YYYYMMDD-XXXX
 * @property string $passport_number_encrypted Encrypted passport number
 * @property string $nationality ISO 3166-1 alpha-2 country code
 * @property string $authorization_type ETA or VISA
 * @property string $status active, expired, revoked, used
 */
class TravelAuthorization extends Model
{
    protected $table = 'travel_authorizations';
    protected $primaryKey = 'taid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'taid',
        'passport_number_encrypted',
        'nationality',
        'authorization_type',
        'status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the ETA application associated with this TAID.
     */
    public function etaApplication()
    {
        return $this->hasOne(EtaApplication::class, 'taid', 'taid');
    }

    /**
     * Get the visa application associated with this TAID.
     */
    public function visaApplication()
    {
        return $this->hasOne(Application::class, 'taid', 'taid');
    }

    /**
     * Get all border crossings for this TAID.
     */
    public function borderCrossings()
    {
        return $this->hasMany(BorderCrossing::class, 'taid', 'taid');
    }

    /**
     * Get all boarding authorizations for this TAID.
     */
    public function boardingAuthorizations()
    {
        return $this->hasMany(BoardingAuthorization::class, 'taid', 'taid');
    }

    /**
     * Get decrypted passport number.
     */
    public function getPassportNumberAttribute(): string
    {
        return Crypt::decryptString($this->passport_number_encrypted);
    }

    /**
     * Set encrypted passport number.
     */
    public function setPassportNumberAttribute(string $value): void
    {
        $this->attributes['passport_number_encrypted'] = Crypt::encryptString($value);
    }

    /**
     * Get the authorization record (ETA or Visa).
     */
    public function getAuthorizationRecord()
    {
        if ($this->authorization_type === 'ETA') {
            return $this->etaApplication;
        }
        return $this->visaApplication;
    }

    /**
     * Check if this authorization is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if this authorization has been used.
     */
    public function isUsed(): bool
    {
        return $this->status === 'used';
    }

    /**
     * Check if this authorization has expired.
     */
    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    /**
     * Mark authorization as used.
     */
    public function markAsUsed(): void
    {
        $this->update(['status' => 'used']);
    }

    /**
     * Mark authorization as expired.
     */
    public function markAsExpired(): void
    {
        $this->update(['status' => 'expired']);
    }

    /**
     * Revoke this authorization.
     */
    public function revoke(): void
    {
        $this->update(['status' => 'revoked']);
    }
}
