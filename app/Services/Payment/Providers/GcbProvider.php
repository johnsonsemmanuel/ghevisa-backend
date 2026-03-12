<?php

namespace App\Services\Payment\Providers;

use App\Models\Application;
use App\Models\Payment;
use App\Services\Payment\Contracts\PaymentProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GcbProvider implements PaymentProviderInterface
{
    protected string $baseUrl;
    protected string $apiKey;

    protected bool $testMode;

    public function __construct()
    {
        $this->baseUrl = config('services.gcb.base_url', 'https://epayuat.gcbltd.com:98/paymentgateway');
        $this->apiKey = config('services.gcb.api_key', '');
        
        // CRITICAL SECURITY FIX: NEVER default to test mode in production
        // Test mode allows FREE VISAS - this is a CRITICAL vulnerability
        // 
        // If API key is not configured in production, the system MUST FAIL LOUDLY
        // rather than silently accepting fake payments.
        //
        // Real-world impact:
        // - Applicants could get free visas
        // - Government loses revenue
        // - System abuse at national scale
        // - Financial audit failures
        // - National embarrassment
        
        if (config('app.env') === 'production') {
            // Production MUST have API key configured
            if (empty($this->apiKey)) {
                Log::critical('GCB Payment Gateway: API key not configured in PRODUCTION', [
                    'environment' => config('app.env'),
                    'base_url' => $this->baseUrl,
                ]);
                
                throw new \RuntimeException(
                    'CRITICAL: GCB Payment Gateway API key not configured. ' .
                    'Payment system is DISABLED to prevent free visas. ' .
                    'Configure GCB_API_KEY in .env immediately.'
                );
            }
            
            // Production MUST NOT be in test mode
            if (config('services.gcb.test_mode', false)) {
                Log::critical('GCB Payment Gateway: Test mode enabled in PRODUCTION', [
                    'environment' => config('app.env'),
                ]);
                
                throw new \RuntimeException(
                    'CRITICAL: GCB Payment Gateway test mode is enabled in PRODUCTION. ' .
                    'This allows FREE VISAS. Set GCB_TEST_MODE=false immediately.'
                );
            }
            
            // In production, test mode is ALWAYS false
            $this->testMode = false;
            
            Log::info('GCB Payment Gateway: Production mode active', [
                'base_url' => $this->baseUrl,
                'api_key_configured' => !empty($this->apiKey),
            ]);
        } else {
            // Non-production: test mode only if explicitly enabled OR no API key
            $this->testMode = config('services.gcb.test_mode', empty($this->apiKey));
            
            if ($this->testMode) {
                Log::warning('GCB Payment Gateway: TEST MODE ACTIVE', [
                    'environment' => config('app.env'),
                    'reason' => empty($this->apiKey) ? 'No API key configured' : 'Explicitly enabled',
                    'warning' => 'NO REAL PAYMENTS WILL BE PROCESSED',
                ]);
            }
        }
    }

    /**
     * Initiate a checkout session with GCB Payment Gateway
     * 
     * CRITICAL SECURITY FIX: Idempotent payment initiation.
     * 
     * If payment already initiated with same idempotency key, returns existing payment.
     * This prevents double charging when user clicks "Pay Now" multiple times.
     * 
     * @param Application $application
     * @param string $callbackUrl
     * @param string|null $idempotencyKey Optional idempotency key (generated if not provided)
     * @return array
     */
    public function initiateCheckout(Application $application, string $callbackUrl, ?string $idempotencyKey = null): array
    {
        // Generate idempotency key if not provided
        if (!$idempotencyKey) {
            $idempotencyKey = Payment::generateIdempotencyKey($application->user_id, $application->id);
        }

        // CRITICAL: Check if payment already initiated with this idempotency key
        $existingPayment = Payment::findByIdempotencyKey($idempotencyKey);
        
        if ($existingPayment) {
            Log::info('Payment already initiated (idempotency)', [
                'idempotency_key' => $idempotencyKey,
                'payment_id' => $existingPayment->id,
                'status' => $existingPayment->status,
            ]);

            // If payment is completed, return success
            if ($existingPayment->status === 'completed') {
                return [
                    'success' => true,
                    'checkout_url' => null,
                    'checkout_id' => $existingPayment->checkout_id,
                    'merchant_ref' => $existingPayment->merchant_ref,
                    'payment_id' => $existingPayment->id,
                    'already_completed' => true,
                ];
            }

            // If payment is pending, return existing checkout URL
            if ($existingPayment->status === 'pending' && $existingPayment->checkout_url) {
                return [
                    'success' => true,
                    'checkout_url' => $existingPayment->checkout_url,
                    'checkout_id' => $existingPayment->checkout_id,
                    'merchant_ref' => $existingPayment->merchant_ref,
                    'payment_id' => $existingPayment->id,
                    'idempotent_return' => true,
                ];
            }

            // If payment failed and can retry, allow new attempt
            if ($existingPayment->status === 'failed' && $existingPayment->canRetry()) {
                $existingPayment->incrementRetry();
                Log::info('Retrying failed payment', [
                    'payment_id' => $existingPayment->id,
                    'retry_count' => $existingPayment->retry_count,
                ]);
                // Continue with new payment attempt
            } else if ($existingPayment->status === 'failed') {
                return [
                    'success' => false,
                    'error' => 'Payment failed and maximum retries exceeded. Please contact support.',
                    'payment_id' => $existingPayment->id,
                ];
            }
        }

        $merchantRef = $this->generateMerchantRef($application);
        $amount = (float) $application->total_fee;

        $payload = [
            'merchantRef' => $merchantRef,
            'amount' => $amount,
            'currency' => 'GHS',
            'description' => "Ghana e-Visa Application - {$application->reference_number}",
            'paymentOption' => null,
            'callbackUrl' => $callbackUrl,
        ];

        // Test mode: Return mock response when API key is not configured
        if ($this->testMode) {
            Log::warning('GCB Test Mode: Generating mock checkout URL', [
                'application_id' => $application->id,
                'merchant_ref' => $merchantRef,
            ]);

            $checkoutId = 'TEST_' . strtoupper(Str::random(16));
            $mockCheckoutUrl = config('app.frontend_url') . '/payment/gcb-test?merchantRef=' . $merchantRef . '&checkoutId=' . $checkoutId;

            // DB-03: Use create instead of updateOrCreate to preserve payment history
            // PAY-05: Mark test payments distinctly
            // CRITICAL: Add idempotency key
            $payment = Payment::create([
                'application_id' => $application->id,
                'user_id' => $application->user_id,
                'idempotency_key' => $idempotencyKey,
                'merchant_ref' => $merchantRef,
                'checkout_id' => $checkoutId,
                'checkout_url' => $mockCheckoutUrl,
                'transaction_reference' => $merchantRef,
                'payment_provider' => 'gcb_test',
                'amount' => $amount,
                'currency' => 'GHS',
                'status' => 'pending',
                'gateway' => 'gcb',
                'gateway_response' => [
                    'test_mode' => true,
                    'checkOutId' => $checkoutId,
                    'checkOutUrl' => $mockCheckoutUrl,
                ],
            ]);

            return [
                'success' => true,
                'checkout_url' => $mockCheckoutUrl,
                'checkout_id' => $checkoutId,
                'merchant_ref' => $merchantRef,
                'payment_id' => $payment->id,
                'test_mode' => true,
            ];
        }

        try {
            Log::info('GCB Checkout request', [
                'application_id' => $application->id,
                'merchant_ref' => $merchantRef,
                'amount' => $amount,
                'payload' => $payload,
            ]);

            $response = Http::timeout(30)->withHeaders([
                'X-Api-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl}/checkout", $payload);

            Log::info('GCB Checkout response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if ($response->successful()) {
                $data = $response->json();

                // Validate required fields in response
                if (empty($data['checkOutUrl'])) {
                    Log::error('GCB Checkout missing checkOutUrl', ['response' => $data]);
                    return [
                        'success' => false,
                        'error' => 'Payment gateway returned invalid response',
                    ];
                }

                // DB-03: Use create to preserve payment history
                // CRITICAL: Add idempotency key
                $payment = Payment::create([
                    'application_id' => $application->id,
                    'user_id' => $application->user_id,
                    'idempotency_key' => $idempotencyKey,
                    'merchant_ref' => $merchantRef,
                    'checkout_id' => $data['checkOutId'] ?? null,
                    'checkout_url' => $data['checkOutUrl'] ?? null,
                    'transaction_reference' => $merchantRef,
                    'payment_provider' => 'gcb',
                    'amount' => $amount,
                    'currency' => 'GHS',
                    'status' => 'pending',
                    'gateway' => 'gcb',
                    'gateway_response' => $data,
                ]);

                Log::info('GCB Checkout initiated', [
                    'application_id' => $application->id,
                    'merchant_ref' => $merchantRef,
                    'checkout_id' => $data['checkOutId'] ?? null,
                    'checkout_url' => $data['checkOutUrl'] ?? null,
                ]);

                return [
                    'success' => true,
                    'checkout_url' => $data['checkOutUrl'],
                    'checkout_id' => $data['checkOutId'] ?? null,
                    'merchant_ref' => $merchantRef,
                    'payment_id' => $payment->id,
                ];
            }

            // Handle error responses
            $errorData = $response->json();
            $errorMessage = $errorData['message'] ?? $errorData['error'] ?? 'Unknown error';
            
            Log::error('GCB Checkout failed', [
                'application_id' => $application->id,
                'status' => $response->status(),
                'response' => $response->body(),
                'error_message' => $errorMessage,
            ]);

            return [
                'success' => false,
                'error' => 'Payment gateway error: ' . $errorMessage,
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('GCB Checkout connection failed', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Unable to connect to payment gateway. Please try again.',
            ];
        } catch (\Exception $e) {
            Log::error('GCB Checkout exception', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'Payment gateway error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check transaction status with GCB Payment Gateway
     */
    public function checkTransactionStatus(string $checkoutId): array
    {
        try {
            $response = Http::withHeaders([
                'X-Api-Key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->get("{$this->baseUrl}/transactions/{$checkoutId}/status");

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'merchant_ref' => $data['merchantRef'] ?? null,
                    'status_code' => $data['status'] ?? null,
                    'status' => $this->mapStatusCode($data['status'] ?? ''),
                    'bank_ref' => $data['bankRef'] ?? null,
                    'time_completed' => $data['timeCompleted'] ?? null,
                    'payment_option' => $data['paymentOption'] ?? null,
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to check transaction status',
            ];
        } catch (\Exception $e) {
            Log::error('GCB Status check exception', [
                'checkout_id' => $checkoutId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Payment gateway connection failed',
            ];
        }
    }

    /**
     * Process callback from GCB Payment Gateway
     */
    public function processCallback(array $data): array
    {
        $merchantRef = $data['merchantRef'] ?? null;
        $statusCode = $data['statusCode'] ?? null;
        $bankRef = $data['bankRef'] ?? null;
        $timeCompleted = $data['timeCompleted'] ?? null;
        $paymentOption = $data['paymentOption'] ?? null;

        if (!$merchantRef) {
            return ['success' => false, 'error' => 'Missing merchant reference'];
        }

        $payment = Payment::where('merchant_ref', $merchantRef)->first();

        if (!$payment) {
            Log::error('GCB Callback: Payment not found', ['merchant_ref' => $merchantRef]);
            return ['success' => false, 'error' => 'Payment not found'];
        }

        $status = $this->mapStatusCode($statusCode);

        $payment->update([
            'status' => $status,
            'bank_ref' => $bankRef,
            'payment_option' => $paymentOption,
            'completed_at' => $timeCompleted ? \Carbon\Carbon::parse($timeCompleted) : null,
            'gateway_response' => array_merge($payment->gateway_response ?? [], [
                'callback' => $data,
            ]),
        ]);

        Log::info('GCB Callback processed', [
            'merchant_ref' => $merchantRef,
            'status' => $status,
            'bank_ref' => $bankRef,
        ]);

        return [
            'success' => true,
            'payment_id' => $payment->id,
            'application_id' => $payment->application_id,
            'status' => $status,
        ];
    }

    /**
     * Generate unique merchant reference
     */
    protected function generateMerchantRef(Application $application): string
    {
        // Format: GH + date + random (max 20 chars)
        $date = now()->format('ymd');
        $random = strtoupper(Str::random(8));
        return "GH{$date}{$random}";
    }

    /**
     * Map GCB status code to internal status
     */
    public function mapStatusCode(?string $code): string
    {
        return match ($code) {
            '00' => 'completed',
            '01' => 'pending',
            '02' => 'failed',
            '03' => 'failed',   // Checkout URL Expired
            '04' => 'failed',   // Checkout ID not found
            '05' => 'failed',   // Internal Error
            default => 'pending',
        };
    }

    /**
     * Get status description
     */
    public static function getStatusDescription(string $code): string
    {
        return match ($code) {
            '00' => 'Payment Successful',
            '01' => 'Payment Pending',
            '02' => 'Payment Failed',
            '03' => 'Checkout URL Expired',
            '04' => 'Checkout ID not found or caller error',
            '05' => 'Internal Error',
            default => 'Unknown Status',
        };
    }
}

    /**
     * Get provider name.
     */
    public function getName(): string
    {
        return 'gcb';
    }

    /**
     * Check if provider is available for given country.
     */
    public function isAvailableForCountry(string $countryCode): bool
    {
        // GCB is only available in Ghana
        return strtoupper($countryCode) === 'GH';
    }

    /**
     * Initialize a payment transaction (interface method).
     */
    public function initializePayment(
        Application $application,
        float $amount,
        string $currency,
        string $callbackUrl
    ): array {
        // Use existing initiateCheckout method
        return $this->initiateCheckout($application, $callbackUrl);
    }

    /**
     * Handle webhook callback from provider (interface method).
     */
    public function handleWebhook(array $payload): array
    {
        // GCB uses polling instead of webhooks
        // Return success to acknowledge receipt
        return [
            'success' => true,
            'reference' => $payload['reference'] ?? '',
            'status' => 'pending',
            'message' => 'GCB uses polling for status updates',
        ];
    }
}
