<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\InterpolCheck;

class AeropassIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set test credentials in config
        config(['aeropass.username' => 'evisaSystemUser']);
        config(['aeropass.password' => 'V9$kT2!qL7#rX4@z']);
    }

    protected function getAuthHeader(): array
    {
        $credentials = base64_encode('evisaSystemUser:V9$kT2!qL7#rX4@z');
        return ['Authorization' => 'Basic ' . $credentials];
    }

    /** @test */
    public function it_accepts_valid_interpol_callback()
    {
        $response = $this->postJson('/api/e-visa/interpol-nominal-verification/callback', [
            'uniqueReferenceId' => 'TEST-001',
            'firstName' => 'John',
            'surname' => 'Doe',
            'dateOfBirth' => '20/09/1990',
            'interpolNominalMatched' => 'No',
        ], $this->getAuthHeader());

        $response->assertStatus(200)
            ->assertJson([
                'uniqueReferenceId' => 'TEST-001',
                'responseCode' => '200',
                'errorMessage' => null,
            ]);

        // Verify database record
        $this->assertDatabaseHas('interpol_checks', [
            'unique_reference_id' => 'TEST-001',
            'first_name' => 'John',
            'surname' => 'Doe',
            'interpol_nominal_matched' => 'No',
        ]);
    }

    /** @test */
    public function it_rejects_interpol_callback_without_auth()
    {
        $response = $this->postJson('/api/e-visa/interpol-nominal-verification/callback', [
            'uniqueReferenceId' => 'TEST-002',
            'firstName' => 'John',
            'surname' => 'Doe',
            'dateOfBirth' => '20/09/1990',
            'interpolNominalMatched' => 'No',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'responseCode' => '401',
                'errorMessage' => 'Invalid Authorisation Header',
            ]);
    }

    /** @test */
    public function it_rejects_interpol_callback_with_invalid_auth()
    {
        $response = $this->postJson('/api/e-visa/interpol-nominal-verification/callback', [
            'uniqueReferenceId' => 'TEST-003',
            'firstName' => 'John',
            'surname' => 'Doe',
            'dateOfBirth' => '20/09/1990',
            'interpolNominalMatched' => 'No',
        ], ['Authorization' => 'Basic ' . base64_encode('wrong:credentials')]);

        $response->assertStatus(401)
            ->assertJson([
                'responseCode' => '401',
                'errorMessage' => 'Invalid Authorisation Header',
            ]);
    }

    /** @test */
    public function it_validates_interpol_callback_required_fields()
    {
        $response = $this->postJson('/api/e-visa/interpol-nominal-verification/callback', [
            'uniqueReferenceId' => 'TEST-004',
            'firstName' => 'John',
        ], $this->getAuthHeader());

        $response->assertStatus(400)
            ->assertJson([
                'uniqueReferenceId' => 'TEST-004',
                'responseCode' => '400',
            ])
            ->assertJsonPath('errorMessage', function ($value) {
                return str_contains($value, 'Missing Mandatory Field(s)');
            });
    }

    /** @test */
    public function it_validates_interpol_callback_date_format()
    {
        $response = $this->postJson('/api/e-visa/interpol-nominal-verification/callback', [
            'uniqueReferenceId' => 'TEST-005',
            'firstName' => 'John',
            'surname' => 'Doe',
            'dateOfBirth' => '1990-09-20', // Wrong format
            'interpolNominalMatched' => 'No',
        ], $this->getAuthHeader());

        $response->assertStatus(400)
            ->assertJson([
                'uniqueReferenceId' => 'TEST-005',
                'responseCode' => '400',
            ]);
    }

    /** @test */
    public function it_validates_interpol_nominal_matched_values()
    {
        $response = $this->postJson('/api/e-visa/interpol-nominal-verification/callback', [
            'uniqueReferenceId' => 'TEST-006',
            'firstName' => 'John',
            'surname' => 'Doe',
            'dateOfBirth' => '20/09/1990',
            'interpolNominalMatched' => 'Maybe', // Invalid value
        ], $this->getAuthHeader());

        $response->assertStatus(400)
            ->assertJson([
                'uniqueReferenceId' => 'TEST-006',
                'responseCode' => '400',
            ]);
    }

    /** @test */
    public function it_accepts_valid_evisa_check_request()
    {
        $response = $this->postJson('/api/e-visa/visa-check', [
            'uniqueReferenceId' => 'TEST-101',
            'firstName' => 'John',
            'surname' => 'Doe',
            'dateOfBirth' => '1990-09-20',
            'nationality' => 'USA',
            'travelDocNumber' => 'P123456789',
        ], $this->getAuthHeader());

        $response->assertStatus(200)
            ->assertJsonStructure([
                'uniqueReferenceId',
                'errorMessage',
            ]);
    }

    /** @test */
    public function it_rejects_evisa_check_without_auth()
    {
        $response = $this->postJson('/api/e-visa/visa-check', [
            'uniqueReferenceId' => 'TEST-102',
            'firstName' => 'John',
            'surname' => 'Doe',
            'dateOfBirth' => '1990-09-20',
            'nationality' => 'USA',
            'travelDocNumber' => 'P123456789',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'responseCode' => '401',
                'errorMessage' => 'Invalid Authorisation Header',
            ]);
    }

    /** @test */
    public function it_validates_evisa_check_required_fields()
    {
        $response = $this->postJson('/api/e-visa/visa-check', [
            'uniqueReferenceId' => 'TEST-103',
            'firstName' => 'John',
            'surname' => 'Doe',
        ], $this->getAuthHeader());

        $response->assertStatus(400)
            ->assertJson([
                'uniqueReferenceId' => 'TEST-103',
                'responseCode' => '400',
            ]);
    }

    /** @test */
    public function it_validates_evisa_check_date_format()
    {
        $response = $this->postJson('/api/e-visa/visa-check', [
            'uniqueReferenceId' => 'TEST-104',
            'firstName' => 'John',
            'surname' => 'Doe',
            'dateOfBirth' => '20/09/1990', // Wrong format
            'nationality' => 'USA',
            'travelDocNumber' => 'P123456789',
        ], $this->getAuthHeader());

        $response->assertStatus(400)
            ->assertJson([
                'uniqueReferenceId' => 'TEST-104',
                'responseCode' => '400',
            ]);
    }

    /** @test */
    public function it_validates_evisa_check_nationality_length()
    {
        $response = $this->postJson('/api/e-visa/visa-check', [
            'uniqueReferenceId' => 'TEST-105',
            'firstName' => 'John',
            'surname' => 'Doe',
            'dateOfBirth' => '1990-09-20',
            'nationality' => 'US', // Should be 3 chars
            'travelDocNumber' => 'P123456789',
        ], $this->getAuthHeader());

        $response->assertStatus(400)
            ->assertJson([
                'uniqueReferenceId' => 'TEST-105',
                'responseCode' => '400',
            ]);
    }
}
