<?php

namespace App\Rules;

use App\Models\Application;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * SECURITY FIX: Validate that passport number doesn't have active application.
 * 
 * Prevents duplicate applications with same passport number.
 */
class UniqueActivePassport implements ValidationRule
{
    protected ?int $exceptApplicationId;
    
    public function __construct(?int $exceptApplicationId = null)
    {
        $this->exceptApplicationId = $exceptApplicationId;
    }
    
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Only check if duplicate detection is enabled
        if (!config('security.duplicate_detection.enabled', true)) {
            return;
        }
        
        $activeStatuses = config('security.duplicate_detection.active_statuses', [
            'draft',
            'submitted',
            'submitted_awaiting_payment',
            'pending_payment',
            'paid_submitted',
            'under_review',
            'pending_approval',
            'approved',
            'issued',
        ]);
        
        // Check for existing application with this passport
        $query = Application::whereIn('status', $activeStatuses);
        
        // Exclude current application if updating
        if ($this->exceptApplicationId) {
            $query->where('id', '!=', $this->exceptApplicationId);
        }
        
        // Get all applications and check decrypted passport numbers
        $existingApplications = $query->get();
        
        foreach ($existingApplications as $app) {
            if (strtoupper(trim($app->passport_number)) === strtoupper(trim($value))) {
                $fail("This passport already has an active visa application (Reference: {$app->reference_number})");
                return;
            }
        }
    }
}
