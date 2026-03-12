<?php

namespace App\Http\Controllers\Api\Applicant;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\VisaType;
use App\Rules\UniqueActivePassport;
use App\Services\ApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EtaApplicationController extends Controller
{
    public function __construct(
        protected ApplicationService $applicationService,
    ) {}

    /**
     * Create a new ETA application (from applicant dashboard).
     * Uses same field names as regular applications for consistency.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Visa Setup
            'visa_channel'   => 'nullable|string|in:e-visa,regular',
            'authorization_type' => 'required|string|in:eta,evisa',
            
            // Applicant Details
            'first_name'     => 'required|string|max:255',
            'last_name'      => 'required|string|max:255',
            'other_names'    => 'nullable|string|max:255',
            'date_of_birth'  => 'required|date|before:today',
            'gender'         => 'required|string|in:male,female',
            'marital_status' => 'required|string|in:single,married,divorced,widowed,separated',
            'country_of_birth' => 'required|string|max:3',
            'place_of_birth' => 'nullable|string|max:255',
            'nationality'    => 'required|string|max:3',
            'profession'     => 'required|string|max:255',
            
            // Passport Information
            'passport_number'=> ['required', 'string', 'max:50', new UniqueActivePassport()],
            'passport_issuing_authority' => 'nullable|string|max:255',
            'passport_issue_date' => 'required|date|before_or_equal:today',
            'passport_expiry'=> 'required|date|after:today',
            'passport_issue_place' => 'nullable|string|max:255',
            
            // Contact Information
            'email'          => 'required|email',
            'phone'          => 'required|string|max:20',
            'phone_country'  => 'nullable|string|max:3',
            
            // Travel Details
            'intended_arrival' => 'required|date|after:today',
            'duration_days'  => 'required|integer|min:1|max:90',
            'port_of_entry'  => 'required|string|max:255',
            'place_of_embarkation' => 'nullable|string|max:255',
            'destination_city' => 'nullable|string|max:255',
            'address_in_ghana' => 'required|string|max:500',
            'purpose_of_visit' => 'required|string|max:255',
            'purpose_details' => 'nullable|string|max:1000',
            
            // Travel History
            'visited_ghana_before' => 'required|string|in:yes,no',
            'previous_visa_number' => 'nullable|string|max:50',
            'visited_other_countries' => 'required|string|in:yes,no',
            'visited_country_1' => 'nullable|string|max:255',
            'visited_country_2' => 'nullable|string|max:255',
            'visited_country_3' => 'nullable|string|max:255',
            
            // Accommodation
            'accommodation_type' => 'required|string|in:hotel,family',
            'hotel_name'     => 'nullable|string|max:255',
            'hotel_booking_reference' => 'nullable|string|max:255',
            'accommodation_address' => 'nullable|string|max:500',
            'host_name'      => 'nullable|string|max:255',
            'host_phone'     => 'nullable|string|max:20',
            'host_address'   => 'nullable|string|max:500',
            'host_relationship' => 'nullable|string|max:255',
            
            // Health Declaration
            'health_infectious_travel' => 'required|string|in:yes,no',
            'health_infectious_countries' => 'nullable|string|max:500',
            
            // Security & Travel Declaration
            'high_risk_travel' => 'required|string|in:yes,no',
            'entry_denied_before' => 'required|string|in:yes,no',
            'overstayed_before' => 'required|string|in:yes,no',
            'international_sanctions' => 'required|string|in:yes,no',
            'criminal_conviction' => 'nullable|string|in:yes,no',
            
            // Additional fields
            'airline'        => 'nullable|string|max:255',
            'flight_number'  => 'nullable|string|max:50',
            'return_date'    => 'nullable|date|after:intended_arrival',
            
            // Employment (optional)
            'occupation'     => 'nullable|string|max:255',
            'employer_name'  => 'nullable|string|max:255',
            'employer_address' => 'nullable|string|max:500',
            'employer_phone' => 'nullable|string|max:20',
        ]);

        // Verify authorization type is ETA
        if ($validated['authorization_type'] !== 'eta') {
            return response()->json([
                'message' => 'This endpoint is only for ETA applications',
            ], 422);
        }

        // Check ETA eligibility for nationality
        $eligibilityService = app(\App\Services\EtaEligibilityService::class);
        $authType = $eligibilityService->getAuthorizationType($validated['nationality']);
        
        if ($authType !== 'eta') {
            return response()->json([
                'message' => 'Your nationality is not eligible for ETA. Please apply for an eVisa instead.',
                'authorization_type' => $authType,
            ], 422);
        }

        // Get the ETA visa type
        $etaVisaType = VisaType::where('type', 'eta')
            ->where('is_active', true)
            ->first();

        if (!$etaVisaType) {
            return response()->json([
                'message' => 'ETA visa type not found. Please contact support.',
            ], 500);
        }

        // Add visa_type_id to validated data
        $validated['visa_type_id'] = $etaVisaType->id;

        // Set default visa channel if not provided
        if (empty($validated['visa_channel'])) {
            $validated['visa_channel'] = 'e-visa';
        }

        // Convert yes/no strings to booleans for database
        $booleanFields = [
            'visited_ghana_before',
            'visited_other_countries',
            'health_infectious_travel',
            'high_risk_travel',
            'entry_denied_before',
            'overstayed_before',
            'international_sanctions',
            'criminal_conviction',
        ];

        foreach ($booleanFields as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = $validated[$field] === 'yes';
            }
        }

        // Business rule: enforce passport expiry rules
        if (!empty($validated['passport_expiry'])) {
            $expiry = now()->parse($validated['passport_expiry']);
            if ($expiry->lt(now()->startOfDay())) {
                return response()->json([
                    'message' => 'Your passport has expired. Please renew your passport before applying.',
                ], 422);
            }

            $months = now()->startOfDay()->diffInMonths($expiry);
            if ($months < 6) {
                // Allow creation but surface a warning flag
                $validated['passport_near_expiry'] = true;
            }
        }

        // Check for duplicate ETA applications
        $existingEta = Application::where('user_id', $request->user()->id)
            ->where('authorization_type', 'eta')
            ->whereIn('status', ['submitted', 'under_review', 'pending_approval', 'approved', 'issued'])
            ->where('passport_number', $validated['passport_number'])
            ->where('nationality', $validated['nationality'])
            ->first();

        if ($existingEta) {
            return response()->json([
                'message' => 'You already have an active ETA application with reference number: ' . $existingEta->reference_number,
                'existing_application' => [
                    'reference_number' => $existingEta->reference_number,
                    'status' => $existingEta->status,
                    'submitted_at' => $existingEta->submitted_at?->toIso8601String(),
                ],
            ], 422);
        }

        // Create the ETA application
        $application = $this->applicationService->createDraft($validated, $request->user());

        // Set ETA-specific fields
        $application->update([
            'tier' => 'express', // ETAs are processed quickly
            'entry_type' => 'single', // ETAs are typically single entry
            'visa_duration' => '90', // 90 days validity
        ]);

        return response()->json([
            'message' => 'ETA application created successfully',
            'application' => $application->load('visaType'),
        ], 201);
    }
}
