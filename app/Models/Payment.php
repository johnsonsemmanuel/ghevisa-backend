<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasFactory, Auditable, SoftDeletes;

    protected $fillable = [
        'application_id',
        'user_id',
        'idempotency_key',
        'transaction_reference',
        'payment_provider',
        'provider_reference',
        'amount',
        'currency',
        'status',
        'provider_response',
        'paid_at',
        'merchant_ref',
        'checkout_id',
        'checkout_url',
        'bank_ref',
        'payment_option',
        'payment_method',
        'gateway',
        'gateway_response',
        'completed_at',
        'retry_count',
        'last_retry_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'            => 'decimal:2',
            'provider_response' => 'array',
            'gateway_response'  => 'array',
            'paid_at'           => 'datetime',
            'completed_at'      => 'datetime',
            'last_retry_at'     => 'datetime',
        ];
    }

    /**
     * Generate idempotency key for payment.
     * 
     * CRITICAL SECURITY FIX: Prevents double charging.
     * 
     * Format: {user_id}_{application_id}_{timestamp}_{random}
     * 
     * @param int $userId
     * @param int $applicationId
     * @return string
     */
    public static function generateIdempotencyKey(int $userId, int $applicationId): string
    {
        return sprintf(
            '%d_%d_%s_%s',
            $userId,
            $applicationId,
            now()->format('YmdHis'),
            bin2hex(random_bytes(8))
        );
    }

    /**
     * Find payment by idempotency key.
     * 
     * @param string $key
     * @return Payment|null
     */
    public static function findByIdempotencyKey(string $key): ?Payment
    {
        return self::where('idempotency_key', $key)->first();
    }

    /**
     * Check if payment can be retried.
     * 
     * @return bool
     */
    public function canRetry(): bool
    {
        // Can retry if status is failed or pending
        if (!in_array($this->status, ['failed', 'pending'])) {
            return false;
        }

        // Limit retries to 3 attempts
        if ($this->retry_count >= 3) {
            return false;
        }

        // Must wait at least 5 minutes between retries
        if ($this->last_retry_at && $this->last_retry_at->gt(now()->subMinutes(5))) {
            return false;
        }

        return true;
    }

    /**
     * Increment retry count.
     * 
     * @return void
     */
    public function incrementRetry(): void
    {
        $this->update([
            'retry_count' => $this->retry_count + 1,
            'last_retry_at' => now(),
        ]);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
