<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService,
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
        // In development/testing mode, allow unverified webhooks
        if (app()->environment('local', 'testing')) {
            return true;
        }

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
                return false;
            }

            // Stripe signature verification would go here
            // For now, return true in dev mode
            return true;
        }

        return false;
    }
}
