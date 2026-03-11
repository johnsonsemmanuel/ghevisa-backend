<?php

return [
    'enabled' => env('RISK_SCORING_ENABLED', true),
    
    'categories' => [
        'identity' => [
            'max_points' => 30,
            'rules' => [
                'passport_expiry' => [
                    'points' => 25,
                    'description' => 'Passport expires within 6 months of intended arrival date',
                ],
                'name_mismatch' => [
                    'points' => 20,
                    'description' => 'Passport name does not match application name',
                ],
                'ocr_unreadable' => [
                    'points' => 10,
                    'description' => 'Passport document is unreadable or OCR verification failed',
                ],
                'recent_issuance' => [
                    'points' => 10,
                    'description' => 'Passport was issued less than 6 months ago',
                ],
                'nationality_flag' => [
                    'points' => 15,
                    'description' => 'Nationality flagged for additional review',
                ],
            ],
        ],
        
        'travel' => [
            'max_points' => 20,
            'rules' => [
                'duration_inconsistent' => [
                    'points' => 15,
                    'description' => 'Requested stay duration is inconsistent with visa type',
                ],
                'no_return_ticket' => [
                    'points' => 10,
                    'description' => 'No return ticket document provided',
                ],
                'no_accommodation' => [
                    'points' => 10,
                    'description' => 'Accommodation information is missing or unclear',
                ],
                'high_risk_regions' => [
                    'points' => 20,
                    'description' => 'Frequent recent travel to high-risk regions',
                ],
                'purpose_mismatch' => [
                    'points' => 15,
                    'description' => 'Travel purpose is inconsistent with supporting documents',
                ],
            ],
        ],
        
        'financial' => [
            'max_points' => 20,
            'rules' => [
                'no_proof_of_funds' => [
                    'points' => 20,
                    'description' => 'Proof of funds documents are missing',
                ],
                'bank_statement_pending' => [
                    'points' => 10,
                    'description' => 'Bank statements are under verification',
                ],
                'host_verification_pending' => [
                    'points' => 10,
                    'description' => 'Host verification is pending',
                ],
                'sponsor_incomplete' => [
                    'points' => 10,
                    'description' => 'Sponsor details are incomplete',
                ],
                'no_business_invitation' => [
                    'points' => 15,
                    'description' => 'Business visa application missing invitation letter',
                ],
            ],
        ],
        
        'immigration' => [
            'max_points' => 20, // Note: watchlist can add up to 50
            'rules' => [
                'prior_refusal' => [
                    'points' => 20,
                    'description' => 'Applicant has declared a prior visa refusal',
                ],
                'prior_overstay' => [
                    'points' => 30,
                    'description' => 'Applicant has a prior overstay in Ghana',
                ],
                'deportation' => [
                    'points' => 40,
                    'description' => 'Applicant has a prior deportation record',
                ],
                'watchlist_match' => [
                    'points' => 50,
                    'description' => 'Applicant matches watchlist entry (sanctions or security concern)',
                ],
                'inconsistent_declarations' => [
                    'points' => 25,
                    'description' => 'Inconsistent immigration declarations detected',
                ],
            ],
        ],
        
        'document' => [
            'max_points' => 10,
            'rules' => [
                'missing_required' => [
                    'points' => 15,
                    'description' => 'Required documents are missing',
                ],
                'unreadable' => [
                    'points' => 10,
                    'description' => 'Uploaded documents are blurry or unreadable',
                ],
                'partial_pages' => [
                    'points' => 5,
                    'description' => 'Document uploads contain partial pages',
                ],
                'invalid_format' => [
                    'points' => 5,
                    'description' => 'Invalid file format detected',
                ],
            ],
        ],
    ],
    
    'risk_levels' => [
        'low' => ['min' => 0, 'max' => 24],
        'medium' => ['min' => 25, 'max' => 49],
        'high' => ['min' => 50, 'max' => 74],
        'critical' => ['min' => 75, 'max' => 100],
    ],
    
    'flagged_nationalities' => [
        'high_risk' => ['KP', 'IR', 'SY', 'CU', 'VE', 'AF', 'IQ', 'LY', 'SO', 'YE'],
        'medium_risk' => ['PK', 'BD', 'LK', 'NP', 'MM', 'ET', 'ER', 'SD'],
    ],
    
    'visa_type_duration_limits' => [
        'tourist' => ['min' => 1, 'max' => 90],
        'business' => ['min' => 1, 'max' => 90],
        'transit' => ['min' => 1, 'max' => 7],
        'student' => ['min' => 90, 'max' => 365],
        'work' => ['min' => 90, 'max' => 365],
    ],
    
    'required_documents_by_visa_type' => [
        'tourist' => ['passport', 'photo', 'return_ticket', 'accommodation'],
        'business' => ['passport', 'photo', 'invitation_letter', 'company_registration'],
        'transit' => ['passport', 'photo', 'onward_ticket'],
        'student' => ['passport', 'photo', 'admission_letter', 'financial_proof'],
        'work' => ['passport', 'photo', 'work_permit', 'employment_contract'],
    ],
    
    'recommendations' => [
        'low' => [
            'priority' => 'low',
            'action' => 'STANDARD_REVIEW',
            'guidance' => 'Standard review process. No significant risk factors identified.',
        ],
        'medium' => [
            'priority' => 'medium',
            'action' => 'REVIEW_SUPPORTING_DOCUMENTS',
            'guidance' => 'Review supporting documents carefully. Some risk factors present.',
        ],
        'high' => [
            'priority' => 'high',
            'action' => 'MANUAL_VERIFICATION_REQUIRED',
            'guidance' => 'Manual verification required. Multiple risk factors identified.',
        ],
        'critical' => [
            'priority' => 'critical',
            'action' => 'ESCALATE_SUPERVISOR_REVIEW',
            'guidance' => 'Escalate to supervisor. Severe risk factors present.',
        ],
    ],
];
