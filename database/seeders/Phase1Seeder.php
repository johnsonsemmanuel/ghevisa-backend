<?php

namespace Database\Seeders;

use App\Models\ReasonCode;
use App\Models\ServiceTier;
use App\Models\TierRule;
use App\Models\VisaType;
use Illuminate\Database\Seeder;

class Phase1Seeder extends Seeder
{
    public function run(): void
    {
        $this->seedServiceTiers();
        $this->seedReasonCodes();
        $this->seedVisaTypes();
    }

    private function seedServiceTiers(): void
    {
        $tiers = [
            [
                'code' => 'standard',
                'name' => 'Standard Processing',
                'description' => 'Regular processing time for non-urgent applications',
                'processing_hours' => 120,
                'processing_time_display' => '3-5 business days',
                'fee_multiplier' => 1.00,
                'additional_fee' => 0,
                'sort_order' => 1,
            ],
            [
                'code' => 'express',
                'name' => 'Express Processing',
                'description' => 'Faster processing for time-sensitive travel',
                'processing_hours' => 48,
                'processing_time_display' => '24-48 hours',
                'fee_multiplier' => 1.50,
                'additional_fee' => 25,
                'sort_order' => 2,
            ],
            [
                'code' => 'premium',
                'name' => 'Premium Processing',
                'description' => 'Priority processing with dedicated support',
                'processing_hours' => 24,
                'processing_time_display' => 'Same day (24 hours)',
                'fee_multiplier' => 2.00,
                'additional_fee' => 50,
                'sort_order' => 3,
            ],
            [
                'code' => 'emergency',
                'name' => 'Emergency Processing',
                'description' => 'Urgent cases requiring immediate attention',
                'processing_hours' => 6,
                'processing_time_display' => '4-6 hours',
                'fee_multiplier' => 3.00,
                'additional_fee' => 100,
                'sort_order' => 4,
            ],
        ];

        foreach ($tiers as $tier) {
            ServiceTier::updateOrCreate(['code' => $tier['code']], $tier);
        }
    }

    private function seedReasonCodes(): void
    {
        $codes = [
            // Approval Codes
            ['code' => 'AP01', 'action_type' => 'approve', 'reason' => 'All documents verified', 'description' => 'All submitted documents have been verified and meet requirements', 'sort_order' => 1],
            ['code' => 'AP02', 'action_type' => 'approve', 'reason' => 'Background check passed', 'description' => 'Security and background verification completed successfully', 'sort_order' => 2],
            ['code' => 'AP03', 'action_type' => 'approve', 'reason' => 'Meets eligibility criteria', 'description' => 'Applicant meets all eligibility requirements for this visa type', 'sort_order' => 3],
            ['code' => 'AP04', 'action_type' => 'approve', 'reason' => 'Valid invitation/sponsorship', 'description' => 'Invitation letter or sponsorship documents verified', 'sort_order' => 4],
            ['code' => 'AP05', 'action_type' => 'approve', 'reason' => 'Diplomatic clearance received', 'description' => 'Required diplomatic clearances obtained', 'sort_order' => 5],

            // Rejection Codes
            ['code' => 'RJ01', 'action_type' => 'reject', 'reason' => 'Incomplete documentation', 'description' => 'Required documents missing or incomplete after multiple requests', 'sort_order' => 1],
            ['code' => 'RJ02', 'action_type' => 'reject', 'reason' => 'Fraudulent documents', 'description' => 'Submitted documents identified as fraudulent or forged', 'sort_order' => 2],
            ['code' => 'RJ03', 'action_type' => 'reject', 'reason' => 'Security concern', 'description' => 'Application flagged due to security concerns', 'sort_order' => 3],
            ['code' => 'RJ04', 'action_type' => 'reject', 'reason' => 'Previous visa violation', 'description' => 'Applicant has history of visa violations or overstay', 'sort_order' => 4],
            ['code' => 'RJ05', 'action_type' => 'reject', 'reason' => 'Ineligible nationality', 'description' => 'Applicant nationality not eligible for this visa type', 'sort_order' => 5],
            ['code' => 'RJ06', 'action_type' => 'reject', 'reason' => 'Criminal record', 'description' => 'Applicant has disqualifying criminal history', 'sort_order' => 6],
            ['code' => 'RJ07', 'action_type' => 'reject', 'reason' => 'Insufficient funds', 'description' => 'Unable to demonstrate sufficient financial means', 'sort_order' => 7],
            ['code' => 'RJ08', 'action_type' => 'reject', 'reason' => 'Invalid passport', 'description' => 'Passport expired, damaged, or insufficient validity', 'sort_order' => 8],

            // Request More Info Codes
            ['code' => 'RMI01', 'action_type' => 'request_info', 'reason' => 'Passport bio page unclear', 'description' => 'Please upload a clearer scan of passport bio page', 'sort_order' => 1],
            ['code' => 'RMI02', 'action_type' => 'request_info', 'reason' => 'Photo does not meet requirements', 'description' => 'Please upload a passport-style photo meeting specifications', 'sort_order' => 2],
            ['code' => 'RMI03', 'action_type' => 'request_info', 'reason' => 'Invitation letter required', 'description' => 'Please provide invitation letter from host organization', 'sort_order' => 3],
            ['code' => 'RMI04', 'action_type' => 'request_info', 'reason' => 'Proof of accommodation needed', 'description' => 'Please provide hotel booking or accommodation details', 'sort_order' => 4],
            ['code' => 'RMI05', 'action_type' => 'request_info', 'reason' => 'Flight itinerary required', 'description' => 'Please provide confirmed flight booking details', 'sort_order' => 5],
            ['code' => 'RMI06', 'action_type' => 'request_info', 'reason' => 'Bank statement required', 'description' => 'Please provide recent bank statements (last 3 months)', 'sort_order' => 6],
            ['code' => 'RMI07', 'action_type' => 'request_info', 'reason' => 'Employment verification needed', 'description' => 'Please provide employment letter or business registration', 'sort_order' => 7],
            ['code' => 'RMI08', 'action_type' => 'request_info', 'reason' => 'Medical certificate required', 'description' => 'Please provide required medical certificates', 'sort_order' => 8],

            // Escalation Codes
            ['code' => 'ESC01', 'action_type' => 'escalate', 'reason' => 'Security flag - requires MFA review', 'description' => 'Application flagged for security review by MFA', 'sort_order' => 1],
            ['code' => 'ESC02', 'action_type' => 'escalate', 'reason' => 'Complex case - senior review', 'description' => 'Case requires senior officer assessment', 'sort_order' => 2],
            ['code' => 'ESC03', 'action_type' => 'escalate', 'reason' => 'Diplomatic case', 'description' => 'Requires diplomatic clearance or foreign ministry coordination', 'sort_order' => 3],
            ['code' => 'ESC04', 'action_type' => 'escalate', 'reason' => 'Policy exception required', 'description' => 'Case requires policy exception approval', 'sort_order' => 4],

            // Border Control - Admit (BP-A codes)
            ['code' => 'BP-A01', 'action_type' => 'border_admit', 'reason' => 'Visa/ETA valid and matched', 'description' => 'Travel authorization verified and passport matched', 'sort_order' => 1],
            ['code' => 'BP-A02', 'action_type' => 'border_admit', 'reason' => 'Secondary cleared', 'description' => 'Cleared after secondary inspection', 'sort_order' => 2],
            ['code' => 'BP-A03', 'action_type' => 'border_admit', 'reason' => 'Supervisor override approved', 'description' => 'Entry approved by supervisor override', 'sort_order' => 3],
            ['code' => 'BP-A04', 'action_type' => 'border_admit', 'reason' => 'ECOWAS citizen - visa exempt', 'description' => 'ECOWAS national with valid travel document', 'sort_order' => 4],
            ['code' => 'BP-A05', 'action_type' => 'border_admit', 'reason' => 'Diplomatic passport holder', 'description' => 'Diplomatic immunity verified', 'sort_order' => 5],

            // Border Control - Secondary (BP-S codes)
            ['code' => 'BP-S01', 'action_type' => 'border_secondary', 'reason' => 'Document clarity issue', 'description' => 'Documents require detailed inspection for clarity', 'sort_order' => 1],
            ['code' => 'BP-S02', 'action_type' => 'border_secondary', 'reason' => 'Host/accommodation verification', 'description' => 'Need to verify host or accommodation details', 'sort_order' => 2],
            ['code' => 'BP-S03', 'action_type' => 'border_secondary', 'reason' => 'Travel purpose clarification', 'description' => 'Purpose of visit requires clarification', 'sort_order' => 3],
            ['code' => 'BP-S04', 'action_type' => 'border_secondary', 'reason' => 'Payment confirmation pending', 'description' => 'Visa payment status needs verification', 'sort_order' => 4],
            ['code' => 'BP-S05', 'action_type' => 'border_secondary', 'reason' => 'PNR/flight mismatch', 'description' => 'Flight manifest does not match travel documents', 'sort_order' => 5],
            ['code' => 'BP-S06', 'action_type' => 'border_secondary', 'reason' => 'Interview required', 'description' => 'Traveler requires interview with immigration officer', 'sort_order' => 6],

            // Border Control - Deny/Hold (BP-D codes)
            ['code' => 'BP-D01', 'action_type' => 'border_deny', 'reason' => 'No valid authorization', 'description' => 'Traveler lacks valid visa or ETA', 'sort_order' => 1],
            ['code' => 'BP-D02', 'action_type' => 'border_deny', 'reason' => 'Passport mismatch', 'description' => 'Passport does not match visa/ETA record', 'sort_order' => 2],
            ['code' => 'BP-D03', 'action_type' => 'border_deny', 'reason' => 'Watchlist/Interpol hit', 'description' => 'Traveler flagged on security watchlist or Interpol', 'sort_order' => 3],
            ['code' => 'BP-D04', 'action_type' => 'border_deny', 'reason' => 'Suspected fraud', 'description' => 'Fraudulent documents or identity suspected', 'sort_order' => 4],
            ['code' => 'BP-D05', 'action_type' => 'border_deny', 'reason' => 'Expired/invalid entry window', 'description' => 'Visa expired or outside valid entry dates', 'sort_order' => 5],
            ['code' => 'BP-D06', 'action_type' => 'border_deny', 'reason' => 'Entry limit exceeded', 'description' => 'Maximum entries for visa type exceeded', 'sort_order' => 6],
        ];

        foreach ($codes as $code) {
            ReasonCode::updateOrCreate(['code' => $code['code']], $code);
        }
    }

    private function seedVisaTypes(): void
    {
        // Update existing visa types with new fields
        VisaType::where('slug', 'tourism')->update([
            'government_fee' => 40.00,
            'platform_fee' => 10.00,
            'entry_type' => 'single',
            'validity_period' => '30-90 days',
            'category' => 'visa',
            'sort_order' => 1,
            'required_fields' => [
                'passport_number', 'passport_expiry', 'nationality', 'date_of_birth',
                'intended_arrival_date', 'intended_departure_date', 'port_of_entry',
                'address_in_ghana', 'email', 'phone'
            ],
            'optional_fields' => ['hotel_booking', 'return_ticket'],
            'default_processing_days' => 5,
            'default_route_to' => 'gis',
        ]);

        VisaType::where('slug', 'business')->update([
            'government_fee' => 80.00,
            'platform_fee' => 20.00,
            'entry_type' => 'multiple',
            'validity_period' => '90 days - 1 year',
            'category' => 'visa',
            'sort_order' => 2,
            'required_fields' => [
                'passport_number', 'passport_expiry', 'nationality', 'date_of_birth',
                'intended_arrival_date', 'intended_departure_date', 'company_name',
                'company_address', 'invitation_letter', 'email', 'phone'
            ],
            'optional_fields' => ['business_registration', 'conference_registration'],
            'default_processing_days' => 5,
            'default_route_to' => 'gis',
        ]);

        // Create new visa types
        $newVisaTypes = [
            [
                'name' => 'Student Visa',
                'slug' => 'student',
                'description' => 'For international students enrolled in Ghanaian educational institutions.',
                'base_fee' => 100.00,
                'government_fee' => 80.00,
                'platform_fee' => 20.00,
                'max_duration_days' => 365,
                'entry_type' => 'multiple',
                'validity_period' => '1 year (renewable)',
                'category' => 'visa',
                'sort_order' => 3,
                'required_documents' => ['passport_bio', 'photo', 'admission_letter', 'financial_proof'],
                'required_fields' => [
                    'passport_number', 'passport_expiry', 'nationality', 'date_of_birth',
                    'institution_name', 'course_of_study', 'admission_letter',
                    'sponsor_details', 'email', 'phone'
                ],
                'optional_fields' => ['scholarship_letter', 'previous_qualifications'],
                'default_processing_days' => 10,
                'default_route_to' => 'mfa',
                'is_active' => true,
            ],
            [
                'name' => 'Work Permit Visa',
                'slug' => 'work',
                'description' => 'For foreign nationals seeking employment in Ghana.',
                'base_fee' => 200.00,
                'government_fee' => 160.00,
                'platform_fee' => 40.00,
                'max_duration_days' => 365,
                'entry_type' => 'multiple',
                'validity_period' => '1-2 years',
                'category' => 'visa',
                'sort_order' => 4,
                'required_documents' => ['passport_bio', 'photo', 'employment_contract', 'company_letter', 'qualifications'],
                'required_fields' => [
                    'passport_number', 'passport_expiry', 'nationality', 'date_of_birth',
                    'employer_name', 'employer_address', 'job_title', 'employment_contract',
                    'ghana_investment_promotion_approval', 'email', 'phone'
                ],
                'optional_fields' => ['professional_certifications', 'work_experience'],
                'default_processing_days' => 15,
                'default_route_to' => 'mfa',
                'is_active' => true,
            ],
            [
                'name' => 'Transit Visa',
                'slug' => 'transit',
                'description' => 'For travelers passing through Ghana to another destination.',
                'base_fee' => 30.00,
                'government_fee' => 25.00,
                'platform_fee' => 5.00,
                'max_duration_days' => 3,
                'entry_type' => 'single',
                'validity_period' => '72 hours',
                'category' => 'visa',
                'sort_order' => 5,
                'required_documents' => ['passport_bio', 'photo', 'onward_ticket'],
                'required_fields' => [
                    'passport_number', 'passport_expiry', 'nationality', 'date_of_birth',
                    'arrival_flight', 'departure_flight', 'final_destination', 'email'
                ],
                'optional_fields' => [],
                'default_processing_days' => 2,
                'default_route_to' => 'gis',
                'is_active' => true,
            ],
            [
                'name' => 'Medical Visa',
                'slug' => 'medical',
                'description' => 'For individuals seeking medical treatment in Ghana.',
                'base_fee' => 80.00,
                'government_fee' => 60.00,
                'platform_fee' => 20.00,
                'max_duration_days' => 180,
                'entry_type' => 'single',
                'validity_period' => '90-180 days',
                'category' => 'visa',
                'sort_order' => 6,
                'required_documents' => ['passport_bio', 'photo', 'hospital_letter', 'medical_records'],
                'required_fields' => [
                    'passport_number', 'passport_expiry', 'nationality', 'date_of_birth',
                    'hospital_name', 'treatment_type', 'appointment_date',
                    'accompanying_persons', 'email', 'phone'
                ],
                'optional_fields' => ['insurance_details', 'doctor_referral'],
                'default_processing_days' => 5,
                'default_route_to' => 'gis',
                'is_active' => true,
            ],
            [
                'name' => 'Conference/Event Visa',
                'slug' => 'conference',
                'description' => 'For attendees of conferences, seminars, or special events.',
                'base_fee' => 60.00,
                'government_fee' => 50.00,
                'platform_fee' => 10.00,
                'max_duration_days' => 30,
                'entry_type' => 'single',
                'validity_period' => '14-30 days',
                'category' => 'visa',
                'sort_order' => 7,
                'required_documents' => ['passport_bio', 'photo', 'conference_invitation', 'registration_confirmation'],
                'required_fields' => [
                    'passport_number', 'passport_expiry', 'nationality', 'date_of_birth',
                    'event_name', 'event_dates', 'event_location', 'organizer_details',
                    'email', 'phone'
                ],
                'optional_fields' => ['presentation_abstract', 'speaker_confirmation'],
                'default_processing_days' => 5,
                'default_route_to' => 'gis',
                'is_active' => true,
            ],
            [
                'name' => 'Diplomatic Visa',
                'slug' => 'diplomatic',
                'description' => 'For diplomatic personnel and official government representatives.',
                'base_fee' => 0.00,
                'government_fee' => 0.00,
                'platform_fee' => 0.00,
                'max_duration_days' => 365,
                'entry_type' => 'multiple',
                'validity_period' => 'Duration of posting',
                'category' => 'visa',
                'sort_order' => 8,
                'required_documents' => ['diplomatic_passport', 'photo', 'diplomatic_note'],
                'required_fields' => [
                    'passport_number', 'passport_expiry', 'nationality', 'date_of_birth',
                    'diplomatic_rank', 'sending_ministry', 'mission_type',
                    'diplomatic_note_number', 'email'
                ],
                'optional_fields' => ['dependents_details'],
                'default_processing_days' => 3,
                'default_route_to' => 'mfa',
                'is_active' => true,
            ],
            [
                'name' => 'Emergency/Humanitarian Visa',
                'slug' => 'emergency',
                'description' => 'For urgent humanitarian cases including family emergencies.',
                'base_fee' => 50.00,
                'government_fee' => 40.00,
                'platform_fee' => 10.00,
                'max_duration_days' => 30,
                'entry_type' => 'single',
                'validity_period' => '14-30 days',
                'category' => 'visa',
                'sort_order' => 9,
                'required_documents' => ['passport_bio', 'photo', 'emergency_proof'],
                'required_fields' => [
                    'passport_number', 'passport_expiry', 'nationality', 'date_of_birth',
                    'emergency_type', 'emergency_description', 'contact_in_ghana',
                    'relationship', 'email', 'phone'
                ],
                'optional_fields' => ['hospital_letter', 'death_certificate', 'police_report'],
                'default_processing_days' => 1,
                'default_route_to' => 'gis',
                'is_active' => true,
            ],
            [
                'name' => 'ECOWAS ETA',
                'slug' => 'ecowas-eta',
                'description' => 'Electronic Travel Authorization for ECOWAS citizens (visa-free entry registration).',
                'base_fee' => 10.00,
                'government_fee' => 5.00,
                'platform_fee' => 5.00,
                'max_duration_days' => 90,
                'entry_type' => 'multiple',
                'validity_period' => '90 days',
                'category' => 'eta',
                'sort_order' => 10,
                'required_documents' => ['passport_bio', 'photo'],
                'required_fields' => [
                    'passport_number', 'passport_expiry', 'nationality', 'date_of_birth',
                    'intended_arrival_date', 'port_of_entry', 'address_in_ghana', 'email'
                ],
                'optional_fields' => ['return_ticket'],
                'default_processing_days' => 1,
                'default_route_to' => 'gis',
                'eligible_nationalities' => [
                    'NG', 'SN', 'CI', 'ML', 'BF', 'NE', 'BJ', 'TG', 'GN', 'SL', 'LR', 'GM', 'GW', 'CV'
                ],
                'is_active' => true,
            ],
            [
                'name' => 'African Union ETA',
                'slug' => 'au-eta',
                'description' => 'Electronic Travel Authorization for African Union member state citizens (replaces Visa on Arrival).',
                'base_fee' => 25.00,
                'government_fee' => 15.00,
                'platform_fee' => 10.00,
                'max_duration_days' => 30,
                'entry_type' => 'single',
                'validity_period' => '30 days',
                'category' => 'eta',
                'sort_order' => 11,
                'required_documents' => ['passport_bio', 'photo'],
                'required_fields' => [
                    'passport_number', 'passport_expiry', 'nationality', 'date_of_birth',
                    'intended_arrival_date', 'port_of_entry', 'address_in_ghana', 'email', 'phone'
                ],
                'optional_fields' => ['return_ticket', 'hotel_booking'],
                'default_processing_days' => 2,
                'default_route_to' => 'gis',
                'eligible_nationalities' => [
                    // East Africa
                    'KE', 'TZ', 'RW', 'UG', 'ET', 'DJ', 'SO', 'SS', 'SD', 'ER',
                    // Southern Africa
                    'ZA', 'NA', 'BW', 'ZW', 'ZM', 'MW', 'MZ', 'AO', 'SZ', 'LS',
                    // North Africa
                    'MA', 'TN', 'DZ', 'EG', 'LY',
                    // Central Africa
                    'GA', 'CM', 'CG', 'CD', 'CF', 'TD', 'GQ', 'ST',
                ],
                'is_active' => true,
            ],
            [
                'name' => 'Caribbean ETA',
                'slug' => 'caribbean-eta',
                'description' => 'Electronic Travel Authorization for Caribbean visa-waiver countries.',
                'base_fee' => 15.00,
                'government_fee' => 10.00,
                'platform_fee' => 5.00,
                'max_duration_days' => 90,
                'entry_type' => 'single',
                'validity_period' => '90 days',
                'category' => 'eta',
                'sort_order' => 12,
                'required_documents' => ['passport_bio', 'photo'],
                'required_fields' => [
                    'passport_number', 'passport_expiry', 'nationality', 'date_of_birth',
                    'intended_arrival_date', 'port_of_entry', 'address_in_ghana', 'email', 'phone'
                ],
                'optional_fields' => ['return_ticket', 'hotel_booking'],
                'default_processing_days' => 1,
                'default_route_to' => 'gis',
                'eligible_nationalities' => [
                    'BB', 'BS', 'GD', 'JM', 'TT', 'AG', 'DM', 'KN', 'LC', 'VC'
                ],
                'is_active' => true,
            ],
        ];

        foreach ($newVisaTypes as $visaType) {
            VisaType::updateOrCreate(['slug' => $visaType['slug']], $visaType);
        }

        // Create tier rules for new visa types
        $this->createTierRulesForNewVisaTypes();
    }

    private function createTierRulesForNewVisaTypes(): void
    {
        $visaTypes = VisaType::whereIn('slug', ['student', 'work', 'transit', 'medical', 'conference', 'diplomatic', 'emergency'])->get();

        foreach ($visaTypes as $visaType) {
            // Standard tier
            TierRule::updateOrCreate(
                ['visa_type_id' => $visaType->id, 'processing_tier' => 'standard'],
                [
                    'tier' => 'tier_1',
                    'name' => "Standard {$visaType->name}",
                    'description' => "Standard processing for {$visaType->name}",
                    'conditions' => [],
                    'route_to' => $visaType->default_route_to,
                    'sla_hours' => $visaType->default_processing_days * 24,
                    'priority' => 5,
                    'price_multiplier' => 1.00,
                    'is_active' => true,
                ]
            );

            // Express tier (except diplomatic)
            if ($visaType->slug !== 'diplomatic') {
                TierRule::updateOrCreate(
                    ['visa_type_id' => $visaType->id, 'processing_tier' => 'express'],
                    [
                        'tier' => 'tier_1',
                        'name' => "Express {$visaType->name}",
                        'description' => "Expedited processing for {$visaType->name}",
                        'conditions' => [],
                        'route_to' => 'gis',
                        'sla_hours' => 48,
                        'priority' => 15,
                        'price_multiplier' => 1.50,
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
