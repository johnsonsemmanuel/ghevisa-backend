<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $visaTypes = [
            'family' => [
                'name' => 'Family Visit Visa',
                'description' => 'Regular visa for visiting immediate family members resident in Ghana.',
                'base_fee' => 260.00,
                'max_duration_days' => 90,
                'required_documents' => [
                    'passport_bio',
                    'passport_photo',
                    'proof_of_relationship',
                    'host_id',
                    'invitation_letter',
                    'return_ticket',
                ],
                'default_route_to' => 'mfa',
                'default_processing_days' => 7,
            ],
            'international_org' => [
                'name' => 'International Organization Visa',
                'description' => 'Regular visa for accredited staff of international organizations operating in Ghana.',
                'base_fee' => 0.00,
                'max_duration_days' => 365,
                'required_documents' => [
                    'passport_bio',
                    'passport_photo',
                    'organization_id',
                    'mission_letter',
                ],
                'default_route_to' => 'mfa',
                'default_processing_days' => 10,
            ],
        ];

        foreach ($visaTypes as $slug => $data) {
            DB::table('visa_types')->updateOrInsert(
                ['slug' => $slug],
                $this->buildPayload($slug, $data, $now)
            );
        }
    }

    public function down(): void
    {
        DB::table('visa_types')->whereIn('slug', ['family', 'international_org'])->delete();
    }

    private function buildPayload(string $slug, array $data, $timestamp): array
    {
        $payload = [
            'name' => $data['name'],
            'slug' => $slug,
            'description' => $data['description'],
            'base_fee' => $data['base_fee'],
            'max_duration_days' => $data['max_duration_days'],
            'is_active' => true,
            'type' => 'visa',
            'default_route_to' => $data['default_route_to'],
            'default_processing_days' => $data['default_processing_days'],
            'required_documents' => json_encode($data['required_documents']),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        if (Schema::hasColumn('visa_types', 'category')) {
            $payload['category'] = 'regular';
        }

        if (Schema::hasColumn('visa_types', 'authorization_type')) {
            $payload['authorization_type'] = 'embassy_visa';
        }

        if (Schema::hasColumn('visa_types', 'validity_period')) {
            $payload['validity_period'] = $slug === 'family' ? '90 days' : '1 year (renewable)';
        }

        if (Schema::hasColumn('visa_types', 'entry_type')) {
            $payload['entry_type'] = $slug === 'family' ? 'single' : 'multiple';
        }

        return $payload;
    }
};
