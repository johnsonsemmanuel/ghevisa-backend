<?php

namespace Database\Seeders;

use App\Models\ReasonCode;
use Illuminate\Database\Seeder;

class ReasonCodeSeeder extends Seeder
{
    public function run(): void
    {
        $reasonCodes = [
            // Request Info Codes
            [
                'code' => 'MISSING_PASSPORT_COPY',
                'action_type' => 'request_info',
                'reason' => 'Missing Passport Copy',
                'description' => 'Clear copy of passport bio-data page required',
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'code' => 'MISSING_PHOTO',
                'action_type' => 'request_info',
                'reason' => 'Missing Passport Photo',
                'description' => 'Recent passport-sized photograph required',
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'code' => 'MISSING_FINANCIAL_PROOF',
                'action_type' => 'request_info',
                'reason' => 'Missing Financial Documents',
                'description' => 'Bank statements or proof of funds required',
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'code' => 'MISSING_ACCOMMODATION',
                'action_type' => 'request_info',
                'reason' => 'Missing Accommodation Details',
                'description' => 'Hotel booking or host information required',
                'is_active' => true,
                'sort_order' => 4,
            ],
            [
                'code' => 'MISSING_FLIGHT_ITINERARY',
                'action_type' => 'request_info',
                'reason' => 'Missing Flight Itinerary',
                'description' => 'Flight booking confirmation or travel itinerary required',
                'is_active' => true,
                'sort_order' => 5,
            ],
            [
                'code' => 'UNCLEAR_PURPOSE',
                'action_type' => 'request_info',
                'reason' => 'Unclear Purpose of Visit',
                'description' => 'Additional details about travel purpose required',
                'is_active' => true,
                'sort_order' => 6,
            ],
            [
                'code' => 'MISSING_INVITATION',
                'action_type' => 'request_info',
                'reason' => 'Missing Invitation Letter',
                'description' => 'Official invitation letter from host organization required',
                'is_active' => true,
                'sort_order' => 7,
            ],
            [
                'code' => 'DOCUMENT_QUALITY',
                'action_type' => 'request_info',
                'reason' => 'Poor Document Quality',
                'description' => 'Clearer, higher quality document images required',
                'is_active' => true,
                'sort_order' => 8,
            ],

            // Rejection Codes
            [
                'code' => 'INSUFFICIENT_FUNDS',
                'action_type' => 'reject',
                'reason' => 'Insufficient Financial Resources',
                'description' => 'Applicant does not demonstrate adequate financial means',
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'code' => 'INVALID_PASSPORT',
                'action_type' => 'reject',
                'reason' => 'Invalid or Expired Passport',
                'description' => 'Passport is invalid, expired, or does not meet requirements',
                'is_active' => true,
                'sort_order' => 11,
            ],
            [
                'code' => 'SECURITY_CONCERNS',
                'action_type' => 'reject',
                'reason' => 'Security Concerns',
                'description' => 'Application flagged for security reasons',
                'is_active' => true,
                'sort_order' => 12,
            ],
            [
                'code' => 'FRAUDULENT_DOCUMENTS',
                'action_type' => 'reject',
                'reason' => 'Fraudulent or Forged Documents',
                'description' => 'Submitted documents appear to be fraudulent or altered',
                'is_active' => true,
                'sort_order' => 13,
            ],
            [
                'code' => 'PREVIOUS_VIOLATIONS',
                'action_type' => 'reject',
                'reason' => 'Previous Immigration Violations',
                'description' => 'History of immigration violations or overstays',
                'is_active' => true,
                'sort_order' => 14,
            ],
            [
                'code' => 'INCOMPLETE_APPLICATION',
                'action_type' => 'reject',
                'reason' => 'Incomplete Application',
                'description' => 'Application remains incomplete after multiple requests',
                'is_active' => true,
                'sort_order' => 15,
            ],
            [
                'code' => 'TRAVEL_PURPOSE_UNCLEAR',
                'action_type' => 'reject',
                'reason' => 'Unclear Travel Purpose',
                'description' => 'Purpose of visit is not clearly established or credible',
                'is_active' => true,
                'sort_order' => 16,
            ],
            [
                'code' => 'HEALTH_CONCERNS',
                'action_type' => 'reject',
                'reason' => 'Health and Safety Concerns',
                'description' => 'Health declarations indicate potential public health risk',
                'is_active' => true,
                'sort_order' => 17,
            ],
            [
                'code' => 'CRIMINAL_BACKGROUND',
                'action_type' => 'reject',
                'reason' => 'Criminal Background',
                'description' => 'Criminal history that poses a risk to public safety',
                'is_active' => true,
                'sort_order' => 18,
            ],

            // Approval Codes (optional, for tracking approval reasons)
            [
                'code' => 'STANDARD_APPROVAL',
                'action_type' => 'approve',
                'reason' => 'Standard Approval',
                'description' => 'Application meets all requirements for approval',
                'is_active' => true,
                'sort_order' => 20,
            ],
            [
                'code' => 'EXPEDITED_APPROVAL',
                'action_type' => 'approve',
                'reason' => 'Expedited Processing',
                'description' => 'Application approved under expedited processing',
                'is_active' => true,
                'sort_order' => 21,
            ],

            // Border Control Codes
            [
                'code' => 'BORDER_ADMIT',
                'action_type' => 'border_admit',
                'reason' => 'Admitted at Border',
                'description' => 'Traveler admitted to Ghana',
                'is_active' => true,
                'sort_order' => 30,
            ],
            [
                'code' => 'BORDER_DENY_EXPIRED',
                'action_type' => 'border_deny',
                'reason' => 'Expired Authorization',
                'description' => 'Travel authorization has expired',
                'is_active' => true,
                'sort_order' => 31,
            ],
            [
                'code' => 'BORDER_DENY_INVALID',
                'action_type' => 'border_deny',
                'reason' => 'Invalid Documentation',
                'description' => 'Travel documents are invalid or fraudulent',
                'is_active' => true,
                'sort_order' => 32,
            ],
            [
                'code' => 'BORDER_SECONDARY',
                'action_type' => 'border_secondary',
                'reason' => 'Secondary Inspection',
                'description' => 'Referred for additional screening',
                'is_active' => true,
                'sort_order' => 33,
            ],
        ];

        foreach ($reasonCodes as $code) {
            ReasonCode::updateOrCreate(
                ['code' => $code['code']],
                $code
            );
        }

        $this->command->info('Reason codes seeded successfully.');
    }
}