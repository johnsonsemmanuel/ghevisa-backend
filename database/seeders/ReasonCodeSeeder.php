<?php

namespace Database\Seeders;

use App\Models\ReasonCode;
use Illuminate\Database\Seeder;

class ReasonCodeSeeder extends Seeder
{
    public function run(): void
    {
        $reasonCodes = [
            // Approval Reasons
            ['code' => 'APP-001', 'action_type' => 'approve', 'reason' => 'All requirements met', 'description' => 'Application meets all visa requirements and documentation is complete'],
            ['code' => 'APP-002', 'action_type' => 'approve', 'reason' => 'Valid purpose of visit', 'description' => 'Purpose of visit is legitimate and well-documented'],
            ['code' => 'APP-003', 'action_type' => 'approve', 'reason' => 'Sufficient financial proof', 'description' => 'Applicant has demonstrated sufficient financial means'],
            ['code' => 'APP-004', 'action_type' => 'approve', 'reason' => 'Strong ties to home country', 'description' => 'Applicant has strong ties to country of origin'],

            // Rejection Reasons
            ['code' => 'REJ-001', 'action_type' => 'reject', 'reason' => 'Incomplete documentation', 'description' => 'Required documents are missing or incomplete'],
            ['code' => 'REJ-002', 'action_type' => 'reject', 'reason' => 'Insufficient financial proof', 'description' => 'Unable to demonstrate sufficient financial means for the visit'],
            ['code' => 'REJ-003', 'action_type' => 'reject', 'reason' => 'Invalid passport', 'description' => 'Passport is expired, damaged, or does not meet validity requirements'],
            ['code' => 'REJ-004', 'action_type' => 'reject', 'reason' => 'Security concerns', 'description' => 'Application flagged for security or risk assessment concerns'],
            ['code' => 'REJ-005', 'action_type' => 'reject', 'reason' => 'Previous visa violations', 'description' => 'History of visa violations or overstays'],
            ['code' => 'REJ-006', 'action_type' => 'reject', 'reason' => 'Fraudulent documents', 'description' => 'Submitted documents appear to be fraudulent or falsified'],
            ['code' => 'REJ-007', 'action_type' => 'reject', 'reason' => 'Unclear purpose of visit', 'description' => 'Purpose of visit is not clearly stated or appears inconsistent'],
            ['code' => 'REJ-008', 'action_type' => 'reject', 'reason' => 'Watchlist match', 'description' => 'Applicant matches entry on security watchlist'],
            ['code' => 'REJ-009', 'action_type' => 'reject', 'reason' => 'Ineligible nationality', 'description' => 'Applicant nationality is not eligible for this visa type'],
            ['code' => 'REJ-010', 'action_type' => 'reject', 'reason' => 'Criminal record', 'description' => 'Applicant has relevant criminal history'],

            // Information Request Reasons
            ['code' => 'INFO-001', 'action_type' => 'request_info', 'reason' => 'Additional financial documents needed', 'description' => 'Need more proof of financial capacity'],
            ['code' => 'INFO-002', 'action_type' => 'request_info', 'reason' => 'Clarification on purpose of visit', 'description' => 'Need more details about the purpose and itinerary'],
            ['code' => 'INFO-003', 'action_type' => 'request_info', 'reason' => 'Updated passport copy required', 'description' => 'Current passport copy is unclear or outdated'],
            ['code' => 'INFO-004', 'action_type' => 'request_info', 'reason' => 'Missing supporting documents', 'description' => 'Additional supporting documents are required'],
            ['code' => 'INFO-005', 'action_type' => 'request_info', 'reason' => 'Accommodation details needed', 'description' => 'Need proof of accommodation arrangements'],
            ['code' => 'INFO-006', 'action_type' => 'request_info', 'reason' => 'Travel itinerary required', 'description' => 'Need detailed travel plans and itinerary'],

            // Escalation Reasons
            ['code' => 'ESC-001', 'action_type' => 'escalate', 'reason' => 'Complex case requiring MFA review', 'description' => 'Case complexity requires higher-level review'],
            ['code' => 'ESC-002', 'action_type' => 'escalate', 'reason' => 'Diplomatic considerations', 'description' => 'Case involves diplomatic or political considerations'],
            ['code' => 'ESC-003', 'action_type' => 'escalate', 'reason' => 'High-value applicant', 'description' => 'Applicant is VIP or high-value visitor'],
            ['code' => 'ESC-004', 'action_type' => 'escalate', 'reason' => 'Policy clarification needed', 'description' => 'Case requires policy interpretation or guidance'],
            ['code' => 'ESC-005', 'action_type' => 'escalate', 'reason' => 'Security clearance required', 'description' => 'Case requires additional security clearance'],
        ];

        foreach ($reasonCodes as $code) {
            ReasonCode::create([
                'code' => $code['code'],
                'action_type' => $code['action_type'],
                'reason' => $code['reason'],
                'description' => $code['description'],
                'is_active' => true,
            ]);
        }
    }
}
