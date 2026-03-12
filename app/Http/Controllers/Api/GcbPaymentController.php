<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Payment;
use App\Services\ApplicationService;
use App\Services\Payment\Providers\GcbProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GcbPaymentController extends Controller
{
    public function __construct(
        protected GcbProvider $gcbService,
        protected ApplicationService $applicationService,
    ) {}

    /**
     * Initiate GCB checkout for an application
     */
    public function initiateCheckout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'application_id' => 'required|exists:applications,id',
        ]);

        $application = Application::findOrFail($validated['application_id']);

        // Verify ownership
        if ($application->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Verify application is ready for payment
        if (!in_array($application->status, ['draft', 'submitted_awaiting_payment', 'pending_payment'])) {
            return response()->json([
                'message' => 'Application is not eligible for payment',
            ], 422);
        }

        // Calculate fees if not already set
        if (!$application->total_fee) {
            $application->total_fee = $this->calculateTotalFee($application);
            $application->save();
        }

        $callbackUrl = config('app.frontend_url', 'http://localhost:3000') . '/payment/callback';

        $result = $this->gcbService->initiateCheckout($application, $callbackUrl);

        if (!$result['success']) {
            return response()->json([
                'message' => $result['error'] ?? 'Failed to initiate payment',
            ], 500);
        }

        // Update application status to pending_payment (not draft)
        if (in_array($application->status, ['draft', 'submitted_awaiting_payment'])) {
            $application->status = 'pending_payment';
            $application->save();
        }

        return response()->json([
            'success' => true,
            'checkout_url' => $result['checkout_url'],
            'checkout_id' => $result['checkout_id'],
            'merchant_ref' => $result['merchant_ref'],
        ]);
    }

    /**
     * Check payment status
     */
    public function checkStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'checkout_id' => 'required|string',
        ]);

        $payment = Payment::where('checkout_id', $validated['checkout_id'])->first();

        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        // Check status from GCB
        $result = $this->gcbService->checkTransactionStatus($validated['checkout_id']);

        if ($result['success']) {
            // Update payment record
            $payment->update([
                'status' => $result['status'],
                'bank_ref' => $result['bank_ref'],
                'payment_option' => $result['payment_option'],
                'completed_at' => $result['time_completed'] ? \Carbon\Carbon::parse($result['time_completed']) : null,
            ]);

            // If payment completed, update application
            if ($result['status'] === 'completed') {
                $this->handlePaymentSuccess($payment);
            }
        }

        return response()->json([
            'success' => true,
            'status' => $payment->fresh()->status,
            'status_code' => $result['status_code'] ?? null,
            'status_description' => GcbProvider::getStatusDescription($result['status_code'] ?? ''),
            'payment_option' => $result['payment_option'] ?? null,
            'application_status' => $payment->application->status,
        ]);
    }

    /**
     * GCB Callback endpoint (called by GCB gateway)
     * SECURITY FIX: Always require signature verification
     */
    public function callback(Request $request): JsonResponse
    {
        // CRITICAL: Always verify GCB callback signature
        $signature = $request->header('X-GCB-Signature');
        $gcbSecret = config('services.gcb.callback_secret');

        // SECURITY FIX: Fail if secret not configured
        if (!$gcbSecret) {
            Log::critical('GCB callback secret not configured - payment system misconfigured', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            abort(500, 'Payment system misconfigured');
        }

        if (!$signature) {
            Log::warning('GCB callback missing signature', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'payload' => $request->all(),
            ]);
            abort(401, 'Missing signature');
        }

        $expectedSignature = hash_hmac('sha256', $request->getContent(), $gcbSecret);
        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('GCB callback invalid signature', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'provided_signature' => $signature,
            ]);
            abort(401, 'Invalid signature');
        }

        Log::info('GCB Callback received', $request->all());

        $result = $this->gcbService->processCallback($request->all());

        if (!$result['success']) {
            Log::error('GCB Callback processing failed', $result);
            return response()->json(['error' => $result['error']], 400);
        }

        // Handle payment success
        if ($result['status'] === 'completed') {
            $payment = Payment::find($result['payment_id']);
            if ($payment) {
                $this->handlePaymentSuccess($payment);
            }
        }

        // Return 200 as expected by GCB
        return response()->json(['success' => true], 200);
    }

    /**
     * Verify payment after redirect from GCB
     * Per GCB docs: After redirect, merchant extracts merchantRef from URL
     * and calls Status Check API using the stored checkOutId.
     * For test mode: use statusCode from URL query params.
     */
    public function verify(Request $request): JsonResponse
    {
        $merchantRef = $request->query('merchantRef') ?? $request->query('merchant_ref');
        $statusCode = $request->query('statusCode') ?? $request->query('status_code');

        if (!$merchantRef) {
            return response()->json(['message' => 'Missing merchant reference'], 400);
        }

        $payment = Payment::where('merchant_ref', $merchantRef)->first();

        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        // If already completed, just return success
        if ($payment->status === 'completed') {
            return response()->json([
                'success' => true,
                'status' => 'completed',
                'application_id' => $payment->application_id,
                'reference_number' => $payment->application->reference_number ?? null,
                'message' => 'Payment successful! Your application has been submitted.',
            ]);
        }

        // For test mode payments: use statusCode from URL redirect
        if ($payment->payment_provider === 'gcb_test' && $statusCode !== null) {
            $status = $this->gcbService->mapStatusCode($statusCode);
            $payment->update([
                'status' => $status === 'completed' ? 'completed' : ($status === 'pending' ? 'pending' : 'failed'),
                'paid_at' => $status === 'completed' ? now() : null,
                'completed_at' => $status === 'completed' ? now() : null,
            ]);

            if ($status === 'completed') {
                $this->handlePaymentSuccess($payment);
            }
        }
        // For real GCB payments: call Status Check API using checkOutId
        elseif ($payment->checkout_id && $payment->payment_provider === 'gcb') {
            $result = $this->gcbService->checkTransactionStatus($payment->checkout_id);

            if ($result['success']) {
                $payment->update([
                    'status' => $result['status'],
                    'bank_ref' => $result['bank_ref'],
                    'payment_option' => $result['payment_option'],
                    'completed_at' => $result['time_completed'] ? \Carbon\Carbon::parse($result['time_completed']) : null,
                ]);

                if ($result['status'] === 'completed') {
                    $this->handlePaymentSuccess($payment);
                }
            }
        }

        $payment = $payment->fresh();

        return response()->json([
            'success' => $payment->status === 'completed',
            'status' => $payment->status,
            'application_id' => $payment->application_id,
            'reference_number' => $payment->application->reference_number ?? null,
            'message' => $payment->status === 'completed' 
                ? 'Payment successful! Your application has been submitted.'
                : 'Payment ' . $payment->status,
        ]);
    }

    /**
     * Handle successful payment
     */
    protected function handlePaymentSuccess(Payment $payment): void
    {
        $application = $payment->application;

        if (!$application) {
            return;
        }

        // Mark payment as completed
        $payment->update([
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        // Confirm payment and submit application
        $this->applicationService->confirmPayment($application);
        
        // Submit for processing (routes to appropriate queue)
        if ($application->status === 'paid_submitted') {
            $this->applicationService->submit($application);
        }

        Log::info('Payment success handled', [
            'payment_id' => $payment->id,
            'application_id' => $application->id,
            'new_status' => $application->fresh()->status,
        ]);
    }

    /**
     * Calculate total fee for application
     */
    protected function calculateTotalFee(Application $application): float
    {
        $visaType = $application->visaType;
        $serviceTier = $application->serviceTier;

        $baseFee = $visaType->base_fee ?? 150.00;
        $entryFee = $application->entry_type === 'multiple' 
            ? ($visaType->multiple_entry_fee ?? 50.00) 
            : 0;
        $processingFee = $serviceTier->additional_fee ?? 0;

        $total = $baseFee + $entryFee + $processingFee;

        $application->government_fee = $baseFee;
        $application->platform_fee = $entryFee;
        $application->processing_fee = $processingFee;

        return $total;
    }
}
