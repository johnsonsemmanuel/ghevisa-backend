<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreEVisaApplicationRequest extends BaseApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled in controller
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            // Visa Setup
            'visa_type_id'   => ['required', 'integer', 'exists:visa_types,id'],
            'visa_channel'   => ['nullable', 'string', Rule::in(['e-visa', 'regular'])],
            'entry_type'     => ['nullable', 'string', Rule::in(['single', 'multiple'])],
            'service_tier_id'=> ['nullable', 'integer', 'exists:service_tiers,id'],
            
            // Applicant Details
            'first_name'     => ['required', 'string', 'min:2', 'max:255', 'regex:/^[a-zA-Z\s\-\'\.]+$/'],
            'last_name'      => ['required', 'string', 'min:2', 'max:255', 'regex:/^[a-zA-Z\s\-\'\.]+$/'],
            'other_names'    => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z\s\-\'\.]+$/'],
            'date_of_birth'  => ['required', 'date', 'before:today', 'after:-120 years'],
            'gender'         => ['required', 'string', Rule::in(['male', 'female'])],
            'marital_status' => ['required', 'string', Rule::in(['single', 'married', 'divorced', 'widowed', 'separated'])],
            'country_of_birth' => ['required', 'string', 'size:2'],
            'place_of_birth' => ['nullable', 'string', 'max:255'],
            'nationality'    => ['required', 'string', 'size:2'],
            'profession'     => ['required', 'string', 'max:255'],
            
            // Passport Information
            'passport_number'=> ['required', 'string', 'min:6', 'max:50', 'regex:/^[A-Z0-9\s\-]+$/'],
            'passport_issuing_authority' => ['nullable', 'string', 'max:255'],
            'passport_issue_date' => ['required', 'date', 'before_or_equal:today'],
            'passport_expiry'=> ['required', 'date', 'after:today'],
            'passport_issue_place' => ['nullable', 'string', 'max:255'],
            
            // Contact Information
            'email'          => ['required', 'email', 'max:255'],
            'phone'          => ['required', 'string', 'max:20', 'regex:/^\+?[0-9\s\-\(\)]+$/'],
            'phone_country'  => ['nullable', 'string', 'size:2'],
            
            // Travel Details
            'intended_arrival' => ['required', 'date', 'after:today', 'before:+1 year'],
            'duration_days'  => ['required', 'integer', 'min:1', 'max:365'],
            'visa_duration'  => ['nullable', 'string', 'max:50'],
            'port_of_entry'  => ['required', 'string', 'max:255'],
            'place_of_embarkation' => ['nullable', 'string', 'max:255'],
            'destination_city' => ['nullable', 'string', 'max:255'],
            'address_in_ghana' => ['required', 'string', 'max:500'],
            'purpose_of_visit' => ['required', 'string', 'max:255'],
            'purpose_details' => ['nullable', 'string', 'max:1000'],
            
            // Travel History
            'visited_ghana_before' => ['required', 'string', Rule::in(['yes', 'no'])],
            'previous_visa_number' => ['nullable', 'string', 'max:50'],
            'visited_other_countries' => ['required', 'string', Rule::in(['yes', 'no'])],
            'visited_country_1' => ['nullable', 'string', 'max:255'],
            'visited_country_2' => ['nullable', 'string', 'max:255'],
            'visited_country_3' => ['nullable', 'string', 'max:255'],
            
            // Accommodation
            'accommodation_type' => ['required', 'string', Rule::in(['hotel', 'family'])],
            'hotel_name'     => ['nullable', 'string', 'max:255'],
            'hotel_booking_reference' => ['nullable', 'string', 'max:255'],
            'accommodation_address' => ['nullable', 'string', 'max:500'],
            'host_name'      => ['nullable', 'string', 'max:255'],
            'host_phone'     => ['nullable', 'string', 'max:20'],
            'host_address'   => ['nullable', 'string', 'max:500'],
            'host_relationship' => ['nullable', 'string', 'max:255'],
            
            // Health Declaration
            'health_infectious_travel' => ['nullable', 'string', Rule::in(['yes', 'no'])],
            'health_infectious_countries' => ['nullable', 'string', 'max:500'],
            
            // Security & Travel Declaration
            'high_risk_travel' => ['nullable', 'string', Rule::in(['yes', 'no'])],
            'entry_denied_before' => ['nullable', 'string', Rule::in(['yes', 'no'])],
            'overstayed_before' => ['nullable', 'string', Rule::in(['yes', 'no'])],
            'international_sanctions' => ['nullable', 'string', Rule::in(['yes', 'no'])],
            'criminal_conviction' => ['nullable', 'string', Rule::in(['yes', 'no'])],
            
            // Additional fields
            'current_address' => ['nullable', 'string', 'max:500'],
            'city'           => ['nullable', 'string', 'max:255'],
            'state_province' => ['nullable', 'string', 'max:255'],
            'postal_code'    => ['nullable', 'string', 'max:20'],
            'country_of_residence' => ['nullable', 'string', 'size:2'],
            'airline'        => ['nullable', 'string', 'max:255'],
            'flight_number'  => ['nullable', 'string', 'max:50'],
            'return_date'    => ['nullable', 'date', 'after:intended_arrival'],
            
            // Employment (optional)
            'occupation'     => ['nullable', 'string', 'max:255'],
            'employer_name'  => ['nullable', 'string', 'max:255'],
            'employer_address' => ['nullable', 'string', 'max:500'],
            'employer_phone' => ['nullable', 'string', 'max:20'],
            
            // Business (optional)
            'company_name'   => ['nullable', 'string', 'max:255'],
            'company_address' => ['nullable', 'string', 'max:500'],
            'job_title'      => ['nullable', 'string', 'max:255'],
            'host_company_name' => ['nullable', 'string', 'max:255'],
            'host_company_address' => ['nullable', 'string', 'max:500'],
            'host_contact_name' => ['nullable', 'string', 'max:255'],
            'host_contact_phone' => ['nullable', 'string', 'max:20'],
            'business_purpose' => ['nullable', 'string', 'max:255'],
            'business_details' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom error messages for validation rules.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'first_name.regex' => 'First name can only contain letters, spaces, hyphens, dots, and apostrophes.',
            'last_name.regex' => 'Last name can only contain letters, spaces, hyphens, dots, and apostrophes.',
            'other_names.regex' => 'Other names can only contain letters, spaces, hyphens, dots, and apostrophes.',
            'email.email' => 'Please provide a valid email address.',
            'phone.regex' => 'Phone number can only contain numbers, spaces, plus sign, hyphens, and parentheses.',
            'passport_number.regex' => 'Passport number can only contain uppercase letters, numbers, spaces, and hyphens.',
            'passport_number.min' => 'Passport number must be at least 6 characters.',
            'date_of_birth.before' => 'Date of birth must be before today.',
            'date_of_birth.after' => 'Date of birth cannot be more than 120 years ago.',
            'passport_issue_date.before_or_equal' => 'Passport issue date cannot be in the future.',
            'passport_expiry.after' => 'Passport must not be expired.',
            'intended_arrival.after' => 'Intended arrival date must be after today.',
            'intended_arrival.before' => 'Intended arrival date cannot be more than 1 year from now.',
            'return_date.after' => 'Return date must be after intended arrival date.',
            'duration_days.min' => 'Duration must be at least 1 day.',
            'duration_days.max' => 'Duration cannot exceed 365 days.',
            'nationality.size' => 'Nationality must be a 2-letter country code.',
            'country_of_birth.size' => 'Country of birth must be a 2-letter country code.',
            'visa_type_id.exists' => 'The selected visa type is not available.',
            'service_tier_id.exists' => 'The selected service tier is not available.',
        ]);
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();

            // Validate accommodation based on type
            if (isset($data['accommodation_type'])) {
                if ($data['accommodation_type'] === 'hotel') {
                    if (empty($data['hotel_name'])) {
                        $validator->errors()->add('hotel_name', 'Hotel name is required for hotel accommodation.');
                    }
                    if (empty($data['accommodation_address'])) {
                        $validator->errors()->add('accommodation_address', 'Accommodation address is required for hotel accommodation.');
                    }
                } elseif ($data['accommodation_type'] === 'family') {
                    if (empty($data['host_name'])) {
                        $validator->errors()->add('host_name', 'Host name is required for family/friend accommodation.');
                    }
                    if (empty($data['host_phone'])) {
                        $validator->errors()->add('host_phone', 'Host phone is required for family/friend accommodation.');
                    }
                    if (empty($data['host_address'])) {
                        $validator->errors()->add('host_address', 'Host address is required for family/friend accommodation.');
                    }
                }
            }

            // Validate visited countries if visited_other_countries is yes
            if (isset($data['visited_other_countries']) && $data['visited_other_countries'] === 'yes') {
                if (empty($data['visited_country_1'])) {
                    $validator->errors()->add('visited_country_1', 'Please provide the first country you have visited.');
                }
                if (empty($data['visited_country_2'])) {
                    $validator->errors()->add('visited_country_2', 'Please provide the second country you have visited.');
                }
                if (empty($data['visited_country_3'])) {
                    $validator->errors()->add('visited_country_3', 'Please provide the third country you have visited.');
                }
            }

            // Validate health infectious countries if health_infectious_travel is yes
            if (isset($data['health_infectious_travel']) && $data['health_infectious_travel'] === 'yes') {
                if (empty($data['health_infectious_countries'])) {
                    $validator->errors()->add('health_infectious_countries', 'Please specify which countries with infectious diseases you have visited.');
                }
            }
        });
    }
}
