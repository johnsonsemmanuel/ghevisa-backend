<?php

namespace App\Http\Controllers\Api\Applicant;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Payment;
use App\Services\ApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    protected \App\Services\MultiPaymentService $multiPaymentService;

    public function __construct(ApplicationService $applicationService, \App\Services\MultiPaymentService $multiPaymentService)
    {
        $this->applicationService = $applicationService;
        $this->multiPaymentService = $multiPaymentService;
    }

    /**
     * Verify payment with the actual payment gateway.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'reference' => 'required|string',
        ]);

        $reference = $request->input('reference');
        
        // Pass to MultiPaymentService
        $result = $this->multiPaymentService->verifyPayment($reference);

        if (!$result['success'] && !isset($result['payment'])) {
            return response()->json([
                'success' => false,
                'message' => 'Payment verification failed: ' . ($result['status'] ?? 'unknown'),
            ], 400);
        }

        $payment = $result['payment'] ?? Payment::where('transaction_reference', $reference)->first();

        // If successful, check if we need to manually trigger submission
        // (Note: MultiPaymentService might have already called confirmPayment)
        if ($result['success'] && $payment && $payment->status === 'completed') {
            $application = $payment->application;
            
            // Re-fetch to get latest status
            if ($application) {
                $application->refresh();
                
                // If it's still in a pre-submission state after confirmPayment, submit it
                if (in_array($application->status, ['paid_submitted', 'submitted_awaiting_payment', 'pending_payment', 'draft'])) {
                    // This method handles routing
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
     */
    public function simulatePayment(Request $request): JsonResponse
    {
        $request->validate([
            'application_id' => 'required|exists:applications,id',
        ]);

        $application = Application::findOrFail($request->input('application_id'));

        // Check if user owns this application
        if ($application->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Check if already paid
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

        // Create demo payment
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

        // Update application status
        if (in_array($application->status, ['submitted_awaiting_payment', 'pending_payment', 'draft'])) {
            $this->applicationService->confirmPayment($application);
            
            // Submit the application to trigger routing
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
}
