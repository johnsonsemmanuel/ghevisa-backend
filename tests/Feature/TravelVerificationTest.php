<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\EtaApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class TravelVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed basic data if needed
        // $this->artisan('db:seed', ['--class' => 'DatabaseSeeder']);
    }

    /** @test */
    public function it_verifies_valid_eta_with_passport_binding()
    {
        $eta = EtaApplication::factory()->create([
            'eta_number' => 'GH-ETA-20260310-TEST1',
            'passport_number_encrypted' => Crypt::encryptString('P1234567'),
            'nationality_encrypted' => Crypt::encryptString('US'),
            'first_name_encrypted' => Crypt::encryptString('John'),
            'last_name_encrypted' => Crypt::encryptString('Doe'),
            'status' => 'approved',
            'expires_at' => now()->addDays(90),
            'entry_consumed' => false,
        ]);

        $response = $this->getJson('/api/verify-travel?' . http_build_query([
            'passport_number' => 'P1234567',
            'nationality' => 'US',
            'eta_number' => 'GH-ETA-20260310-TEST1',
        ]));

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'AUTHORIZED',
                'authorization_type' => 'ETA',
                'traveler_name' => 'John Doe',
                'eta_number' => 'GH-ETA-20260310-TEST1',
            ]);
    }

    /** @test */
    public function it_rejects_eta_with_wrong_passport_number()
    {
        $eta = EtaApplication::factory()->create([
            'eta_number' => 'GH-ETA-20260310-TEST2',
            'passport_number_encrypted' => Crypt::encryptString('P1234567'),
            'nationality_encrypted' => Crypt::encryptString('US'),
            'status' => 'approved',
            'expires_at' => now()->addDays(90),
        ]);

        $response = $this->getJson('/api/verify-travel?' . http_build_query([
            'passport_number' => 'P9999999', // Wrong passport
            'nationality' => 'US',
            'eta_number' => 'GH-ETA-20260310-TEST2',
        ]));

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'ETA_REQUIRED',
            ]);
    }

    /** @test */
    public function it_rejects_expired_eta()
    {
        $eta = EtaApplication::factory()->create([
            'eta_number' => 'GH-ETA-20260310-TEST3',
            'passport_number_encrypted' => Crypt::encryptString('P1234567'),
            'nationality_encrypted' => Crypt::encryptString('US'),
            'status' => 'approved',
            'expires_at' => now()->subDays(1), // Expired
        ]);

        $response = $this->getJson('/api/verify-travel?' . http_build_query([
            'passport_number' => 'P1234567',
            'nationality' => 'US',
            'eta_number' => 'GH-ETA-20260310-TEST3',
        ]));

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'EXPIRED',
                'reason' => 'AUTHORIZATION_EXPIRED',
            ]);
    }

    /** @test */
    public function it_rejects_already_used_eta()
    {
        $eta = EtaApplication::factory()->create([
            'eta_number' => 'GH-ETA-20260310-TEST4',
            'passport_number_encrypted' => Crypt::encryptString('P1234567'),
            'nationality_encrypted' => Crypt::encryptString('US'),
            'status' => 'approved',
            'expires_at' => now()->addDays(90),
            'entry_consumed' => true, // Already used
        ]);

        $response = $this->getJson('/api/verify-travel?' . http_build_query([
            'passport_number' => 'P1234567',
            'nationality' => 'US',
            'eta_number' => 'GH-ETA-20260310-TEST4',
        ]));

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'DENIED',
                'reason' => 'ETA_ALREADY_USED',
            ]);
    }

    /** @test */
    public function it_verifies_valid_visa_with_passport_binding()
    {
        $application = Application::factory()->create([
            'reference_number' => 'VIS-2026-000123',
            'passport_number_encrypted' => 'P1234567',
            'nationality_encrypted' => 'NG',
            'first_name_encrypted' => 'Jane',
            'last_name_encrypted' => 'Smith',
            'status' => 'approved',
            'decided_at' => now(),
        ]);

        $response = $this->getJson('/api/verify-travel?' . http_build_query([
            'passport_number' => 'P1234567',
            'nationality' => 'NG',
            'visa_id' => 'VIS-2026-000123',
        ]));

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'AUTHORIZED',
                'authorization_type' => 'VISA',
                'traveler_name' => 'Jane Smith',
                'visa_reference' => 'VIS-2026-000123',
            ]);
    }

    /** @test */
    public function it_generates_boarding_authorization_code_for_airlines()
    {
        $airline = User::factory()->create(['role' => 'airline_staff']);

        $eta = EtaApplication::factory()->create([
            'eta_number' => 'GH-ETA-20260310-TEST5',
            'passport_number_encrypted' => Crypt::encryptString('P1234567'),
            'nationality_encrypted' => Crypt::encryptString('US'),
            'first_name_encrypted' => Crypt::encryptString('John'),
            'last_name_encrypted' => Crypt::encryptString('Doe'),
            'status' => 'approved',
            'expires_at' => now()->addDays(90),
        ]);

        $response = $this->actingAs($airline)->getJson('/api/verify-travel?' . http_build_query([
            'passport_number' => 'P1234567',
            'nationality' => 'US',
            'eta_number' => 'GH-ETA-20260310-TEST5',
        ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'authorization_type',
                'boarding_authorization_code',
                'bac_expires_at',
            ]);

        $this->assertStringStartsWith('GH-BA-', $response->json('boarding_authorization_code'));
    }

    /** @test */
    public function border_officer_can_confirm_entry()
    {
        $officer = User::factory()->create(['role' => 'border_officer']);

        $eta = EtaApplication::factory()->create([
            'eta_number' => 'GH-ETA-20260310-TEST6',
            'passport_number_encrypted' => Crypt::encryptString('P1234567'),
            'nationality_encrypted' => Crypt::encryptString('US'),
            'status' => 'approved',
            'expires_at' => now()->addDays(90),
            'entry_consumed' => false,
        ]);

        $response = $this->actingAs($officer)->postJson('/api/verify-travel/confirm-entry', [
            'passport_number' => 'P1234567',
            'eta_number' => 'GH-ETA-20260310-TEST6',
            'port_of_entry' => 'Kotoka International Airport',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Entry confirmed successfully',
                'port_of_entry' => 'Kotoka International Airport',
            ]);

        $eta->refresh();
        $this->assertTrue($eta->entry_consumed);
        $this->assertEquals('Kotoka International Airport', $eta->port_of_entry_used);
        $this->assertEquals($officer->id, $eta->entry_officer_id);
    }

    /** @test */
    public function non_border_officer_cannot_confirm_entry()
    {
        $user = User::factory()->create(['role' => 'applicant']);

        $response = $this->actingAs($user)->postJson('/api/verify-travel/confirm-entry', [
            'passport_number' => 'P1234567',
            'eta_number' => 'GH-ETA-20260310-TEST7',
            'port_of_entry' => 'Kotoka International Airport',
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function it_verifies_by_passport_and_nationality_only()
    {
        $eta = EtaApplication::factory()->create([
            'passport_number_encrypted' => Crypt::encryptString('P1234567'),
            'nationality_encrypted' => Crypt::encryptString('US'),
            'first_name_encrypted' => Crypt::encryptString('John'),
            'last_name_encrypted' => Crypt::encryptString('Doe'),
            'status' => 'approved',
            'expires_at' => now()->addDays(90),
        ]);

        $response = $this->getJson('/api/verify-travel?' . http_build_query([
            'passport_number' => 'P1234567',
            'nationality' => 'US',
        ]));

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'AUTHORIZED',
                'authorization_type' => 'ETA',
                'traveler_name' => 'John Doe',
            ]);
    }

    /** @test */
    public function it_returns_eta_required_for_unauthorized_traveler()
    {
        $response = $this->getJson('/api/verify-travel?' . http_build_query([
            'passport_number' => 'P9999999',
            'nationality' => 'US',
        ]));

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'ETA_REQUIRED',
                'reason' => 'NO_AUTHORIZATION_FOUND',
            ]);
    }

    /** @test */
    public function it_logs_all_verification_attempts()
    {
        $this->getJson('/api/verify-travel?' . http_build_query([
            'passport_number' => 'P1234567',
            'nationality' => 'US',
        ]));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'travel_verification_attempt',
        ]);
    }
}
