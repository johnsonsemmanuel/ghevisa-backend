<?php

namespace App\Services\Payment;

use App\Models\Application;
use App\Models\Payment;
use App\Services\Payment\Providers\PaystackProvider;
use App\Services\Payment\Providers\GcbProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Payment Orchestrator
 * 
 * Manages payment processing across multiple payment providers.
 * Routes payment requests to appropriate providers based on method and country.
 */
class PaymentOrchestrator
{
    protected array $providers = ['paystack', 'stripe', 'mobile_money', 'bank_transfer'];

    /**
     * Get available payment methods for a country.
     */
    public function getAvailablePaymentMethods(string $countryCode = 'GH'): array
    {
        $methods = [
            [
                'id' => 'paystack_card',
                'provider' => 'paystack',
                'name' => 'Card Payment',
                'description' => 'Pay with Visa, Mastercard, or Verve',
                'icon' => 'credit-card',
                'currencies' => ['GHS', 'NGN', 'USD'],
                'countries' => ['GH', 'NG', 'ZA', 'KE'],
            ],
            [
                'id' => 'paystack_mobile_money',
                'provider' => 'paystack',
                'name' => 'Mobile Money',
                'description' => 'Pay with MTN MoMo, Vodafone Cash, AirtelTigo Money',
                'icon' => 'smartphone',
                'currencies' => ['GHS'],
                'countries' => ['GH'],
            ],
            [
                'id' => 'stripe_card',
                'provider' => 'stripe',
                'name' => 'International Card',
                'description' => 'Pay with international Visa, Mastercard, Amex',
                'icon' => 'globe',
                'currencies' => ['USD', 'EUR', 'GBP'],
                'countries' => ['*'],
            ],
            [
                'id' => 'bank_transfer',
                'provider' => 'bank_transfer',
                'name' => 'Bank Transfer',
                'description' => 'Direct bank transfer (manual verification)',
                'icon' => 'building',
                'currencies' => ['GHS', 'USD'],
                'countries' => ['*'],
            ],
        ];

        // Filter methods available for the country
        return array_filter($methods, function ($method) use ($countryCode) {
            return in_array('*', $method['countries']) || in_array($countryCode, $method['countries']);
        });
    }

    /**
     * Initialize payment with selected method.
     */
    public function initializePayment(
        Application $application,
        string $paymentMethod,
        string $currency = 'GHS',
        ?string $callbackUrl = null
    ): array {
        // HIGH-06: Idempotency — if a recent pending payment exists, return it instead of creating new one
        $existingPending = Payment::where('application_id', $application->id)
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes(30))
            ->first();

        if ($existingPending) {
            // Return existing payment details so user can complete it
            // Check if we have stored authorization URL in metadata
            $authUrl = $existingPending->metadata['authorization_url'] ?? null;
            
            if ($authUrl) {
                return [
                    'success' => true,
                    'provider' => $existingPending->payment_provider,
                    'authorization_url' => $authUrl,
                    'reference' => $existingPending->transaction_reference,
                    'existing' => true,
                    'message' => 'Resuming existing payment session.',
                ];
            }
            
            // If no auth URL stored, mark old payment as failed and create new one
            $existingPending->update(['status' => 'failed']);
        }

        // Also reject if already paid
        $existingCompleted = Payment::where('application_id', $application->id)
            ->where('status', 'completed')
            ->first();

        if ($existingCompleted) {
            return [
                'success' => false,
                'message' => 'This application has already been paid for.',
            ];
        }

        $amount = $this->calculateAmount($application, $currency);

        return match ($paymentMethod) {
            'paystack_card', 'paystack_mobile_money' => $this->initializePaystack($application, $amount, $currency, $paymentMethod, $callbackUrl),
            'stripe_card' => $this->initializeStripe($application, $amount, $currency, $callbackUrl),
            'gcb_payment' => $this->initializeGcb($application, $amount, $currency, $callbackUrl),
            'bank_transfer' => $this->initializeBankTransfer($application, $amount, $currency),
            default => ['success' => false, 'message' => 'Invalid payment method'],
        };
    }

    /**
     * Initialize Paystack payment.
     */
    protected function initializePaystack(
        Application $application,
        float $amount,
        string $currency,
        string $method,
        ?string $callbackUrl
    ): array {
        $reference = $this->generateReference($application, 'PS');
        $channels = $method === 'paystack_mobile_money' ? ['mobile_money'] : ['card', 'bank'];
        $baseUrl = config('services.paystack.base_url', 'https://api.paystack.co');

        // For Paystack, use the visa type base_fee directly in GHS
        // The visa fees are stored in GHS, not USD
        $ghsAmount = $application->visaType->base_fee ?? 260.00;

        $payload = [
            'email' => $application->email ?: config('services.paystack.merchant_email'),
            'amount' => (int) round($ghsAmount * 100), // Paystack expects amount in pesewas
            'currency' => 'GHS',
            'reference' => $reference,
            'callback_url' => $callbackUrl ?? config('app.frontend_url') . '/payment/callback',
            'channels' => $channels,
            'metadata' => [
                'application_id' => $application->id,
                'reference_number' => $application->reference_number,
                'payment_method' => $method,
                'custom_fields' => [
                    ['display_name' => 'Application Reference', 'variable_name' => 'application_ref', 'value' => $application->reference_number],
                    ['display_name' => 'Applicant Name', 'variable_name' => 'applicant_name', 'value' => $application->first_name . ' ' . $application->last_name],
                ],
            ],
        ];

        try {
            Log::info('Paystack initialize request', ['payload' => array_merge($payload, ['amount_ghs' => $ghsAmount])]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.paystack.secret_key'),
                'Content-Type' => 'application/json',
            ])->post("{$baseUrl}/transaction/initialize", $payload);

            Log::info('Paystack initialize response', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            if ($response->successful() && $response->json('status')) {
                $data = $response->json('data');

                $this->createPaymentRecord($application, $reference, 'paystack', $ghsAmount, 'GHS', $method, [
                    'authorization_url' => $data['authorization_url'],
                    'access_code' => $data['access_code'] ?? null,
                ]);

                // Update application status to pending_payment
                if (in_array($application->status, ['draft', 'submitted_awaiting_payment'])) {
                    $application->update(['status' => 'pending_payment']);
                }

                return [
                    'success' => true,
                    'provider' => 'paystack',
                    'authorization_url' => $data['authorization_url'],
                    'reference' => $data['reference'],
                ];
            }

            Log::error('Paystack initialization failed', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return ['success' => false, 'message' => $response->json('message') ?? 'Payment initialization failed'];
        } catch (\Exception $e) {
            Log::error('Paystack error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return ['success' => false, 'message' => 'Payment service unavailable: ' . $e->getMessage()];
        }
    }

    /**
     * Initialize Stripe payment.
     */
    protected function initializeStripe(
        Application $application,
        float $amount,
        string $currency,
        ?string $callbackUrl
    ): array {
        $reference = $this->generateReference($application, 'ST');

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.stripe.secret_key'),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ])->asForm()->post('https://api.stripe.com/v1/checkout/sessions', [
                'payment_method_types[]' => 'card',
                'line_items[0][price_data][currency]' => strtolower($currency),
                'line_items[0][price_data][product_data][name]' => 'Ghana eVisa - ' . $application->visaType?->name,
                'line_items[0][price_data][unit_amount]' => (int) ($amount * 100),
                'line_items[0][quantity]' => 1,
                'mode' => 'payment',
                'success_url' => ($callbackUrl ?? config('app.frontend_url') . '/payment/callback') . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => config('app.frontend_url') . '/payment/cancelled',
                'client_reference_id' => $reference,
                'metadata[application_id]' => $application->id,
                'metadata[reference_number]' => $application->reference_number,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                $this->createPaymentRecord($application, $reference, 'stripe', $amount, $currency, 'stripe_card');

                return [
                    'success' => true,
                    'provider' => 'stripe',
                    'authorization_url' => $data['url'],
                    'session_id' => $data['id'],
                    'reference' => $reference,
                ];
            }

            return ['success' => false, 'message' => 'Stripe initialization failed'];
        } catch (\Exception $e) {
            Log::error('Stripe error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Payment service unavailable'];
        }
    }

    /**
     * Initialize GCB payment.
     */
    protected function initializeGcb(
        Application $application,
        float $amount,
        string $currency,
        ?string $callbackUrl
    ): array {
        try {
            $gcbService = app(\App\Services\Payment\Providers\GcbProvider::class);
            $result = $gcbService->initiateCheckout($application, $callbackUrl ?? config('app.frontend_url') . '/payment/callback');

            if ($result['success']) {
                // Update application status to pending_payment
                if (in_array($application->status, ['draft', 'submitted_awaiting_payment'])) {
                    $application->update(['status' => 'pending_payment']);
                }

                return [
                    'success' => true,
                    'provider' => 'gcb',
                    'authorization_url' => $result['checkout_url'],
                    'checkout_id' => $result['checkout_id'],
                    'merchant_ref' => $result['merchant_ref'],
                ];
            }

            return ['success' => false, 'message' => $result['error'] ?? 'GCB payment initialization failed'];
        } catch (\Exception $e) {
            Log::error('GCB payment error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'GCB payment service unavailable'];
        }
    }

    /**
     * Initialize bank transfer payment.
     */
    protected function initializeBankTransfer(
        Application $application,
        float $amount,
        string $currency
    ): array {
        $reference = $this->generateReference($application, 'BT');

        $this->createPaymentRecord($application, $reference, 'bank_transfer', $amount, $currency, 'bank_transfer');

        // Update application status to pending_payment (not draft)
        if (in_array($application->status, ['draft', 'submitted_awaiting_payment'])) {
            $application->update(['status' => 'pending_payment']);
        }

        // Bank details for manual transfer
        $bankDetails = [
            'bank_name' => 'Ghana Commercial Bank',
            'account_name' => 'Ghana Immigration Service - eVisa',
            'account_number' => '1234567890123',
            'branch' => 'Accra Main Branch',
            'swift_code' => 'GHCBGHAC',
        ];

        return [
            'success' => true,
            'provider' => 'bank_transfer',
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'bank_details' => $bankDetails,
            'instructions' => [
                'Use reference number as payment description',
                'Upload proof of payment after transfer',
                'Payment verification takes 1-2 business days',
            ],
        ];
    }

    /**
     * Verify payment status.
     */
    public function verifyPayment(string $reference): array
    {
        $payment = Payment::where('transaction_reference', $reference)
            ->orWhere('provider_reference', $reference)
            ->orWhere('merchant_ref', $reference)
            ->orWhere('checkout_id', $reference)
            ->first();

        if (!$payment) {
            return ['success' => false, 'status' => 'not_found'];
        }

        if ($payment->status === 'completed') {
            // Ensure onPaymentSuccess was called for proper routing
            // This handles cases where payment was completed but application wasn't routed
            if ($payment->application && in_array($payment->application->status, ['submitted_awaiting_payment', 'pending_payment'])) {
                $this->onPaymentSuccess($payment);
            }
            return ['success' => true, 'status' => 'completed', 'payment' => $payment];
        }

        // Verify with provider
        return match ($payment->payment_provider) {
            'paystack' => $this->verifyPaystack($reference, $payment),
            'stripe' => $this->verifyStripe($reference, $payment),
            'gcb', 'gcb_test' => $this->verifyGcb($reference, $payment),
            'bank_transfer' => ['success' => true, 'status' => $payment->status, 'payment' => $payment],
            default => ['success' => false, 'status' => 'unknown_provider'],
        };
    }

    /**
     * Verify Paystack payment.
     */
    protected function verifyPaystack(string $reference, Payment $payment): array
    {
        try {
            Log::info('Paystack verification starting', ['reference' => $reference, 'payment_id' => $payment->id]);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.paystack.secret_key'),
            ])->get("https://api.paystack.co/transaction/verify/{$reference}");

            Log::info('Paystack verification response', [
                'status_code' => $response->status(),
                'successful' => $response->successful(),
                'json_status' => $response->json('status'),
                'data_status' => $response->json('data.status'),
            ]);

            if ($response->successful() && $response->json('status')) {
                $data = $response->json('data');

                if ($data['status'] === 'success') {
                    $payment->update([
                        'status' => 'completed',
                        'paid_at' => now(),
                        'provider_reference' => $data['reference'],
                    ]);

                    Log::info('Calling onPaymentSuccess for Paystack payment', [
                        'payment_id' => $payment->id,
                        'application_id' => $payment->application_id,
                        'application_status_before' => $payment->application->status ?? 'unknown',
                    ]);

                    $this->onPaymentSuccess($payment);

                    Log::info('Paystack payment verified successfully', [
                        'payment_id' => $payment->id,
                        'application_status_after' => $payment->application->fresh()->status ?? 'unknown',
                    ]);
                    return ['success' => true, 'status' => 'completed', 'payment' => $payment->fresh()];
                }
                
                Log::warning('Paystack payment not successful', ['data_status' => $data['status']]);
            }

            Log::warning('Paystack verification failed', ['response' => $response->body()]);
            return ['success' => false, 'status' => $payment->status];
        } catch (\Exception $e) {
            Log::error('Paystack verify error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return ['success' => false, 'status' => 'verification_failed'];
        }
    }

    /**
     * Verify GCB payment.
     */
    protected function verifyGcb(string $reference, Payment $payment): array
    {
        try {
            Log::info('GCB verification starting', ['reference' => $reference, 'payment_id' => $payment->id]);
            
            // For test mode, simulate successful payment
            if ($payment->payment_provider === 'gcb_test') {
                Log::info('GCB test mode verification - simulating success');
                
                $payment->update([
                    'status' => 'completed',
                    'paid_at' => now(),
                    'provider_reference' => $reference,
                ]);

                $this->onPaymentSuccess($payment);

                Log::info('GCB test payment verified successfully', ['payment_id' => $payment->id]);
                return ['success' => true, 'status' => 'completed', 'payment' => $payment->fresh()];
            }

            // For production GCB, use the GCB provider to check status
            $gcbProvider = app(\App\Services\Payment\Providers\GcbProvider::class);
            $result = $gcbProvider->checkTransactionStatus($payment->checkout_id ?? $reference);

            if ($result['success'] && isset($result['status'])) {
                $mappedStatus = $gcbProvider->mapStatusCode($result['status']);
                
                if ($mappedStatus === 'completed') {
                    $payment->update([
                        'status' => 'completed',
                        'paid_at' => now(),
                        'provider_reference' => $result['transactionId'] ?? $reference,
                        'gateway_response' => array_merge($payment->gateway_response ?? [], $result),
                    ]);

                    $this->onPaymentSuccess($payment);

                    Log::info('GCB payment verified successfully', ['payment_id' => $payment->id]);
                    return ['success' => true, 'status' => 'completed', 'payment' => $payment->fresh()];
                } else {
                    // Update payment status but don't mark as completed
                    $payment->update([
                        'status' => $mappedStatus,
                        'gateway_response' => array_merge($payment->gateway_response ?? [], $result),
                    ]);
                    
                    Log::info('GCB payment status updated', ['payment_id' => $payment->id, 'status' => $mappedStatus]);
                    return ['success' => false, 'status' => $mappedStatus, 'payment' => $payment->fresh()];
                }
            }

            Log::warning('GCB verification failed', ['result' => $result]);
            return ['success' => false, 'status' => $payment->status];
        } catch (\Exception $e) {
            Log::error('GCB verify error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return ['success' => false, 'status' => 'verification_failed'];
        }
    }

    /**
     * Verify Stripe payment.
     */
    protected function verifyStripe(string $reference, Payment $payment): array
    {
        try {
            // For Stripe, we typically verify via webhooks
            // This is a fallback check
            return ['success' => true, 'status' => $payment->status, 'payment' => $payment];
        } catch (\Exception $e) {
            Log::error('Stripe verify error: ' . $e->getMessage());
            return ['success' => false, 'status' => 'verification_failed'];
        }
    }

    /**
     * Confirm bank transfer manually.
     */
    public function confirmBankTransfer(string $reference, string $proofUrl, ?int $confirmedBy = null): array
    {
        $payment = Payment::where('transaction_reference', $reference)
            ->where('payment_provider', 'bank_transfer')
            ->first();

        if (!$payment) {
            return ['success' => false, 'message' => 'Payment not found'];
        }

        $payment->update([
            'status' => 'completed',
            'paid_at' => now(),
            'metadata' => array_merge($payment->metadata ?? [], [
                'proof_url' => $proofUrl,
                'confirmed_by' => $confirmedBy,
                'confirmed_at' => now()->toIso8601String(),
            ]),
        ]);

        $this->onPaymentSuccess($payment);

        return ['success' => true, 'payment' => $payment->fresh()];
    }

    /**
     * Handle successful payment.
     * Transitions: submitted_awaiting_payment/pending_payment → paid_submitted → submitted → under_review
     * Automatically routes the application to GIS/MFA for review.
     */
    protected function onPaymentSuccess(Payment $payment): void
    {
        $application = $payment->application;
        if (!$application) return;

        // HIGH-05/PAY-02: Amount Verification
        // Verify the paid amount matches the expected amount
        $expectedAmount = $this->calculateAmount($application, $payment->currency);

        // Allow a small tolerance for rounding issues (e.g. 0.05)
        if ($payment->amount < ($expectedAmount - 0.05)) {
            Log::error("Payment amount mismatch for application {$application->reference_number}. Paid: {$payment->amount}, Expected: {$expectedAmount}");
            $payment->update(['status' => 'failed']);
            return; // Abort processing this payment
        }

        // Store total fee
        $application->update(['total_fee' => $payment->amount]);

        $applicationService = app(\App\Services\Application\ApplicationService::class);

        // Use centralized ApplicationService for proper status transition + audit trail
        if (in_array($application->status, ['submitted_awaiting_payment', 'pending_payment'])) {
            $applicationService->confirmPayment($application);
            $application->refresh();
        }

        // After payment confirmation, the application is now under review
        // No need to submit again as it's already in the correct status
        Log::info("Payment completed for application {$application->reference_number}", [
            'application_id' => $application->id,
            'new_status' => $application->status,
        ]);
    }

    /**
     * Create payment record.
     */
    protected function createPaymentRecord(
        Application $application,
        string $reference,
        string $provider,
        float $amount,
        string $currency,
        string $method,
        array $extraMetadata = []
    ): Payment {
        return Payment::create([
            'application_id' => $application->id,
            'user_id' => $application->user_id,
            'transaction_reference' => $reference,
            'payment_provider' => $provider,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'pending',
            'metadata' => array_merge(['payment_method' => $method], $extraMetadata),
        ]);
    }

    /**
     * Calculate amount in specified currency using unified PricingService.
     */
    protected function calculateAmount(Application $application, string $currency): float
    {
        // For GHS payments (Paystack), use the visa type base_fee directly
        if ($currency === 'GHS') {
            return $application->visaType->base_fee ?? 260.00;
        }
        
        // For other currencies, use the pricing service
        $pricingService = app(\App\Services\Integration\PricingService::class);
        $pricing = $pricingService->calculatePrice($application);
        
        $amountUsd = $pricing['total'];

        // HIGH-05: Use configurable exchange rates from config/exchange_rates.php
        $rates = config('services.exchange_rates', ['USD' => 1, 'GHS' => 12.5, 'EUR' => 0.92, 'GBP' => 0.79]);
        $rate = $rates[$currency] ?? 1;

        return round($amountUsd * $rate, 2);
    }

    /**
     * Generate payment reference.
     */
    protected function generateReference(Application $application, string $prefix): string
    {
        return $prefix . '-' . $application->reference_number . '-' . Str::random(6);
    }

    /**
     * Handle webhook from payment provider.
     */
    public function handleWebhook(array $payload, string $provider): bool
    {
        try {
            Log::info('Processing payment webhook', [
                'provider' => $provider,
                'event' => $payload['event'] ?? 'unknown',
                'data' => $payload['data'] ?? [],
            ]);

            if ($provider === 'paystack') {
                return $this->handlePaystackWebhook($payload);
            }

            if ($provider === 'stripe') {
                return $this->handleStripeWebhook($payload);
            }

            Log::warning('Unknown webhook provider', ['provider' => $provider]);
            return false;
        } catch (\Exception $e) {
            Log::error('Webhook processing error', [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Handle Paystack webhook.
     */
    protected function handlePaystackWebhook(array $payload): bool
    {
        $event = $payload['event'] ?? '';
        $data = $payload['data'] ?? [];

        // Only process charge.success events
        if ($event !== 'charge.success') {
            Log::info('Ignoring Paystack webhook event', ['event' => $event]);
            return true;
        }

        $reference = $data['reference'] ?? null;
        if (!$reference) {
            Log::warning('Paystack webhook missing reference', ['data' => $data]);
            return false;
        }

        // Find payment by reference
        $payment = Payment::where('transaction_reference', $reference)
            ->orWhere('provider_reference', $reference)
            ->first();

        if (!$payment) {
            Log::warning('Payment not found for Paystack webhook', ['reference' => $reference]);
            return false;
        }

        // If payment is already completed, skip processing
        if ($payment->status === 'completed') {
            Log::info('Payment already completed, skipping webhook', ['payment_id' => $payment->id]);
            return true;
        }

        // Verify payment status
        if ($data['status'] === 'success') {
            $payment->update([
                'status' => 'completed',
                'paid_at' => now(),
                'provider_reference' => $reference,
                'gateway_response' => $data,
            ]);

            $this->onPaymentSuccess($payment);

            Log::info('Paystack webhook processed successfully', [
                'payment_id' => $payment->id,
                'reference' => $reference,
            ]);

            return true;
        }

        Log::warning('Paystack webhook payment not successful', [
            'reference' => $reference,
            'status' => $data['status'] ?? 'unknown',
        ]);

        return false;
    }

    /**
     * Handle Stripe webhook.
     */
    protected function handleStripeWebhook(array $payload): bool
    {
        $event = $payload['type'] ?? '';
        $data = $payload['data']['object'] ?? [];

        // Only process checkout.session.completed events
        if ($event !== 'checkout.session.completed') {
            Log::info('Ignoring Stripe webhook event', ['event' => $event]);
            return true;
        }

        $sessionId = $data['id'] ?? null;
        $clientReferenceId = $data['client_reference_id'] ?? null;

        if (!$clientReferenceId) {
            Log::warning('Stripe webhook missing client_reference_id', ['data' => $data]);
            return false;
        }

        // Find payment by reference
        $payment = Payment::where('transaction_reference', $clientReferenceId)->first();

        if (!$payment) {
            Log::warning('Payment not found for Stripe webhook', ['reference' => $clientReferenceId]);
            return false;
        }

        // If payment is already completed, skip processing
        if ($payment->status === 'completed') {
            Log::info('Payment already completed, skipping webhook', ['payment_id' => $payment->id]);
            return true;
        }

        // Update payment status
        $payment->update([
            'status' => 'completed',
            'paid_at' => now(),
            'provider_reference' => $sessionId,
            'gateway_response' => $data,
        ]);

        $this->onPaymentSuccess($payment);

        Log::info('Stripe webhook processed successfully', [
            'payment_id' => $payment->id,
            'reference' => $clientReferenceId,
        ]);

        return true;
    }
}
