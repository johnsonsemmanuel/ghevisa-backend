<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration adds all missing fields that are submitted from the frontend
     * but were not present in the backend database schema.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // Applicant Details - Additional Fields
            $table->string('other_names')->nullable()->after('last_name_encrypted');
            $table->string('place_of_birth')->nullable()->after('country_of_birth');
            $table->string('passport_issue_place')->nullable()->after('passport_issue_date');
            
            // Contact Information
            $table->string('phone_country', 3)->default('GH')->after('phone_encrypted');
            
            // Travel Details - Additional Fields
            $table->string('visa_duration')->nullable()->after('duration_days');
            $table->string('place_of_embarkation')->nullable()->after('intended_arrival');
            $table->string('destination_city')->nullable()->after('port_of_entry');
            $table->date('return_date')->nullable()->after('intended_arrival');
            $table->text('purpose_details')->nullable()->after('purpose_of_visit');
            
            // Accommodation Details
            $table->enum('accommodation_type', ['hotel', 'family'])->nullable()->after('address_in_ghana');
            $table->text('accommodation_address')->nullable()->after('accommodation_type');
            $table->string('hotel_name')->nullable()->after('accommodation_address');
            $table->text('host_address')->nullable()->after('host_phone');
            $table->string('host_relationship')->nullable()->after('host_address');
            
            // Travel History
            $table->string('visited_ghana_before')->nullable()->after('purpose_details');
            $table->string('previous_visa_number')->nullable()->after('visited_ghana_before');
            $table->string('visited_other_countries')->nullable()->after('previous_visa_number');
            
            // Security & Travel Declaration
            $table->string('high_risk_travel')->nullable()->after('visited_country_3');
            $table->string('overstayed_before')->nullable()->after('entry_denied_before');
            $table->string('international_sanctions')->nullable()->after('overstayed_before');
            
            // Employment Information
            $table->string('occupation')->nullable()->after('profession_encrypted');
            $table->string('employer_name')->nullable()->after('occupation');
            $table->text('employer_address')->nullable()->after('employer_name');
            $table->string('employer_phone')->nullable()->after('employer_address');
            
            // Business Visa Fields
            $table->string('company_name')->nullable()->after('employer_phone');
            $table->text('company_address')->nullable()->after('company_name');
            $table->string('job_title')->nullable()->after('company_address');
            $table->text('business_purpose')->nullable()->after('job_title');
            $table->text('business_details')->nullable()->after('business_purpose');
            
            // Host Company Information (for business visas)
            $table->string('host_company_name')->nullable()->after('host_relationship');
            $table->text('host_company_address')->nullable()->after('host_company_name');
            $table->string('host_contact_name')->nullable()->after('host_company_address');
            $table->string('host_contact_phone')->nullable()->after('host_contact_name');
            
            // Residential Address
            $table->text('current_address')->nullable()->after('phone_country');
            $table->string('city')->nullable()->after('current_address');
            $table->string('state_province')->nullable()->after('city');
            $table->string('postal_code')->nullable()->after('state_province');
            $table->string('country_of_residence')->nullable()->after('postal_code');
            
            // Additional Health Information
            $table->text('health_issues')->nullable()->after('health_infectious_countries');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn([
                // Applicant Details
                'other_names',
                'place_of_birth',
                'passport_issue_place',
                
                // Contact
                'phone_country',
                
                // Travel Details
                'visa_duration',
                'place_of_embarkation',
                'destination_city',
                'return_date',
                'purpose_details',
                
                // Accommodation
                'accommodation_type',
                'accommodation_address',
                'hotel_name',
                'host_address',
                'host_relationship',
                
                // Travel History
                'visited_ghana_before',
                'previous_visa_number',
                'visited_other_countries',
                
                // Security
                'high_risk_travel',
                'overstayed_before',
                'international_sanctions',
                
                // Employment
                'occupation',
                'employer_name',
                'employer_address',
                'employer_phone',
                
                // Business
                'company_name',
                'company_address',
                'job_title',
                'business_purpose',
                'business_details',
                
                // Host Company
                'host_company_name',
                'host_company_address',
                'host_contact_name',
                'host_contact_phone',
                
                // Residential
                'current_address',
                'city',
                'state_province',
                'postal_code',
                'country_of_residence',
                
                // Health
                'health_issues',
            ]);
        });
    }
};
