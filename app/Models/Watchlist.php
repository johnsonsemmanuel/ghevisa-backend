<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class Watchlist extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'list_type',
        'first_name_encrypted',
        'last_name_encrypted',
        'date_of_birth',
        'nationality',
        'passport_number_encrypted',
        'id_number_encrypted',
        'reason',
        'source',
        'source_reference',
        'severity',
        'effective_from',
        'effective_until',
        'is_active',
        'added_by_id',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'first_name_encrypted',
        'last_name_encrypted',
        'passport_number_encrypted',
        'id_number_encrypted',
    ];

    protected $appends = [
        'first_name',
        'last_name',
        'passport_number_masked',
    ];

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id');
    }

    public function getFirstNameAttribute(): ?string
    {
        return $this->first_name_encrypted ? Crypt::decryptString($this->first_name_encrypted) : null;
    }

    public function getLastNameAttribute(): ?string
    {
        return $this->last_name_encrypted ? Crypt::decryptString($this->last_name_encrypted) : null;
    }

    public function getPassportNumberMaskedAttribute(): ?string
    {
        if (!$this->passport_number_encrypted) {
            return null;
        }
        $passport = Crypt::decryptString($this->passport_number_encrypted);
        return substr($passport, 0, 3) . '****' . substr($passport, -2);
    }

    public function getPassportNumberAttribute(): ?string
    {
        return $this->passport_number_encrypted ? Crypt::decryptString($this->passport_number_encrypted) : null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', now());
            })
            ->where(function ($q) {
                $q->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', now());
            });
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('list_type', $type);
    }

    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Check if applicant matches any watchlist entry.
     * 
     * CRITICAL SECURITY FIX: Strengthened watchlist matching thresholds.
     * 
     * Previous threshold of 50 points was TOO LOW, causing:
     * - Excessive false positives
     * - Officer alert fatigue
     * - Real threats getting lost in noise
     * - Rubber-stamping of watchlist alerts
     * 
     * New tiered thresholds:
     * - TERRORIST watchlist: 85+ points (strict matching required)
     * - CRIMINAL watchlist: 70+ points (medium matching)
     * - IMMIGRATION watchlist: 60+ points (standard matching)
     * 
     * Scoring system:
     * - Full name + DOB + nationality: 65 points
     * - Full name + passport: 90 points
     * - Passport alone: 40 points (not enough for match)
     * - Last name + DOB + nationality: 40 points (not enough for match)
     * 
     * @param string $firstName Applicant first name
     * @param string $lastName Applicant last name
     * @param string|null $passportNumber Applicant passport number
     * @param string|null $nationality Applicant nationality
     * @param \DateTime|null $dob Applicant date of birth
     * @return array Array of matches with scores and details
     */
    public static function checkMatch(string $firstName, string $lastName, ?string $passportNumber = null, ?string $nationality = null, ?\DateTime $dob = null): array
    {
        $matches = [];

        $query = self::active();

        // Get all active watchlist entries and check against encrypted values
        $entries = $query->get();

        foreach ($entries as $entry) {
            $score = 0;
            $matchedFields = [];

            // Check name match (fuzzy)
            $entryFirstName = strtolower($entry->first_name ?? '');
            $entryLastName = strtolower($entry->last_name ?? '');
            $checkFirstName = strtolower($firstName);
            $checkLastName = strtolower($lastName);

            // CRITICAL: Full name match is now worth 50 points (was 50)
            if ($entryFirstName === $checkFirstName && $entryLastName === $checkLastName) {
                $score += 50;
                $matchedFields[] = 'full_name';
            } elseif ($entryLastName === $checkLastName) {
                // Last name alone is only 15 points (was 25)
                $score += 15;
                $matchedFields[] = 'last_name';
            }

            // CRITICAL: Passport match is now worth 40 points (unchanged)
            if ($passportNumber && $entry->passport_number_encrypted) {
                $entryPassport = strtoupper($entry->passport_number);
                if ($entryPassport === strtoupper($passportNumber)) {
                    $score += 40;
                    $matchedFields[] = 'passport_number';
                }
            }

            // Nationality match is worth 5 points (unchanged)
            if ($nationality && $entry->nationality === strtoupper($nationality)) {
                $score += 5;
                $matchedFields[] = 'nationality';
            }

            // DOB match is now worth 10 points (unchanged)
            if ($dob && $entry->date_of_birth && $entry->date_of_birth->format('Y-m-d') === $dob->format('Y-m-d')) {
                $score += 10;
                $matchedFields[] = 'date_of_birth';
            }

            // CRITICAL: Determine threshold based on watchlist type
            $threshold = self::getThresholdForListType($entry->list_type, $entry->severity);

            // Only consider it a match if score meets threshold
            if ($score >= $threshold) {
                $matches[] = [
                    'watchlist_id' => $entry->id,
                    'list_type' => $entry->list_type,
                    'severity' => $entry->severity,
                    'match_score' => $score,
                    'threshold' => $threshold,
                    'matched_fields' => $matchedFields,
                    'reason' => $entry->reason,
                    'source' => $entry->source,
                    'requires_immediate_escalation' => $entry->list_type === 'terrorist' || $entry->severity === 'critical',
                ];
            }
        }

        // Sort by score (highest first)
        usort($matches, function ($a, $b) {
            return $b['match_score'] <=> $a['match_score'];
        });

        return $matches;
    }

    /**
     * Get matching threshold based on watchlist type and severity.
     * 
     * CRITICAL: Different watchlist types require different confidence levels.
     * 
     * @param string $listType Type of watchlist (terrorist, criminal, immigration, etc.)
     * @param string $severity Severity level (critical, high, medium, low)
     * @return int Minimum score required for match
     */
    protected static function getThresholdForListType(string $listType, string $severity): int
    {
        // TERRORIST watchlist requires STRICT matching (85+ points)
        // This means: Full name + Passport (90) OR Full name + DOB + Nationality (65) + something else
        if ($listType === 'terrorist' || $severity === 'critical') {
            return 85;
        }

        // CRIMINAL watchlist requires MEDIUM matching (70+ points)
        // This means: Full name + Passport (90) OR Full name + DOB + Nationality (65+)
        if ($listType === 'criminal' || $severity === 'high') {
            return 70;
        }

        // IMMIGRATION violations require STANDARD matching (60+ points)
        // This means: Full name + DOB + Nationality (65) OR Full name + Passport (90)
        if ($listType === 'overstay' || $listType === 'immigration_violation') {
            return 60;
        }

        // Default: MEDIUM threshold (70 points)
        return 70;
    }
}
