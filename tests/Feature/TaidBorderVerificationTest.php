<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\EtaApplication;
use App\Models\User;
use App\Models\VisaType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class TaidBorderVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $borderOfficer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->borderOfficer = User::factory()->create([
            'role' => 'border_officer',
        ]);
    }

    public function test_verify_approved_eta_by_taid()
    {
        $eta = EtaApplication::factory()->approved()->create([
            'status' => 'approved',
            'expires_at' => now()->addDays(30),
            'entry_consumed' => false,
        ]);

        $passportNumber = Crypt::decryptString($eta->passport_number_encrypted);

        $response = $this->actingAs($this->borderOfficer, 'sanctum')
            ->postJson('/api/border/verify-taid', [
                'taid' => $eta->taid,
                'passport_number' => $passportNumber,
            ]);

        $response->assertOk()
            ->assertJson([
                'valid' => true,
                'status' => 'valid',
                'message' => 'ETA verified successfully',
                'authorization' => [
                    'type' => 'eta',
                    'taid' => $eta->taid,
                    'eta_number' => $eta->eta_number,
                    'entry_type' => $eta->entry_type,
                    'entry_consumed' => false,
                ],
            ]);
    }

    public function test_verify_approved_visa_by_taid()
    {
        $visaType = VisaType::factory()->create([
            'max_duration_days' => 90,
        ]);

        $application = Application::factory()->approved()->create([
            'visa_type_id' => $visaType->id,
            'status' => 'approved',
            'decided_at' => now()->subDays(5),
        ]);

        $response = $this->actingAs($this->borderOfficer, 'sanctum')
            ->postJson('/api/border/verify-taid', [
                'taid' => $application->taid,
                'passport_number' => $application->passport_number,
            ]);

        $response->assertOk()
            ->assertJson([
                'valid' => true,
                'status' => 'valid',
                'message' => 'Visa verified successfully',
                'authorization' => [
                    'type' => 'visa',
                    'taid' => $application->taid,
                    'reference_number' => $application->reference_number,
                ],
            ]);
    }

    public function test_verify_rejects_invalid_taid_format()
    {
        $response = $this->actingAs($this->borderOfficer, 'sanctum')
            ->postJson('/api/border/verify-taid', [
                'taid' => 'INVALID-FORMAT',
                'passport_number' => 'P1234567',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'valid' => false,
                'status' => 'invalid_format',
                'message' => 'Invalid TAID format',
            ]);
    }

    public function test_verify_rejects_nonexistent_taid()
    {
        $response = $this->actingAs($this->borderOfficer, 'sanctum')
            ->postJson('/api/border/verify-taid', [
                'taid' => 'TAID-20260311-NOTFOUND',
                'passport_number' => 'P1234567',
            ]);

        $response->assertStatus(404)
            ->assertJson([
                'valid' => false,
                'status' => 'not_found',
                'message' => 'Travel authorization not found',
            ]);
    }

    public function test_verify_rejects_mismatched_passport()
    {
        $eta = EtaApplication::factory()->approved()->create();

        $response = $this->actingAs($this->borderOfficer, 'sanctum')
            ->postJson('/api/border/verify-taid', [
                'taid' => $eta->taid,
                'passport_number' => 'WRONG-PASSPORT',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'valid' => false,
                'status' => 'invalid',
                'message' => 'Passport number does not match authorization record',
            ]);
    }

    public function test_verify_rejects_expired_eta()
    {
        $eta = EtaApplication::factory()->approved()->create([
            'status' => 'approved',
            'expires_at' => now()->subDays(1), // Expired yesterday
        ]);

        $passportNumber = Crypt::decryptString($eta->passport_number_encrypted);

        $response = $this->actingAs($this->borderOfficer, 'sanctum')
            ->postJson('/api/border/verify-taid', [
                'taid' => $eta->taid,
                'passport_number' => $passportNumber,
            ]);

        $response->assertOk()
            ->assertJson([
                'valid' => false,
                'status' => 'expired',
                'message' => 'ETA has expired',
            ]);
    }

    public function test_verify_rejects_consumed_single_entry_eta()
    {
        $eta = EtaApplication::factory()->approved()->create([
            'status' => 'approved',
            'entry_type' => 'single',
            'entry_consumed' => true,
            'entry_date' => now()->subDays(5),
            'expires_at' => now()->addDays(30),
        ]);

        $passportNumber = Crypt::decryptString($eta->passport_number_encrypted);

        $response = $this->actingAs($this->borderOfficer, 'sanctum')
            ->postJson('/api/border/verify-taid', [
                'taid' => $eta->taid,
                'passport_number' => $passportNumber,
            ]);

        $response->assertOk()
            ->assertJson([
                'valid' => false,
                'status' => 'entry_consumed',
                'message' => 'Single-entry ETA already used',
            ]);
    }

    public function test_verify_accepts_multiple_entry_eta_after_first_use()
    {
        $eta = EtaApplication::factory()->approved()->create([
            'status' => 'approved',
            'entry_type' => 'multiple',
            'entry_consumed' => true, // Already used once
            'entry_date' => now()->subDays(5),
            'expires_at' => now()->addDays(30),
        ]);

        $passportNumber = Crypt::decryptString($eta->passport_number_encrypted);

        $response = $this->actingAs($this->borderOfficer, 'sanctum')
            ->postJson('/api/border/verify-taid', [
                'taid' => $eta->taid,
                'passport_number' => $passportNumber,
            ]);

        $response->assertOk()
            ->assertJson([
                'valid' => true,
                'status' => 'valid',
                'authorization' => [
                    'entry_type' => 'multiple',
                ],
            ]);
    }

    public function test_verify_rejects_pending_eta()
    {
        $eta = EtaApplication::factory()->create([
            'status' => 'pending',
        ]);

        $passportNumber = Crypt::decryptString($eta->passport_number_encrypted);

        $response = $this->actingAs($this->borderOfficer, 'sanctum')
            ->postJson('/api/border/verify-taid', [
                'taid' => $eta->taid,
                'passport_number' => $passportNumber,
            ]);

        $response->assertOk()
            ->assertJson([
                'valid' => false,
                'status' => 'not_approved',
                'message' => 'ETA is not approved',
            ]);
    }

    public function test_verify_requires_authentication()
    {
        $eta = EtaApplication::factory()->approved()->create();

        $response = $this->postJson('/api/border/verify-taid', [
            'taid' => $eta->taid,
            'passport_number' => 'P1234567',
        ]);

        $response->assertUnauthorized();
    }

    public function test_verify_validates_required_fields()
    {
        $response = $this->actingAs($this->borderOfficer, 'sanctum')
            ->postJson('/api/border/verify-taid', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['taid', 'passport_number']);
    }

    public function test_verify_includes_risk_warnings_for_high_risk_visa()
    {
        $visaType = VisaType::factory()->create();

        $application = Application::factory()->approved()->create([
            'visa_type_id' => $visaType->id,
            'status' => 'approved',
            'decided_at' => now()->subDays(5),
            'risk_level' => 'high',
            'watchlist_flagged' => true,
        ]);

        $response = $this->actingAs($this->borderOfficer, 'sanctum')
            ->postJson('/api/border/verify-taid', [
                'taid' => $application->taid,
                'passport_number' => $application->passport_number,
            ]);

        $response->assertOk()
            ->assertJson([
                'valid' => true,
                'risk_warnings' => [
                    'WATCHLIST FLAG - Secondary inspection required',
                    'HIGH RISK - Manual verification recommended',
                ],
            ]);
    }
}
