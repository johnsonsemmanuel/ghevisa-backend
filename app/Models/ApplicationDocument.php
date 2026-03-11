<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationDocument extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'application_id',
        'uuid',  // SECURITY: UUID for IDOR protection
        'document_type',
        'original_filename',
        'stored_path',
        'mime_type',
        'file_size',
        // FIX #10: OCR fields removed until OCR service is implemented
        // 'ocr_status',
        // 'ocr_result',
        'verification_status',
        'rejection_reason',
    ];

    protected static function boot()
    {
        parent::boot();
        
        // SECURITY FIX: Auto-generate UUID on creation
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function needsReupload(): bool
    {
        return $this->verification_status === 'reupload_requested';
        // FIX #10: Removed OCR status check until OCR is implemented
        // || $this->ocr_status === 'failed'
    }

    /**
     * FIX #10: OCR is not yet implemented.
     * This method always returns false until OCR service is deployed.
     */
    public function isOcrVerified(): bool
    {
        return false; // OCR not implemented
    }

    /**
     * FIX #10: Get verification status display text.
     * Clear messaging that OCR is not implemented.
     */
    public function getVerificationStatusText(): string
    {
        return match($this->verification_status) {
            'pending' => 'Pending Manual Review',
            'verified' => 'Verified by Officer',
            'rejected' => 'Rejected - Reupload Required',
            'reupload_requested' => 'Reupload Requested',
            default => 'Awaiting Review',
        };
    }
}
