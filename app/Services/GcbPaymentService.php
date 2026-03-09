<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GcbPaymentService
{
    protected string $baseUrl;
    protected string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.gcb.base_url', 'https://epayuat.gcbltd.com:98/paymentgateway');
        $this->apiKey = config('services.gcb.api_key', '');
    }

    /**
     * Initiate a checkout session with GCB Payment Gateway
     */
    public function initiateCheckout(Application $application, string $callbackUrl): array
    {
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

        try {
            $response = Http::withHeaders([
                'X-Api-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl}/checkout", $payload);

            if ($response->successful()) {
                $data = $response->json();

                // Create or update payment record
                $payment = Payment::updateOrCreate(
                    ['application_id' => $application->id],
                    [
                        'merchant_ref' => $merchantRef,
                        'checkout_id' => $data['checkOutId'] ?? null,
                        'checkout_url' => $data['checkOutUrl'] ?? null,
                        'amount' => $amount,
                        'currency' => 'GHS',
                        'status' => 'pending',
                        'gateway' => 'gcb',
                        'gateway_response' => $data,
                    ]
                );

                Log::info('GCB Checkout initiated', [
                    'application_id' => $application->id,
                    'merchant_ref' => $merchantRef,
                    'checkout_id' => $data['checkOutId'] ?? null,
                ]);

                return [
                    'success' => true,
                    'checkout_url' => $data['checkOutUrl'] ?? null,
                    'checkout_id' => $data['checkOutId'] ?? null,
                    'merchant_ref' => $merchantRef,
                    'payment_id' => $payment->id,
                ];
            }

            Log::error('GCB Checkout failed', [
                'application_id' => $application->id,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return [
                'success' => false,
                'error' => 'Payment gateway error: ' . ($response->json('message') ?? 'Unknown error'),
            ];
        } catch (\Exception $e) {
            Log::error('GCB Checkout exception', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Payment gateway connection failed',
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
    protected function mapStatusCode(?string $code): string
    {
        return match ($code) {
            '00' => 'completed',
            '01' => 'pending',
            '02' => 'failed',
            '03' => 'expired',
            '04' => 'error',
            '05' => 'error',
            default => 'unknown',
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
