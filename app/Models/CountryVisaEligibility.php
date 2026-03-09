<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CountryVisaEligibility extends Model
{
    use HasFactory;

    protected $table = 'country_visa_eligibility';

    protected $fillable = [
        'country_code',
        'country_name',
        'region',
        'bloc',
        'authorization_type',
        'current_policy',
        'max_stay_days',
        'eta_fee',
        'evisa_fee',
        'yellow_fever_required',
        'is_active',
        'special_conditions',
    ];

    protected $casts = [
        'eta_fee' => 'decimal:2',
        'evisa_fee' => 'decimal:2',
        'yellow_fever_required' => 'boolean',
        'is_active' => 'boolean',
        'special_conditions' => 'array',
    ];

    /**
     * Get eligibility for a specific country code
     */
    public static function getByCountryCode(string $code): ?self
    {
        return static::where('country_code', strtoupper($code))
            ->where('is_active', true)
            ->first();
    }

    /**
     * Check if a country is ETA eligible
     */
    public static function isEtaEligible(string $countryCode): bool
    {
        $eligibility = static::getByCountryCode($countryCode);
        return $eligibility && $eligibility->authorization_type === 'eta';
    }

    /**
     * Check if a country requires eVisa
     */
    public static function requiresEvisa(string $countryCode): bool
    {
        $eligibility = static::getByCountryCode($countryCode);
        return $eligibility && $eligibility->authorization_type === 'evisa';
    }

    /**
     * Get authorization type for a country
     */
    public static function getAuthorizationType(string $countryCode): string
    {
        $eligibility = static::getByCountryCode($countryCode);
        return $eligibility?->authorization_type ?? 'evisa'; // Default to eVisa if not found
    }

    /**
     * Get fee for a country based on authorization type
     */
    public static function getFee(string $countryCode): float
    {
        $eligibility = static::getByCountryCode($countryCode);
        if (!$eligibility) {
            return 60.00; // Default eVisa fee
        }

        if ($eligibility->authorization_type === 'eta') {
            return (float) ($eligibility->eta_fee ?? 20.00);
        }

        return (float) ($eligibility->evisa_fee ?? 60.00);
    }

    /**
     * Get all ECOWAS countries
     */
    public static function getEcowasCountries()
    {
        return static::where('bloc', 'ECOWAS')
            ->where('is_active', true)
            ->get();
    }

    /**
     * Get all AU countries (excluding ECOWAS)
     */
    public static function getAuCountries()
    {
        return static::where('bloc', 'AU')
            ->where('is_active', true)
            ->get();
    }

    /**
     * Get all Caribbean countries
     */
    public static function getCaribbeanCountries()
    {
        return static::where('bloc', 'CARICOM')
            ->where('is_active', true)
            ->get();
    }

    /**
     * Get countries by authorization type
     */
    public static function getByAuthorizationType(string $type)
    {
        return static::where('authorization_type', $type)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Get all ETA eligible countries
     */
    public static function getEtaEligibleCountries()
    {
        return static::getByAuthorizationType('eta');
    }

    /**
     * Get all eVisa required countries
     */
    public static function getEvisaRequiredCountries()
    {
        return static::getByAuthorizationType('evisa');
    }
}
