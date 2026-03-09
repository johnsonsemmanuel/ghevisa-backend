<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Payment;
use App\Services\ApplicationService;
use App\Services\MultiPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(
        protected MultiPaymentService $paymentService,
        protected ApplicationService $applicationService
    ) {}

    /**
     * Get available payment methods.
     */
    public function methods(Request $request): JsonResponse
    {
        $countryCode = $request->query('country', 'GH');
        
        return response()->json([
            'methods' => $this->paymentService->getAvailablePaymentMethods($countryCode),
        ]);
    }

    /**
     * Initialize payment for an application.
     */
    public function initialize(Request $request, Application $application): JsonResponse
    {
        // Verify ownership
        if ($application->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'payment_method' => 'required|string',
            'currency' => 'nullable|string|in:GHS,USD,EUR,GBP',
            'callback_url' => 'nullable|url',
        ]);

        $result = $this->paymentService->initializePayment(
            $application,
            $validated['payment_method'],
            $validated['currency'] ?? 'GHS',
            $validated['callback_url'] ?? null
        );

        if (!$result['success']) {
            return response()->json([
                'message' => $result['message'] ?? 'Payment initialization failed',
            ], 400);
        }

        return response()->json($result);
    }

    /**
     * Verify payment status.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'reference' => 'required|string',
        ]);

        $reference = $request->input('reference');
        
        $result = $this->paymentService->verifyPayment($reference);

        if (!$result['success'] && !isset($result['payment'])) {
            return response()->json([
                'success' => false,
                'message' => 'Payment verification failed: ' . ($result['status'] ?? 'unknown'),
            ], 400);
        }

        $payment = $result['payment'] ?? Payment::where('transaction_reference', $reference)->first();

        // If successful, check if we need to manually trigger submission
        if ($result['success'] && $payment && $payment->status === 'completed') {
            $application = $payment->application;
            
            if ($application) {
                $application->refresh();
                
                if (in_array($application->status, ['paid_submitted', 'submitted_awaiting_payment', 'pending_payment', 'draft'])) {
                    try {
                        $this->applicationService->submit($application);
                        Log::info('Payment verified and application submitted', [
                            'payment_id' => $payment->id,
                            'application_id' => $application->id,
                            'reference' => $reference,
                            'new_status' => $application->fresh()->status,
                        ]);
                    } catch (\Exception $e) {
                         Log::error('App submission failed after payment: ' . $e->getMessage());
                    }
                }
            }
        }

        return response()->json([
            'success' => $result['success'],
            'status' => $result['status'] ?? ($payment ? $payment->status : 'failed'),
            'message' => $result['success'] ? 'Payment verified successfully' : 'Payment verification failed',
            'application_status' => $payment->application->status ?? null,
        ]);
    }

    /**
     * Demo payment simulation - creates a completed payment immediately.
     * SECURITY: Only available in local/testing environments.
     */
    public function simulatePayment(Request $request): JsonResponse
    {
        if (!app()->environment('local', 'testing')) {
            Log::warning('Payment simulation attempted in production', [
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
            ]);
            return response()->json(['message' => 'Not found'], 404);
        }

        $request->validate([
            'application_id' => 'required|exists:applications,id',
        ]);

        $application = Application::findOrFail($request->input('application_id'));

        if ($application->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $existingPayment = Payment::where('application_id', $application->id)
            ->where('status', 'completed')
            ->first();

        if ($existingPayment) {
            return response()->json([
                'success' => true,
                'message' => 'Application already paid',
                'payment' => $existingPayment,
            ]);
        }

        $payment = Payment::create([
            'application_id' => $application->id,
            'user_id' => $application->user_id,
            'transaction_reference' => 'DEMO-' . strtoupper(uniqid()),
            'payment_provider' => 'demo',
            'amount' => $application->total_fee ?? 260.00,
            'currency' => 'USD',
            'status' => 'completed',
            'paid_at' => now(),
            'provider_response' => [
                'demo_mode' => true,
                'message' => 'Demo payment - no actual transaction',
            ],
        ]);

        if (in_array($application->status, ['submitted_awaiting_payment', 'pending_payment', 'draft'])) {
            $this->applicationService->confirmPayment($application);
            $this->applicationService->submit($application->fresh());
        }

        return response()->json([
            'success' => true,
            'message' => 'Demo payment completed successfully',
            'demo_note' => 'This is for demo purposes only - no actual payment was processed',
            'payment' => $payment,
            'application_status' => $application->fresh()->status,
        ]);
    }

    /**
     * Get payment history for an application.
     */
    public function history(Request $request, Application $application): JsonResponse
    {
        // Verify ownership or admin
        if ($application->user_id !== $request->user()->id && !$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $payments = Payment::where('application_id', $application->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'reference' => $p->transaction_reference,
                'provider' => $p->payment_provider,
                'amount' => $p->amount,
                'currency' => $p->currency,
                'status' => $p->status,
                'paid_at' => $p->paid_at?->toIso8601String(),
                'created_at' => $p->created_at->toIso8601String(),
            ]);

        return response()->json(['payments' => $payments]);
    }

    /**
     * Upload bank transfer proof.
     */
    public function uploadProof(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reference' => 'required|string',
            'proof' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        $payment = Payment::where('transaction_reference', $validated['reference'])
            ->where('payment_provider', 'bank_transfer')
            ->first();

        if (!$payment) {
            return response()->json(['message' => 'Bank transfer payment not found'], 404);
        }

        // Verify ownership
        if ($payment->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Store proof
        $path = $request->file('proof')->store('payment-proofs', 'private');

        $payment->update([
            'status' => 'pending_verification',
            'metadata' => array_merge($payment->metadata ?? [], [
                'proof_path' => $path,
                'proof_uploaded_at' => now()->toIso8601String(),
            ]),
        ]);

        return response()->json([
            'message' => 'Proof uploaded successfully. Verification in progress.',
            'status' => 'pending_verification',
        ]);
    }

    /**
     * Admin: Confirm bank transfer payment.
     */
    public function confirmBankTransfer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reference' => 'required|string',
        ]);

        $payment = Payment::where('transaction_reference', $validated['reference'])
            ->where('payment_provider', 'bank_transfer')
            ->first();

        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        $proofUrl = $payment->metadata['proof_path'] ?? null;

        $result = $this->paymentService->confirmBankTransfer(
            $validated['reference'],
            $proofUrl ?? '',
            $request->user()->id
        );

        if (!$result['success']) {
            return response()->json(['message' => $result['message'] ?? 'Confirmation failed'], 400);
        }

        return response()->json([
            'message' => 'Bank transfer confirmed',
            'payment' => $result['payment'],
        ]);
    }

    /**
     * Get payment statistics (admin).
     */
    public function statistics(Request $request): JsonResponse
    {
        $days = $request->query('days', 30);
        $startDate = now()->subDays($days);

        $stats = [
            'total_revenue' => Payment::where('status', 'completed')
                ->where('paid_at', '>=', $startDate)
                ->sum('amount'),
            'total_transactions' => Payment::where('status', 'completed')
                ->where('paid_at', '>=', $startDate)
                ->count(),
            'by_provider' => Payment::where('status', 'completed')
                ->where('paid_at', '>=', $startDate)
                ->selectRaw('payment_provider, COUNT(*) as count, SUM(amount) as total')
                ->groupBy('payment_provider')
                ->get(),
            'by_status' => Payment::where('created_at', '>=', $startDate)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
            'pending_bank_transfers' => Payment::where('payment_provider', 'bank_transfer')
                ->where('status', 'pending_verification')
                ->count(),
        ];

        return response()->json(['statistics' => $stats]);
    }
}
