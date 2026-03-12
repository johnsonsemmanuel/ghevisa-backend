<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        protected PaymentOrchestrator $paymentService,
    ) {}

    /**
     * Handle payment provider webhook callback.
     * SECURITY: Verifies webhook signature before processing.
     */
    public function handlePayment(Request $request): JsonResponse
    {
        $provider = $request->header('X-Provider', 'paystack');
        
        // Verify webhook signature based on provider
        if (!$this->verifyWebhookSignature($request, $provider)) {
            Log::warning('Webhook signature verification failed', [
                'provider' => $provider,
                'ip' => $request->ip(),
            ]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $payload = $request->all();
        $success = $this->paymentService->handleWebhook($payload, $provider);

        if (!$success) {
            return response()->json(['message' => 'Webhook processing failed'], 400);
        }

        return response()->json(['message' => 'Webhook processed successfully']);
    }

    /**
     * Verify webhook signature from payment provider.
     */
    protected function verifyWebhookSignature(Request $request, string $provider): bool
    {
        if ($provider === 'paystack') {
            $signature = $request->header('X-Paystack-Signature');
            $secretKey = config('services.paystack.secret_key');
            
            if (!$signature || !$secretKey) {
                return false;
            }

            $computedSignature = hash_hmac('sha512', $request->getContent(), $secretKey);
            return hash_equals($computedSignature, $signature);
        }

        if ($provider === 'stripe') {
            $signature = $request->header('Stripe-Signature');
            $webhookSecret = config('services.stripe.webhook_secret');

            if (!$signature || !$webhookSecret) {
                Log::warning('Stripe webhook missing signature or secret', [
                    'has_signature' => (bool) $signature,
                    'has_secret' => (bool) $webhookSecret,
                ]);
                return false;
            }

            try {
                $payload = $request->getContent();
                $timestamp = null;
                $v1Signature = null;

                // Parse Stripe signature header: t=timestamp,v1=signature
                $parts = explode(',', $signature);
                foreach ($parts as $part) {
                    [$key, $value] = explode('=', $part, 2);
                    if ($key === 't') $timestamp = $value;
                    if ($key === 'v1') $v1Signature = $value;
                }

                if (!$timestamp || !$v1Signature) {
                    return false;
                }

                // Reject if timestamp is older than 5 minutes (replay attack protection)
                if (abs(time() - (int) $timestamp) > 300) {
                    Log::warning('Stripe webhook timestamp too old', ['timestamp' => $timestamp]);
                    return false;
                }

                $signedPayload = $timestamp . '.' . $payload;
                $expectedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);

                return hash_equals($expectedSignature, $v1Signature);
            } catch (\Exception $e) {
                Log::error('Stripe webhook verification error', ['error' => $e->getMessage()]);
                return false;
            }
        }

        return false;
    }
}
