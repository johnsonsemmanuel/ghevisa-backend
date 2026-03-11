<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\EtaApplication;
use App\Models\User;
use App\Models\VisaType;
use App\Services\QrCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class QrCodeVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected QrCodeService $qrService;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->qrService = new QrCodeService();
        
        $this->user = User::create([
            'first_name' => 'Test',
            'last_name' => 'Officer',
            'email' => 'officer@test.com',
            'password' => bcrypt('password'),
            'role' => 'border_officer',
        ]);
    }

    public function test_eta_qr_code_includes_passport_number()
    {
        $eta = EtaApplication::create([
            'reference_number' => 'GH-ETA-2026-TEST01',
            'user_id' => $this->user->id,
            'eta_number' => 'GH-ETA-20260311-ABC123',
            'status' => 'approved',
            'passport_number_encrypted' => Crypt::encryptString('P12345678'),
            'first_name_encrypted' => Crypt::encryptString('John'),
            'last_name_encrypted' => Crypt::encryptString('Doe'),
            'nationality_encrypted' => Crypt::encryptString('US'),
            'email_encrypted' => Crypt::encryptString('john@example.com'),
            'phone_encrypted' => Crypt::encryptString('+1234567890'),
            'date_of_birth' => '1990-01-01',
            'gender' => 'male',
            'passport_issue_date' => '2020-01-01',
            'passport_expiry_date' => '2030-01-01',
            'intended_arrival_date' => now()->addDays(30),
            'port_of_entry' => 'KIA',
            'entry_type' => 'single',
            'approved_at' => now(),
            'expires_at' => now()->addDays(90),
        ]);

        $qrData = $this->qrService->generateEtaQrData($eta);

        // Verify format: ETA_NUMBER|PASSPORT_NUMBER
        $this->assertStringContainsString('|', $qrData);
        $this->assertStringContainsString('GH-ETA-20260311-ABC123', $qrData);
        $this->assertStringContainsString('P12345678', $qrData);
        
        $parts = explode('|', $qrData);
        $this->assertCount(2, $parts);
        $this->assertEquals('GH-ETA-20260311-ABC123', $parts[0]);
        $this->assertEquals('P12345678', $parts[1]);
    }

    public function test_eta_qr_verification_enforces_passport_binding()
    {
        $eta = EtaApplication::create([
            'reference_number' => 'GH-ETA-2026-TEST02',
            'user_id' => $this->user->id,
            'eta_number' => 'GH-ETA-20260311-XYZ789',
            'status' => 'approved',
            'passport_number_encrypted' => Crypt::encryptString('P87654321'),
            'first_name_encrypted' => Crypt::encryptString('Jane'),
            'last_name_encrypted' => Crypt::encryptString('Smith'),
            'nationality_encrypted' => Crypt::encryptString('GB'),
            'email_encrypted' => Crypt::encryptString('jane@example.com'),
            'phone_encrypted' => Crypt::encryptString('+44123456789'),
            'date_of_birth' => '1985-05-15',
            'gender' => 'female',
            'passport_issue_date' => '2019-01-01',
            'passport_expiry_date' => '2029-01-01',
            'intended_arrival_date' => now()->addDays(20),
            'port_of_entry' => 'KIA',
            'entry_type' => 'single',
            'approved_at' => now(),
            'expires_at' => now()->addDays(90),
        ]);

        $qrData = $this->qrService->generateEtaQrData($eta);
        
        // Valid verification
        $result = $this->qrService->verifyQrCode($qrData);
        $this->assertTrue($result['valid']);
        $this->assertEquals('P87654321', $result['passport_number']);
        $this->assertTrue($result['passport_verified']);

        // Tampered passport - should fail
        $tamperedQr = str_replace('P87654321', 'P99999999', $qrData);
        $result = $this->qrService->verifyQrCode($tamperedQr);
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('passport_mismatch', $result);
    }

    public function test_evisa_qr_code_includes_passport_in_payload()
    {
        $visaType = VisaType::create([
            'name' => 'Tourist Visa',
            'slug' => 'tourist-visa',
            'description' => 'Tourist visa for Ghana',
            'base_fee' => 150.00,
            'max_duration_days' => 90,
            'entry_type' => 'single',
            'is_active' => true,
            'required_documents' => json_encode(['passport', 'photo']),
        ]);

        $application = Application::create([
            'reference_number' => 'GH-2026-000001',
            'user_id' => $this->user->id,
            'visa_type_id' => $visaType->id,
            'status' => 'approved',
            'first_name_encrypted' => Crypt::encryptString('Michael'),
            'last_name_encrypted' => Crypt::encryptString('Johnson'),
            'passport_number_encrypted' => Crypt::encryptString('P11111111'),
            'nationality_encrypted' => Crypt::encryptString('US'),
            'email_encrypted' => Crypt::encryptString('michael@example.com'),
            'phone_encrypted' => Crypt::encryptString('+1555123456'),
            'date_of_birth_encrypted' => Crypt::encryptString('1980-03-20'),
            'first_name' => 'Michael',
            'last_name' => 'Johnson',
            'passport_number' => 'P11111111',
            'nationality' => 'US',
            'email' => 'michael@example.com',
            'phone' => '+1555123456',
            'date_of_birth' => '1980-03-20',
            'gender' => 'male',
            'passport_issue_date' => '2018-01-01',
            'passport_expiry' => '2028-01-01',
            'intended_arrival' => now()->addDays(45),
            'duration_days' => 30,
            'decided_at' => now(),
        ]);

        $qrData = $this->qrService->generateEvisaQrData($application);

        // Verify format
        $this->assertStringStartsWith('GH-EVISA:', $qrData);

        // Decode payload
        $encoded = substr($qrData, 9);
        $payload = json_decode(base64_decode($encoded), true);

        $this->assertEquals('GHEVISA', $payload['type']);
        $this->assertEquals('GH-2026-000001', $payload['ref']);
        $this->assertEquals('P11111111', $payload['passport']);
        $this->assertEquals(2, $payload['v']); // Version 2
        $this->assertArrayHasKey('hash', $payload);
    }

    public function test_qr_verification_detects_consumed_eta()
    {
        $eta = EtaApplication::create([
            'reference_number' => 'GH-ETA-2026-CONSUMED',
            'user_id' => $this->user->id,
            'eta_number' => 'GH-ETA-20260311-USED01',
            'status' => 'approved',
            'passport_number_encrypted' => Crypt::encryptString('P55555555'),
            'first_name_encrypted' => Crypt::encryptString('Used'),
            'last_name_encrypted' => Crypt::encryptString('Entry'),
            'nationality_encrypted' => Crypt::encryptString('CA'),
            'email_encrypted' => Crypt::encryptString('used@example.com'),
            'phone_encrypted' => Crypt::encryptString('+1234567890'),
            'date_of_birth' => '1992-07-10',
            'gender' => 'male',
            'passport_issue_date' => '2021-01-01',
            'passport_expiry_date' => '2031-01-01',
            'intended_arrival_date' => now()->addDays(10),
            'port_of_entry' => 'KIA',
            'entry_type' => 'single',
            'entry_consumed' => true,
            'entry_date' => now()->subDays(5),
            'port_of_entry_used' => 'KIA',
            'approved_at' => now()->subDays(10),
            'expires_at' => now()->addDays(80),
        ]);

        $qrData = $this->qrService->generateEtaQrData($eta);
        $result = $this->qrService->verifyQrCode($qrData);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('already used', $result['error']);
    }

    public function test_border_officer_can_verify_qr_via_api()
    {
        $eta = EtaApplication::create([
            'reference_number' => 'GH-ETA-2026-API01',
            'user_id' => $this->user->id,
            'eta_number' => 'GH-ETA-20260311-API123',
            'status' => 'approved',
            'passport_number_encrypted' => Crypt::encryptString('P77777777'),
            'first_name_encrypted' => Crypt::encryptString('API'),
            'last_name_encrypted' => Crypt::encryptString('Test'),
            'nationality_encrypted' => Crypt::encryptString('DE'),
            'email_encrypted' => Crypt::encryptString('api@example.com'),
            'phone_encrypted' => Crypt::encryptString('+49123456789'),
            'date_of_birth' => '1988-11-25',
            'gender' => 'female',
            'passport_issue_date' => '2020-06-01',
            'passport_expiry_date' => '2030-06-01',
            'intended_arrival_date' => now()->addDays(15),
            'port_of_entry' => 'KIA',
            'entry_type' => 'single',
            'approved_at' => now(),
            'expires_at' => now()->addDays(90),
        ]);

        $qrData = $this->qrService->generateEtaQrData($eta);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/border/verify-qr', [
                'qr_data' => $qrData,
                'port_of_entry' => 'KIA',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'verified' => true,
            'status' => 'valid',
        ]);
        
        $data = $response->json();
        $this->assertEquals('eta', $data['document']['type']);
        $this->assertEquals('GH-ETA-20260311-API123', $data['document']['eta_number']);
    }
}
