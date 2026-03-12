<?php

namespace App\Services\Application;

use App\Jobs\SendNotification;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\User;
use App\Services\Risk\RiskScoringOrchestrator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

class ApplicationService
{
    public function __construct(
        protected ApplicationRoutingService $routingService,
        protected RiskScoringOrchestrator $riskScoringOrchestrator,
    ) {}

    /**
     * Create a new draft application for the authenticated applicant.
     */
    public function createDraft(array $data, User $user): Application
    {
        $application = Application::create([
            'reference_number'       => Application::generateReferenceNumber(),
            'user_id'                => $user->id,
            'visa_type_id'           => $data['visa_type_id'],
            'visa_channel'           => $data['visa_channel'] ?? 'e-visa',
            'entry_type'             => $data['entry_type'] ?? 'single',
            'service_tier_id'        => $data['service_tier_id'] ?? null,
            
            // Applicant Details (encrypted)
            'first_name_encrypted'   => $data['first_name'],
            'last_name_encrypted'    => $data['last_name'],
            'other_names_encrypted'  => $data['other_names'] ?? null,
            'date_of_birth_encrypted'=> $data['date_of_birth'],
            'passport_number_encrypted' => $data['passport_number'],
            'nationality_encrypted'  => $data['nationality'],
            'email_encrypted'        => $data['email'],
            'phone_encrypted'        => $data['phone'] ?? null,
            'profession_encrypted'   => $data['profession'] ?? null,
            
            // Non-encrypted fields
            'gender'                 => $data['gender'] ?? null,
            'marital_status'         => $data['marital_status'] ?? null,
            'country_of_birth'       => $data['country_of_birth'] ?? $data['place_of_birth'] ?? null,
            'place_of_birth'         => $data['place_of_birth'] ?? null,
            
            // Passport Information
            'passport_issuing_authority' => $data['passport_issuing_authority'] ?? null,
            'passport_issue_date'    => $data['passport_issue_date'] ?? null,
            'passport_expiry'        => $data['passport_expiry'] ?? null,
            'passport_issue_place'   => $data['passport_issue_place'] ?? null,
            'passport_near_expiry'   => $data['passport_near_expiry'] ?? false,
            
            // Contact Information
            'phone_country'          => $data['phone_country'] ?? null,
            
            // Travel Details
            'intended_arrival'       => $data['intended_arrival'] ?? null,
            'duration_days'          => $data['duration_days'] ?? null,
            'visa_duration'          => $data['visa_duration'] ?? null,
            'port_of_entry'          => $data['port_of_entry'] ?? null,
            'place_of_embarkation'   => $data['place_of_embarkation'] ?? null,
            'destination_city'       => $data['destination_city'] ?? null,
            'address_in_ghana'       => $data['address_in_ghana'] ?? null,
            'purpose_of_visit'       => $data['purpose_of_visit'] ?? null,
            'purpose_details'        => $data['purpose_details'] ?? null,
            
            // Travel History
            'visited_ghana_before'   => $data['visited_ghana_before'] ?? false,
            'previous_visa_number'   => $data['previous_visa_number'] ?? null,
            'visited_other_countries' => $data['visited_other_countries'] ?? false,
            'visited_country_1'      => $data['visited_country_1'] ?? null,
            'visited_country_2'      => $data['visited_country_2'] ?? null,
            'visited_country_3'      => $data['visited_country_3'] ?? null,
            
            // Accommodation
            'accommodation_type'     => $data['accommodation_type'] ?? null,
            'hotel_name'             => $data['hotel_name'] ?? null,
            'hotel_booking_reference' => $data['hotel_booking_reference'] ?? null,
            'accommodation_address'  => $data['accommodation_address'] ?? null,
            'host_name'              => $data['host_name'] ?? null,
            'host_phone'             => $data['host_phone'] ?? null,
            'host_address'           => $data['host_address'] ?? null,
            'host_relationship'      => $data['host_relationship'] ?? null,
            
            // Health Declaration
            'health_infectious_travel' => $data['health_infectious_travel'] ?? false,
            'health_infectious_countries' => $data['health_infectious_countries'] ?? null,
            
            // Security & Travel Declaration
            'high_risk_travel'       => $data['high_risk_travel'] ?? false,
            'entry_denied_before'    => $data['entry_denied_before'] ?? false,
            'overstayed_before'      => $data['overstayed_before'] ?? false,
            'international_sanctions' => $data['international_sanctions'] ?? false,
            'criminal_conviction'    => $data['criminal_conviction'] ?? false,
            
            // Additional fields
            'current_address'        => $data['current_address'] ?? null,
            'city'                   => $data['city'] ?? null,
            'state_province'         => $data['state_province'] ?? null,
            'postal_code'            => $data['postal_code'] ?? null,
            'country_of_residence'   => $data['country_of_residence'] ?? null,
            'airline'                => $data['airline'] ?? null,
            'flight_number'          => $data['flight_number'] ?? null,
            'return_date'            => $data['return_date'] ?? null,
            
            // Employment
            'occupation'             => $data['occupation'] ?? null,
            'employer_name'          => $data['employer_name'] ?? null,
            'employer_address'       => $data['employer_address'] ?? null,
            'employer_phone'         => $data['employer_phone'] ?? null,
            
            // Business
            'company_name'           => $data['company_name'] ?? null,
            'company_address'        => $data['company_address'] ?? null,
            'job_title'              => $data['job_title'] ?? null,
            'host_company_name'      => $data['host_company_name'] ?? null,
            'host_company_address'   => $data['host_company_address'] ?? null,
            'host_contact_name'      => $data['host_contact_name'] ?? null,
            'host_contact_phone'     => $data['host_contact_phone'] ?? null,
            'business_purpose'       => $data['business_purpose'] ?? null,
            'business_details'       => $data['business_details'] ?? null,
            
            'status'                 => 'pending_payment',
            'current_step'           => 1,
        ]);

        $this->recordStatusChange($application, null, 'pending_payment', 'Application created and awaiting payment');

        return $application;
    }

    /**
     * Update a draft application's step data.
     */
    public function updateStep(Application $application, int $step, array $data): Application
    {
        $fillable = [];

        switch ($step) {
            case 1: // Personal Details
                if (isset($data['visa_channel'])) $fillable['visa_channel'] = $data['visa_channel'];
                if (isset($data['entry_type'])) $fillable['entry_type'] = $data['entry_type'];
                if (isset($data['service_tier_id'])) $fillable['service_tier_id'] = $data['service_tier_id'];
                break;

            case 2: // Personal Details
                $fillable = [
                    'first_name_encrypted'      => $data['first_name'] ?? $application->first_name_encrypted,
                    'last_name_encrypted'        => $data['last_name'] ?? $application->last_name_encrypted,
                    'date_of_birth_encrypted'    => $data['date_of_birth'] ?? $application->date_of_birth_encrypted,
                    'passport_number_encrypted'  => $data['passport_number'] ?? $application->passport_number_encrypted,
                    'nationality_encrypted'      => $data['nationality'] ?? $application->nationality_encrypted,
                    'email_encrypted'            => $data['email'] ?? $application->email_encrypted,
                    'phone_encrypted'            => $data['phone'] ?? $application->phone_encrypted,
                    'gender'                     => $data['gender'] ?? $application->gender,
                    'marital_status'             => $data['marital_status'] ?? $application->marital_status,
                    'profession_encrypted'       => $data['profession'] ?? $application->profession_encrypted,
                    'country_of_birth'           => $data['country_of_birth'] ?? $application->country_of_birth,
                    'passport_issue_date'        => $data['passport_issue_date'] ?? $application->passport_issue_date,
                    'passport_expiry'            => $data['passport_expiry'] ?? $application->passport_expiry,
                ];
                break;

            case 3: // Travel Details
                $fillable = [];
                
                // Only use data if it's explicitly provided in the request
                if (isset($data['intended_arrival'])) {
                    $fillable['intended_arrival'] = $data['intended_arrival'];
                }
                if (isset($data['duration_days'])) {
                    $fillable['duration_days'] = $data['duration_days'];
                }
                if (isset($data['visa_duration'])) {
                    $fillable['visa_duration'] = $data['visa_duration'];
                }
                if (isset($data['address_in_ghana'])) {
                    $fillable['address_in_ghana'] = $data['address_in_ghana'];
                }
                if (isset($data['purpose_of_visit'])) {
                    $fillable['purpose_of_visit'] = $data['purpose_of_visit'];
                }
                
                // ETA-specific fields
                $etaFields = [
                    'airline', 'flight_number', 'host_name', 'host_phone', 
                    'hotel_booking_reference', 'authorization_type'
                ];
                foreach ($etaFields as $f) {
                    if (isset($data[$f])) {
                        $fillable[$f] = $data[$f];
                    }
                }
                
                // Dynamically save any field passed in Step 3 that exists on the model
                $step3DynamicFields = [
                    'port_of_entry', 'destination_city', 'accommodation_type', 
                    'visited_other_countries', 'visited_country_1', 'visited_country_2', 'visited_country_3'
                ];
                foreach ($step3DynamicFields as $f) {
                    if (isset($data[$f])) {
                        $fillable[$f] = $data[$f];
                    }
                }
                if (isset($data['passport_expiry'])) {
                    $fillable['passport_expiry'] = $data['passport_expiry'];
                }
                break;

            case 4: // Documents (handled via DocumentService)
                break;

            case 5: // Health Declaration
                $fillable = [
                    'health_good_condition' => $data['health_good_condition'] ?? $application->health_good_condition,
                    'health_recent_illness' => $data['health_recent_illness'] ?? $application->health_recent_illness,
                    'health_contact_infectious' => $data['health_contact_infectious'] ?? $application->health_contact_infectious,
                    'health_yellow_fever_vaccinated' => $data['health_yellow_fever_vaccinated'] ?? $application->health_yellow_fever_vaccinated,
                    'health_chronic_conditions' => $data['health_chronic_conditions'] ?? $application->health_chronic_conditions,
                    'health_condition_details' => $data['health_condition_details'] ?? $application->health_condition_details,
                ];
                break;

            case 6: // Security Declaration
                // Handle security declaration fields (including ETA-specific ones)
                $securityFields = [
                    'entry_denied_before',
                    'criminal_conviction',
                    'previous_ghana_visa',
                    'travel_history'
                ];
                
                foreach ($securityFields as $field) {
                    if (isset($data[$field])) {
                        $fillable[$field] = $data[$field];
                    }
                }
                break;

            case 7: // Review & Submit
                break;
        }

        $fillable['current_step'] = $step;
        $application->update($fillable);

        return $application->fresh();
    }

    /**
     * Submit the application for payment.
     * Transitions: draft -> submitted_awaiting_payment
     */
    public function submitForPayment(Application $application): Application
    {
        return DB::transaction(function () use ($application) {
            $fromStatus = $application->status;
            $application->status = 'submitted_awaiting_payment';
            $application->submitted_at = now();
            $application->save();

            $this->recordStatusChange($application, $fromStatus, 'submitted_awaiting_payment', 'Application submitted, awaiting payment');

            SendNotification::dispatch($application, 'status_changed', ['status' => 'submitted_awaiting_payment']);

            return $application;
        });
    }

    /**
     * Confirm payment for an application.
     * Transitions: submitted_awaiting_payment/pending_payment → paid_submitted
     * Does NOT trigger routing — routing is handled separately.
     */
    public function confirmPayment(Application $application): Application
    {
        if (!in_array($application->status, ['submitted_awaiting_payment', 'pending_payment'])) {
            return $application;
        }

        return DB::transaction(function () use ($application) {
            $fromStatus = $application->status;
            
            // First mark as submitted with payment
            $application->status = 'submitted';
            if (!$application->submitted_at) {
                $application->submitted_at = now();
            }
            $application->save();

            $this->recordStatusChange($application, $fromStatus, 'submitted', 'Payment confirmed - Application submitted');

            // Now route the application through CPH to assign to proper agency
            $this->routingService->route($application);
            $this->recordStatusChange($application, 'submitted', $application->status, "Routed to {$application->assigned_agency} as {$application->tier}");

            // SECURITY FIX: Trigger Interpol check automatically (non-blocking)
            $this->triggerInterpolCheckAsync($application);

            // Send notifications
            SendNotification::dispatch($application, 'application_submitted');
            SendNotification::dispatch($application, 'new_application_assigned');

            return $application;
        });
    }

    /**
     * Submit an application after payment is confirmed.
     * Routes through CPH for tier classification and agency assignment.
     * SECURITY: Auto-rejects blacklisted nationalities.
     */
    public function submit(Application $application): Application
    {
        return DB::transaction(function () use ($application) {
            // Check for blacklisted nationalities
            $visaType = $application->visaType;
            if ($visaType && !empty($visaType->blacklisted_nationalities)) {
                $blacklisted = $visaType->blacklisted_nationalities;
                if (in_array($application->nationality, $blacklisted)) {
                    $application->status = 'denied';
                    $application->decided_at = now();
                    $application->decision_notes = 'Application automatically denied: nationality not eligible for this visa type.';
                    $application->save();

                    $this->recordStatusChange($application, $application->status, 'denied', 'Auto-denied: blacklisted nationality');
                    return $application;
                }
            }

            $fromStatus = $application->status;
            $application->status = 'submitted';
            if (!$application->submitted_at) {
                $application->submitted_at = now();
            }
            $application->save();

            $this->recordStatusChange($application, $fromStatus, 'submitted', 'Application submitted with payment');

            // Dispatch routing through CPH
            $this->routingService->route($application);
            $this->recordStatusChange($application, 'submitted', $application->status, "Routed to {$application->assigned_agency} as {$application->tier}");

            // SECURITY FIX: Trigger Interpol check automatically (non-blocking)
            $this->triggerInterpolCheckAsync($application);

            // Send notifications
            SendNotification::dispatch($application, 'application_submitted');
            SendNotification::dispatch($application, 'new_application_assigned');

            return $application;
        });
    }

    /**
     * SECURITY FIX: Trigger Interpol check asynchronously.
     * Non-blocking - if it fails, application continues but is flagged for manual review.
     */
    protected function triggerInterpolCheckAsync(Application $application): void
    {
        // Only trigger if enabled in config
        if (!config('security.interpol.auto_trigger', true)) {
            return;
        }

        try {
            $aeropassService = app(AeropassService::class);
            
            $result = $aeropassService->triggerInterpolCheck([
                'uniqueReferenceId' => $application->reference_number,
                'firstName' => $application->first_name,
                'surname' => $application->last_name,
                'dateOfBirth' => $application->date_of_birth ? $application->date_of_birth->format('d/m/Y') : null,
                'nationality' => $application->nationality,
                'travelDocNumber' => $application->passport_number,
            ]);
            
            // Store check reference
            $application->update([
                'interpol_check_triggered_at' => now(),
                'interpol_check_status' => 'pending',
            ]);
            
            \Log::info('Interpol check triggered successfully', [
                'application_id' => $application->id,
                'reference' => $application->reference_number,
            ]);
            
        } catch (\Exception $e) {
            // If Interpol service is down, flag for manual review but don't block application
            \Log::error('Interpol check failed - flagged for manual review', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
            
            $application->update([
                'interpol_check_status' => 'failed',
                'requires_manual_interpol_check' => true,
            ]);
        }
    }

    /**
     * Valid status transitions matrix.
     * Each key is a source status, and its value is an array of valid target statuses.
     */
    protected const VALID_TRANSITIONS = [
        'draft'                      => ['submitted_awaiting_payment', 'cancelled'],
        'submitted_awaiting_payment' => ['pending_payment', 'paid_submitted', 'cancelled'],
        'pending_payment'            => ['paid_submitted', 'cancelled'],
        'paid_submitted'             => ['submitted', 'cancelled'],
        'submitted'                  => ['under_review', 'pending_approval', 'additional_info_requested', 'denied', 'cancelled'],
        'under_review'               => ['pending_approval', 'additional_info_requested', 'escalated', 'approved', 'denied'],
        'additional_info_requested'  => ['under_review', 'submitted', 'cancelled'],
        'escalated'                  => ['under_review', 'pending_approval', 'additional_info_requested', 'approved', 'denied'],
        'pending_approval'           => ['approved', 'denied', 'additional_info_requested', 'under_review', 'escalated'],
        'approved'                   => ['issued', 'under_review', 'denied'],
        'denied'                     => ['under_review', 'appealed'],
        'issued'                     => ['revoked', 'expired'],
        'revoked'                    => [],
        'expired'                    => [],
        'appealed'                   => ['under_review', 'denied'],
        'cancelled'                  => ['draft'],
    ];

    /**
     * Check if a status transition is valid.
     */
    public function isValidTransition(string $from, string $to): bool
    {
        $allowed = self::VALID_TRANSITIONS[$from] ?? [];
        return in_array($to, $allowed);
    }

    /**
     * Update application status with full audit trail.
     * Validates the transition against the allowed transition matrix.
     */
    public function changeStatus(Application $application, string $newStatus, ?string $notes = null): Application
    {
        $fromStatus = $application->status;

        if (!$this->isValidTransition($fromStatus, $newStatus)) {
            throw new \InvalidArgumentException(
                "Invalid status transition: {$fromStatus} → {$newStatus}"
            );
        }

        return DB::transaction(function () use ($application, $fromStatus, $newStatus, $notes) {
            $application->status = $newStatus;

            if (in_array($newStatus, ['approved', 'denied'])) {
                $application->decided_at = now();
                $application->decision_notes = $notes;
            }

            // Queue management: clear queue on terminal statuses, restore on revert
            if (in_array($newStatus, ['approved', 'denied', 'issued', 'cancelled', 'revoked', 'expired'])) {
                $application->current_queue = null;
            } elseif ($newStatus === 'under_review' && in_array($fromStatus, ['approved', 'denied', 'pending_approval', 'escalated', 'additional_info_requested', 'appealed'])) {
                $application->current_queue = 'review_queue';
            }

            $application->save();
            $this->recordStatusChange($application, $fromStatus, $newStatus, $notes);

            // Trigger risk scoring when application moves to under_review
            if ($newStatus === 'under_review') {
                $this->riskScoringOrchestrator->onStatusChangeToInReview($application);
            }

            // Send appropriate notifications based on status change
            if ($newStatus === 'approved') {
                SendNotification::dispatch($application, 'application_approved');
            } elseif ($newStatus === 'denied') {
                SendNotification::dispatch($application, 'application_denied');
            } elseif ($newStatus === 'issued') {
                SendNotification::dispatch($application, 'visa_issued');
            } elseif ($newStatus === 'additional_info_requested') {
                SendNotification::dispatch($application, 'document_reupload_required', ['reason' => $notes]);
            } else {
                SendNotification::dispatch($application, 'status_changed', ['status' => $newStatus]);
            }

            return $application;
        });
    }

    /**
     * Record a status transition in the history log.
     */
    protected function recordStatusChange(
        Application $application,
        ?string $from,
        string $to,
        ?string $notes = null
    ): void {
        ApplicationStatusHistory::create([
            'application_id' => $application->id,
            'changed_by'     => Auth::id(),
            'from_status'    => $from,
            'to_status'      => $to,
            'notes'          => $notes,
            'ip_address'     => Request::ip(),
        ]);
    }
}
