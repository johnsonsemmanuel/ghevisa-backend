<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration adds ETA (Electronic Travel Authorization) support:
     * - New visa categories: eta, evisa, voa, visa_free, embassy_visa
     * - Country eligibility matrix for different authorization types
     * - ETA-specific fields for applications
     */
    public function up(): void
    {
        // Add new columns to visa_types table
        Schema::table('visa_types', function (Blueprint $table) {
            if (!Schema::hasColumn('visa_types', 'authorization_type')) {
                $table->string('authorization_type')->default('evisa')->after('category');
                // Types: visa_free, eta, voa, evoa, evisa, embassy_visa, transit, conditional
            }
            if (!Schema::hasColumn('visa_types', 'processing_time_hours')) {
                $table->integer('processing_time_hours')->nullable()->after('default_processing_days');
            }
            if (!Schema::hasColumn('visa_types', 'auto_approve_eligible')) {
                $table->boolean('auto_approve_eligible')->default(false)->after('processing_time_hours');
            }
        });

        // Create country eligibility matrix table
        Schema::create('country_visa_eligibility', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 3); // ISO 3166-1 alpha-2 or alpha-3
            $table->string('country_name');
            $table->string('region'); // West Africa, East Africa, Europe, etc.
            $table->string('bloc')->nullable(); // ECOWAS, AU, Caribbean, EU, etc.
            $table->string('authorization_type'); // visa_free, eta, voa, evisa, embassy_visa
            $table->string('current_policy')->nullable(); // Current policy description
            $table->integer('max_stay_days')->default(90);
            $table->decimal('eta_fee', 10, 2)->nullable();
            $table->decimal('evisa_fee', 10, 2)->nullable();
            $table->boolean('yellow_fever_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->json('special_conditions')->nullable();
            $table->timestamps();
            
            $table->unique('country_code');
            $table->index('authorization_type');
            $table->index('bloc');
            $table->index('region');
        });

        // Add ETA-specific fields to applications table
        Schema::table('applications', function (Blueprint $table) {
            if (!Schema::hasColumn('applications', 'authorization_type')) {
                $table->string('authorization_type')->nullable()->after('visa_channel');
            }
            if (!Schema::hasColumn('applications', 'eta_number')) {
                $table->string('eta_number')->nullable()->after('reference_number');
            }
            if (!Schema::hasColumn('applications', 'airline')) {
                $table->string('airline')->nullable()->after('port_of_entry');
            }
            if (!Schema::hasColumn('applications', 'flight_number')) {
                $table->string('flight_number')->nullable()->after('airline');
            }
            if (!Schema::hasColumn('applications', 'host_name')) {
                $table->string('host_name')->nullable()->after('address_in_ghana');
            }
            if (!Schema::hasColumn('applications', 'host_phone')) {
                $table->string('host_phone')->nullable()->after('host_name');
            }
            if (!Schema::hasColumn('applications', 'hotel_booking_reference')) {
                $table->string('hotel_booking_reference')->nullable()->after('host_phone');
            }
            if (!Schema::hasColumn('applications', 'previous_ghana_visa')) {
                $table->boolean('previous_ghana_visa')->nullable()->after('visited_country_3');
            }
            if (!Schema::hasColumn('applications', 'entry_denied_before')) {
                $table->boolean('entry_denied_before')->nullable()->after('previous_ghana_visa');
            }
            if (!Schema::hasColumn('applications', 'criminal_conviction')) {
                $table->boolean('criminal_conviction')->nullable()->after('entry_denied_before');
            }
            if (!Schema::hasColumn('applications', 'travel_history')) {
                $table->text('travel_history')->nullable()->after('criminal_conviction');
            }
            if (!Schema::hasColumn('applications', 'eta_validity_days')) {
                $table->integer('eta_validity_days')->nullable()->after('duration_days');
            }
            if (!Schema::hasColumn('applications', 'entry_type_granted')) {
                $table->string('entry_type_granted')->nullable()->after('entry_type');
            }
        });

        // Insert ETA visa type
        DB::table('visa_types')->insert([
            [
                'name' => 'Electronic Travel Authorization (ETA)',
                'slug' => 'eta',
                'description' => 'Pre-travel authorization for visa-free and visa-on-arrival eligible countries. Quick digital registration for ECOWAS, AU, and Caribbean nationals.',
                'base_fee' => 20.00,
                'max_duration_days' => 90,
                'is_active' => true,
                'required_documents' => json_encode(['passport_bio', 'passport_photo']),
                'government_fee' => 0.00,
                'platform_fee' => 5.00,
                'entry_type' => 'single',
                'validity_period' => 90,
                'category' => 'authorization',
                'default_processing_days' => 1,
                'default_route_to' => 'gis',
                'authorization_type' => 'eta',
                'processing_time_hours' => 24,
                'auto_approve_eligible' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Visa on Arrival (Pre-Registration)',
                'slug' => 'voa',
                'description' => 'Pre-registration for visa on arrival. Complete your application online and collect your visa at the port of entry.',
                'base_fee' => 60.00,
                'max_duration_days' => 30,
                'is_active' => true,
                'required_documents' => json_encode(['passport_bio', 'passport_photo', 'return_ticket']),
                'government_fee' => 0.00,
                'platform_fee' => 10.00,
                'entry_type' => 'single',
                'validity_period' => 30,
                'category' => 'authorization',
                'default_processing_days' => 1,
                'default_route_to' => 'gis',
                'authorization_type' => 'voa',
                'processing_time_hours' => 48,
                'auto_approve_eligible' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Update existing visa types with authorization_type
        DB::table('visa_types')->where('slug', 'tourism')->update(['authorization_type' => 'evisa']);
        DB::table('visa_types')->where('slug', 'business')->update(['authorization_type' => 'evisa']);
        DB::table('visa_types')->where('slug', 'student')->update(['authorization_type' => 'evisa']);
        DB::table('visa_types')->where('slug', 'work')->update(['authorization_type' => 'evisa']);
        DB::table('visa_types')->where('slug', 'medical')->update(['authorization_type' => 'evisa']);
        DB::table('visa_types')->where('slug', 'transit')->update(['authorization_type' => 'evisa']);
        DB::table('visa_types')->where('slug', 'diplomatic')->update(['authorization_type' => 'embassy_visa']);

        // Insert country eligibility matrix
        $this->seedCountryEligibility();
    }

    /**
     * Seed the country eligibility matrix
     */
    private function seedCountryEligibility(): void
    {
        $countries = [
            // ECOWAS Countries (Visa-Free → ETA Required)
            ['code' => 'NG', 'name' => 'Nigeria', 'region' => 'West Africa', 'bloc' => 'ECOWAS', 'auth' => 'eta', 'policy' => 'Visa-Free (90 days)', 'days' => 90, 'eta_fee' => 10],
            ['code' => 'BJ', 'name' => 'Benin', 'region' => 'West Africa', 'bloc' => 'ECOWAS', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 10],
            ['code' => 'TG', 'name' => 'Togo', 'region' => 'West Africa', 'bloc' => 'ECOWAS', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 10],
            ['code' => 'BF', 'name' => 'Burkina Faso', 'region' => 'West Africa', 'bloc' => 'ECOWAS', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 10],
            ['code' => 'ML', 'name' => 'Mali', 'region' => 'West Africa', 'bloc' => 'ECOWAS', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 10],
            ['code' => 'NE', 'name' => 'Niger', 'region' => 'West Africa', 'bloc' => 'ECOWAS', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 10],
            ['code' => 'SN', 'name' => 'Senegal', 'region' => 'West Africa', 'bloc' => 'ECOWAS', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 10],
            ['code' => 'GN', 'name' => 'Guinea', 'region' => 'West Africa', 'bloc' => 'ECOWAS', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 10],
            ['code' => 'GW', 'name' => 'Guinea-Bissau', 'region' => 'West Africa', 'bloc' => 'ECOWAS', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 10],
            ['code' => 'LR', 'name' => 'Liberia', 'region' => 'West Africa', 'bloc' => 'ECOWAS', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 10],
            ['code' => 'SL', 'name' => 'Sierra Leone', 'region' => 'West Africa', 'bloc' => 'ECOWAS', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 10],
            ['code' => 'CV', 'name' => 'Cape Verde', 'region' => 'West Africa', 'bloc' => 'ECOWAS', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 10],
            ['code' => 'GM', 'name' => 'Gambia', 'region' => 'West Africa', 'bloc' => 'ECOWAS', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 10],
            ['code' => 'CI', 'name' => 'Côte d\'Ivoire', 'region' => 'West Africa', 'bloc' => 'ECOWAS', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 10],

            // African Union Countries (Visa-on-Arrival → ETA)
            ['code' => 'KE', 'name' => 'Kenya', 'region' => 'East Africa', 'bloc' => 'AU', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 20],
            ['code' => 'TZ', 'name' => 'Tanzania', 'region' => 'East Africa', 'bloc' => 'AU', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 20],
            ['code' => 'RW', 'name' => 'Rwanda', 'region' => 'East Africa', 'bloc' => 'AU', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 20],
            ['code' => 'UG', 'name' => 'Uganda', 'region' => 'East Africa', 'bloc' => 'AU', 'auth' => 'eta', 'policy' => 'Visa-on-Arrival', 'days' => 90, 'eta_fee' => 20],
            ['code' => 'ET', 'name' => 'Ethiopia', 'region' => 'East Africa', 'bloc' => 'AU', 'auth' => 'eta', 'policy' => 'Visa-on-Arrival', 'days' => 90, 'eta_fee' => 20],
            ['code' => 'ZA', 'name' => 'South Africa', 'region' => 'Southern Africa', 'bloc' => 'AU', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 20],
            ['code' => 'NA', 'name' => 'Namibia', 'region' => 'Southern Africa', 'bloc' => 'AU', 'auth' => 'eta', 'policy' => 'eVisa', 'days' => 90, 'eta_fee' => 20],
            ['code' => 'BW', 'name' => 'Botswana', 'region' => 'Southern Africa', 'bloc' => 'AU', 'auth' => 'eta', 'policy' => 'Visa-on-Arrival', 'days' => 90, 'eta_fee' => 20],
            ['code' => 'ZW', 'name' => 'Zimbabwe', 'region' => 'Southern Africa', 'bloc' => 'AU', 'auth' => 'eta', 'policy' => 'Visa-on-Arrival', 'days' => 90, 'eta_fee' => 20],
            ['code' => 'ZM', 'name' => 'Zambia', 'region' => 'Southern Africa', 'bloc' => 'AU', 'auth' => 'eta', 'policy' => 'Visa-on-Arrival', 'days' => 90, 'eta_fee' => 20],
            ['code' => 'MA', 'name' => 'Morocco', 'region' => 'North Africa', 'bloc' => 'AU', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 20],
            ['code' => 'TN', 'name' => 'Tunisia', 'region' => 'North Africa', 'bloc' => 'AU', 'auth' => 'eta', 'policy' => 'Visa-on-Arrival', 'days' => 90, 'eta_fee' => 20],
            ['code' => 'EG', 'name' => 'Egypt', 'region' => 'North Africa', 'bloc' => 'AU', 'auth' => 'eta', 'policy' => 'Visa-on-Arrival', 'days' => 90, 'eta_fee' => 20],
            ['code' => 'GA', 'name' => 'Gabon', 'region' => 'Central Africa', 'bloc' => 'AU', 'auth' => 'eta', 'policy' => 'eVisa', 'days' => 90, 'eta_fee' => 20],
            ['code' => 'CM', 'name' => 'Cameroon', 'region' => 'Central Africa', 'bloc' => 'AU', 'auth' => 'eta', 'policy' => 'Visa-on-Arrival', 'days' => 90, 'eta_fee' => 20],
            ['code' => 'CG', 'name' => 'Republic of Congo', 'region' => 'Central Africa', 'bloc' => 'AU', 'auth' => 'eta', 'policy' => 'Visa-on-Arrival', 'days' => 90, 'eta_fee' => 20],
            ['code' => 'CD', 'name' => 'DR Congo', 'region' => 'Central Africa', 'bloc' => 'AU', 'auth' => 'eta', 'policy' => 'Visa-on-Arrival', 'days' => 90, 'eta_fee' => 20],

            // Caribbean / Friendly Countries (Visa Waiver → ETA)
            ['code' => 'BB', 'name' => 'Barbados', 'region' => 'Caribbean', 'bloc' => 'CARICOM', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 15],
            ['code' => 'BS', 'name' => 'Bahamas', 'region' => 'Caribbean', 'bloc' => 'CARICOM', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 15],
            ['code' => 'GD', 'name' => 'Grenada', 'region' => 'Caribbean', 'bloc' => 'CARICOM', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 15],
            ['code' => 'JM', 'name' => 'Jamaica', 'region' => 'Caribbean', 'bloc' => 'CARICOM', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 15],
            ['code' => 'TT', 'name' => 'Trinidad and Tobago', 'region' => 'Caribbean', 'bloc' => 'CARICOM', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 15],
            ['code' => 'AG', 'name' => 'Antigua and Barbuda', 'region' => 'Caribbean', 'bloc' => 'CARICOM', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 15],
            ['code' => 'DM', 'name' => 'Dominica', 'region' => 'Caribbean', 'bloc' => 'CARICOM', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 15],
            ['code' => 'KN', 'name' => 'Saint Kitts and Nevis', 'region' => 'Caribbean', 'bloc' => 'CARICOM', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 15],
            ['code' => 'LC', 'name' => 'Saint Lucia', 'region' => 'Caribbean', 'bloc' => 'CARICOM', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 15],
            ['code' => 'VC', 'name' => 'Saint Vincent and the Grenadines', 'region' => 'Caribbean', 'bloc' => 'CARICOM', 'auth' => 'eta', 'policy' => 'Visa-Free', 'days' => 90, 'eta_fee' => 15],

            // Countries requiring eVisa
            ['code' => 'GB', 'name' => 'United Kingdom', 'region' => 'Europe', 'bloc' => null, 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
            ['code' => 'DE', 'name' => 'Germany', 'region' => 'Europe', 'bloc' => 'EU', 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
            ['code' => 'FR', 'name' => 'France', 'region' => 'Europe', 'bloc' => 'EU', 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
            ['code' => 'IT', 'name' => 'Italy', 'region' => 'Europe', 'bloc' => 'EU', 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
            ['code' => 'ES', 'name' => 'Spain', 'region' => 'Europe', 'bloc' => 'EU', 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
            ['code' => 'NL', 'name' => 'Netherlands', 'region' => 'Europe', 'bloc' => 'EU', 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
            ['code' => 'BE', 'name' => 'Belgium', 'region' => 'Europe', 'bloc' => 'EU', 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
            ['code' => 'US', 'name' => 'United States', 'region' => 'North America', 'bloc' => null, 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
            ['code' => 'CA', 'name' => 'Canada', 'region' => 'North America', 'bloc' => null, 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
            ['code' => 'CN', 'name' => 'China', 'region' => 'Asia', 'bloc' => null, 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
            ['code' => 'IN', 'name' => 'India', 'region' => 'Asia', 'bloc' => null, 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
            ['code' => 'JP', 'name' => 'Japan', 'region' => 'Asia', 'bloc' => null, 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
            ['code' => 'KR', 'name' => 'South Korea', 'region' => 'Asia', 'bloc' => null, 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
            ['code' => 'AU', 'name' => 'Australia', 'region' => 'Oceania', 'bloc' => null, 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
            ['code' => 'NZ', 'name' => 'New Zealand', 'region' => 'Oceania', 'bloc' => null, 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
            ['code' => 'BR', 'name' => 'Brazil', 'region' => 'South America', 'bloc' => null, 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
            ['code' => 'MX', 'name' => 'Mexico', 'region' => 'North America', 'bloc' => null, 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
            ['code' => 'AE', 'name' => 'United Arab Emirates', 'region' => 'Middle East', 'bloc' => null, 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
            ['code' => 'SA', 'name' => 'Saudi Arabia', 'region' => 'Middle East', 'bloc' => null, 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
            ['code' => 'RU', 'name' => 'Russia', 'region' => 'Europe', 'bloc' => null, 'auth' => 'evisa', 'policy' => 'Visa Required', 'days' => 90, 'evisa_fee' => 60],
        ];

        foreach ($countries as $country) {
            DB::table('country_visa_eligibility')->insert([
                'country_code' => $country['code'],
                'country_name' => $country['name'],
                'region' => $country['region'],
                'bloc' => $country['bloc'],
                'authorization_type' => $country['auth'],
                'current_policy' => $country['policy'],
                'max_stay_days' => $country['days'],
                'eta_fee' => $country['eta_fee'] ?? null,
                'evisa_fee' => $country['evisa_fee'] ?? null,
                'yellow_fever_required' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove ETA visa types
        DB::table('visa_types')->whereIn('slug', ['eta', 'voa'])->delete();

        // Drop country eligibility table
        Schema::dropIfExists('country_visa_eligibility');

        // Remove added columns from visa_types
        Schema::table('visa_types', function (Blueprint $table) {
            $table->dropColumn(['authorization_type', 'processing_time_hours', 'auto_approve_eligible']);
        });

        // Remove added columns from applications
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn([
                'authorization_type', 'eta_number', 'airline', 'flight_number',
                'host_name', 'host_phone', 'hotel_booking_reference',
                'previous_ghana_visa', 'entry_denied_before', 'criminal_conviction',
                'travel_history', 'eta_validity_days', 'entry_type_granted'
            ]);
        });
    }
};
