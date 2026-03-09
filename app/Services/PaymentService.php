<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    public function __construct(
        protected MultiPaymentService $multiPaymentService,
    ) {}

    /**
     * Initiate a payment for an application.
     * Supports Paystack and Stripe providers.
     */
    public function initiatePayment(Application $application, string $provider = 'paystack'): array
    {
        $paymentMethod = match ($provider) {
            'paystack' => 'paystack_card',
            'stripe' => 'stripe_card',
            'bank_transfer' => 'bank_transfer',
            'gcb', 'gcb_payment' => 'gcb_payment',
            default => 'paystack_card',
        };

        $currency = in_array($provider, ['paystack', 'gcb', 'gcb_payment']) ? 'GHS' : 'USD';

        $result = $this->multiPaymentService->initializePayment(
            $application,
            $paymentMethod,
            $currency,
            config('app.frontend_url') . '/payment/callback'
        );

        if (!($result['success'] ?? false)) {
            throw new \RuntimeException($result['message'] ?? 'Payment initialization failed');
        }

        $payment = Payment::where('transaction_reference', $result['reference'] ?? null)->first();

        return [
            'payment' => $payment,
            'checkout_url' => $result['authorization_url'] ?? null,
            'transaction_reference' => $result['reference'] ?? null,
        ];
    }

    /**
     * Handle webhook callback from payment provider.
     * Verifies signature and updates payment + application status.
     */
    public function handleWebhook(array $payload, string $provider): bool
    {
        $reference = $payload['transaction_reference']
            ?? $payload['reference']
            ?? data_get($payload, 'data.reference');

        if (!$reference) {
            Log::warning('Payment webhook missing transaction reference', ['provider' => $provider]);
            return false;
        }

        $result = $this->multiPaymentService->verifyPayment($reference);

        return (bool) ($result['success'] ?? false);
    }

    /**
     * Verify payment was actually completed (for double-check).
     */
    public function verifyPayment(Payment $payment): bool
    {
        $result = $this->multiPaymentService->verifyPayment($payment->transaction_reference);

        return (bool) ($result['success'] ?? false)
            && (($result['payment'] ?? null)?->status === 'completed'
                || (($result['status'] ?? null) === 'completed'));
    }
}
