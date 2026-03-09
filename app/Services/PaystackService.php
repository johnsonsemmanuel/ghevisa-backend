<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaystackService
{
    protected string $baseUrl = 'https://api.paystack.co';
    protected string $secretKey;
    protected string $publicKey;

    public function __construct()
    {
        $this->secretKey = config('services.paystack.secret_key');
        $this->publicKey = config('services.paystack.public_key');
    }

    /**
     * Initialize a payment transaction.
     */
    public function initializeTransaction(Application $application, string $callbackUrl = null): array
    {
        $email = $application->email;
        $amount = $this->calculateTotalAmount($application);
        $reference = $this->generateReference($application);

        $payload = [
            'email' => $email,
            'amount' => (int) ($amount * 100), // Paystack expects amount in kobo/pesewas
            'currency' => 'GHS',
            'reference' => $reference,
            'callback_url' => $callbackUrl ?? config('app.frontend_url') . '/payment/callback',
            'metadata' => [
                'application_id' => $application->id,
                'reference_number' => $application->reference_number,
                'visa_type' => $application->visaType?->name,
                'applicant_name' => $application->first_name . ' ' . $application->last_name,
            ],
            'channels' => ['card', 'bank', 'mobile_money', 'qr'],
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/transaction/initialize', $payload);

            if ($response->successful() && $response->json('status')) {
                $data = $response->json('data');

                // Create payment record
                Payment::create([
                    'application_id' => $application->id,
                    'user_id' => $application->user_id,
                    'transaction_reference' => $reference,
                    'payment_provider' => 'paystack',
                    'provider_reference' => $data['reference'],
                    'amount' => $amount,
                    'currency' => 'GHS',
                    'status' => 'pending',
                ]);

                // Update application status
                $application->update([
                    'status' => 'pending_payment',
                    'total_fee' => $amount,
                ]);

                return [
                    'success' => true,
                    'authorization_url' => $data['authorization_url'],
                    'access_code' => $data['access_code'],
                    'reference' => $data['reference'],
                ];
            }

            Log::error('Paystack initialization failed', [
                'response' => $response->json(),
                'application_id' => $application->id,
            ]);

            return [
                'success' => false,
                'message' => $response->json('message') ?? 'Payment initialization failed',
            ];
        } catch (\Exception $e) {
            Log::error('Paystack initialization error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Payment service unavailable',
            ];
        }
    }

    /**
     * Verify a transaction.
     */
    public function verifyTransaction(string $reference): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
            ])->get($this->baseUrl . '/transaction/verify/' . $reference);

            if ($response->successful() && $response->json('status')) {
                $data = $response->json('data');

                return [
                    'success' => true,
                    'status' => $data['status'],
                    'amount' => $data['amount'] / 100,
                    'currency' => $data['currency'],
                    'paid_at' => $data['paid_at'] ?? null,
                    'channel' => $data['channel'] ?? null,
                    'metadata' => $data['metadata'] ?? [],
                    'gateway_response' => $data['gateway_response'] ?? null,
                ];
            }

            return [
                'success' => false,
                'message' => $response->json('message') ?? 'Verification failed',
            ];
        } catch (\Exception $e) {
            Log::error('Paystack verification error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Verification service unavailable',
            ];
        }
    }

    /**
     * Handle webhook event from Paystack.
     */
    public function handleWebhook(array $payload, string $signature): bool
    {
        // Verify webhook signature
        $computedSignature = hash_hmac('sha512', json_encode($payload), $this->secretKey);
        if ($signature !== $computedSignature) {
            Log::warning('Invalid Paystack webhook signature');
            return false;
        }

        $event = $payload['event'] ?? null;
        $data = $payload['data'] ?? [];

        Log::info('Paystack webhook received', ['event' => $event, 'reference' => $data['reference'] ?? null]);

        switch ($event) {
            case 'charge.success':
                return $this->handleSuccessfulCharge($data);
            case 'charge.failed':
                return $this->handleFailedCharge($data);
            default:
                Log::info('Unhandled Paystack event: ' . $event);
                return true;
        }
    }

    /**
     * Handle successful charge webhook.
     */
    protected function handleSuccessfulCharge(array $data): bool
    {
        $reference = $data['reference'] ?? null;
        if (!$reference) {
            return false;
        }

        $payment = Payment::where('transaction_reference', $reference)
            ->orWhere('provider_reference', $reference)
            ->first();

        if (!$payment) {
            Log::warning('Payment not found for reference: ' . $reference);
            return false;
        }

        $payment->update([
            'status' => 'completed',
            'paid_at' => now(),
            'provider_reference' => $data['reference'],
        ]);

        $application = $payment->application;
        if ($application) {
            // Store total fee
            $application->update(['total_fee' => $payment->amount]);

            // Use centralized ApplicationService for proper status transition + audit trail
            if (in_array($application->status, ['submitted_awaiting_payment', 'pending_payment'])) {
                app(ApplicationService::class)->confirmPayment($application);
            }

            Log::info("Payment completed for application {$application->reference_number}");
        }

        return true;
    }

    /**
     * Handle failed charge webhook.
     */
    protected function handleFailedCharge(array $data): bool
    {
        $reference = $data['reference'] ?? null;
        if (!$reference) {
            return false;
        }

        $payment = Payment::where('transaction_reference', $reference)
            ->orWhere('provider_reference', $reference)
            ->first();

        if ($payment) {
            $payment->update([
                'status' => 'failed',
            ]);

            Log::warning("Payment failed for reference: {$reference}");
        }

        return true;
    }

    /**
     * Calculate total amount for application using unified PricingService.
     */
    public function calculateTotalAmount(Application $application): float
    {
        $pricingService = app(\App\Services\PricingService::class);
        $pricing = $pricingService->calculatePrice($application);

        // Update application fee breakdown
        $application->update([
            'government_fee' => $pricing['government_fee'] ?? 0,
            'platform_fee' => $pricing['platform_fee'] ?? 0,
            'processing_fee' => $pricing['processing_fee'],
            'total_fee' => $pricing['total'],
        ]);

        return $pricing['total'];
    }

    /**
     * Generate unique payment reference.
     */
    protected function generateReference(Application $application): string
    {
        return 'GH-' . $application->reference_number . '-' . Str::random(6);
    }

    /**
     * Get list of banks for bank transfer.
     */
    public function getBanks(string $country = 'ghana'): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
            ])->get($this->baseUrl . '/bank', ['country' => $country]);

            if ($response->successful()) {
                return $response->json('data') ?? [];
            }
        } catch (\Exception $e) {
            Log::error('Failed to fetch banks: ' . $e->getMessage());
        }

        return [];
    }
}
