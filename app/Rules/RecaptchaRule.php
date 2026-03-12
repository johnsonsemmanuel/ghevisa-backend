<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SECURITY FIX: reCAPTCHA v3 validation rule.
 * 
 * Validates reCAPTCHA token to prevent bot submissions.
 */
class RecaptchaRule implements ValidationRule
{
    protected string $action;
    
    public function __construct(string $action = 'submit')
    {
        $this->action = $action;
    }
    
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Skip if reCAPTCHA is disabled (development/testing)
        if (!config('security.recaptcha.enabled', false)) {
            return;
        }
        
        // Skip if no secret key configured
        $secretKey = config('services.recaptcha.secret_key');
        if (empty($secretKey)) {
            Log::warning('reCAPTCHA validation skipped: No secret key configured');
            return;
        }
        
        try {
            $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secretKey,
                'response' => $value,
                'remoteip' => request()->ip(),
            ]);
            
            if (!$response->successful()) {
                Log::error('reCAPTCHA API request failed', [
                    'status' => $response->status(),
                ]);
                
                // Fail-open in case of API issues (don't block legitimate users)
                if (config('app.env') === 'production') {
                    return;
                }
                
                $fail('reCAPTCHA verification failed. Please try again.');
                return;
            }
            
            $data = $response->json();
            
            // Check if verification was successful
            if (!($data['success'] ?? false)) {
                $fail('reCAPTCHA verification failed. Please try again.');
                return;
            }
            
            // Check score (reCAPTCHA v3)
            $score = $data['score'] ?? 0;
            $threshold = config('security.recaptcha.score_threshold', 0.5);
            
            if ($score < $threshold) {
                Log::warning('reCAPTCHA score below threshold', [
                    'score' => $score,
                    'threshold' => $threshold,
                    'action' => $this->action,
                    'ip' => request()->ip(),
                ]);
                
                $fail('Suspicious activity detected. Please try again or contact support.');
                return;
            }
            
            // Verify action matches
            if (isset($data['action']) && $data['action'] !== $this->action) {
                Log::warning('reCAPTCHA action mismatch', [
                    'expected' => $this->action,
                    'received' => $data['action'],
                ]);
                
                $fail('reCAPTCHA verification failed. Please refresh and try again.');
                return;
            }
            
        } catch (\Exception $e) {
            Log::error('reCAPTCHA validation exception', [
                'error' => $e->getMessage(),
            ]);
            
            // Fail-open in production to avoid blocking legitimate users
            if (config('app.env') === 'production') {
                return;
            }
            
            $fail('reCAPTCHA verification failed. Please try again.');
        }
    }
}
