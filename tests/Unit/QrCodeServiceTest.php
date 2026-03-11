<?php

namespace Tests\Unit;

use App\Models\Application;
use App\Models\EtaApplication;
use App\Models\VisaType;
use App\Services\QrCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class QrCodeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected QrCodeService $qrService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->qrService = new QrCodeService();
    }

    /** @test */
    public function it_generates_evisa_qr_with_passport_binding()
    {
        $visaType = VisaType::factory()->create([
            'name' => 'Tourist Visa',
            'entry_type' => 'single',
            'max_duration_days' => 90,
        ]);

        $application = Application::factory()->create([
            'status' => 'approved',
            'reference_number' => 'GH-2026-000001',
            'passport_number' => 'P12345678',
            'visa_type_id' => $visaType->id,
            'decided_at' => now(),
        ]);

        $qrData = $this->qrService->generateEvisaQrData($application);

        // Verify format
        $this->assertStringStartsWith('GH-EVISA:', $qrData);

        // Decode and verify payload
        $encoded = substr($qrData, 9);
        $payload = json_decode(base64_decode($encoded), true);

        $this->assertEquals('GHEVISA', $payload['type']);
        $this->assertEquals('GH-2026-000001', $payload['ref']);
        $this->assertEquals('P12345678', $payload['passport']);
        $this->assertEquals(2, $payload['v']); // Version 2 with passport
        $this->assertEquals('single', $payload['entry']);
        $this->assertArrayHasKey('hash', $payload);
    }

    /** @test */
    public function it_generates_eta_qr_with_simple_format()
    {
        $eta = EtaApplication::factory()->create([
            'status' => 'approved',
            'eta_number' => 'GH-ETA-20260308-AX7283',
            'passport_number_encrypted' => Crypt::encryptString('P87654321'),
            'approved_at' => now(),
            'expires_at' => now()->addDays(90),
        ]);

        $qrData = $this->qrService->generateEtaQrData($eta);

        // Verify simple format: ETA_NUMBER|PASSPORT_NUMBER
        $this->assertStringContainsString('|', $qrData);
        
        $parts = explode('|', $qrData);
        $this->assertCount(2, $parts);
        $this->assertEquals('GH-ETA-20260308-AX7283', $parts[0]);
        $this->assertEquals('P87654321', $parts[1]);
    }

    /** @test */
    public function it_verifies_evisa_qr_with_passport_binding()
    {
        $visaType = VisaType::factory()->create([
            'max_duration_days' => 90,
            'entry_type' => 'single',
        ]);

        $application = Application::factory()->create([
            'status' => 'approved',
            'reference_number' => 'GH-2026-000002',
            'passport_number' => 'P11111111',
            'visa_type_id' => $visaType->id,
            'decided_at' => now(),
        ]);

        $qrData = $this->qrService->generateEvisaQrData($application);
        $result = $this->qrService->verifyQrCode($qrData);

        $this->assertTrue($result['valid']);
        $this->assertEquals('evisa', $result['type']);
        $this->assertEquals('GH-2026-000002', $result['reference_number']);
        $this->assertEquals('P11111111', $result['passport_number']);
    }

    /** @test */
    public function it_verifies_eta_qr_with_passport_binding()
    {
        $eta = EtaApplication::factory()->create([
            'status' => 'approved',
            'eta_number' => 'GH-ETA-20260310-TEST01',
            'passport_number_encrypted' => Crypt::encryptString('P99999999'),
            'first_name_encrypted' => Crypt::encryptString('John'),
            'last_name_encrypted' => Crypt::encryptString('Doe'),
            'nationality_encrypted' => Crypt::encryptString('US'),
            'approved_at' => now(),
            'expires_at' => now()->addDays(90),
            'entry_type' => 'single',
            'entry_consumed' => false,
        ]);

        $qrData = $this->qrService->generateEtaQrData($eta);
        $result = $this->qrService->verifyQrCode($qrData);

        $this->assertTrue($result['valid']);
        $this->assertEquals('eta', $result['type']);
        $this->assertEquals('GH-ETA-20260310-TEST01', $result['eta_number']);
        $this->assertEquals('P99999999', $result['passport_number']);
        $this->assertTrue($result['passport_verified']);
    }

    /** @test */
    public function it_rejects_eta_with_wrong_passport()
    {
        $eta = EtaApplication::factory()->create([
            'status' => 'approved',
            'eta_number' => 'GH-ETA-20260310-TEST02',
            'passport_number_encrypted' => Crypt::encryptString('P12345678'),
            'approved_at' => now(),
            'expires_at' => now()->addDays(90),
        ]);

        // Generate QR with correct passport
        $qrData = $this->qrService->generateEtaQrData($eta);
        
        // Tamper with passport in QR
        $tamperedQr = str_replace('P12345678', 'P99999999', $qrData);
        
        $result = $this->qrService->verifyQrCode($tamperedQr);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Passport binding verification failed', $result['error']);
        $this->assertTrue($result['passport_mismatch']);
    }

    /** @test */
    public function it_detects_consumed_eta_in_qr_verification()
    {
        $eta = EtaApplication::factory()->create([
            'status' => 'approved',
            'eta_number' => 'GH-ETA-20260310-USED01',
            'passport_number_encrypted' => Crypt::encryptString('P55555555'),
            'approved_at' => now(),
            'expires_at' => now()->addDays(90),
            'entry_type' => 'single',
            'entry_consumed' => true,
            'entry_date' => now()->subDays(5),
            'port_of_entry_used' => 'KIA',
        ]);

        $qrData = $this->qrService->generateEtaQrData($eta);
        $result = $this->qrService->verifyQrCode($qrData);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('already used', $result['error']);
        $this->assertArrayHasKey('entry_date', $result);
        $this->assertArrayHasKey('port_of_entry', $result);
    }

    /** @test */
    public function it_detects_expired_eta_in_qr_verification()
    {
        $eta = EtaApplication::factory()->create([
            'status' => 'approved',
            'eta_number' => 'GH-ETA-20260101-EXPIRED',
            'passport_number_encrypted' => Crypt::encryptString('P77777777'),
            'approved_at' => now()->subDays(100),
            'expires_at' => now()->subDays(10), // Expired
        ]);

        $qrData = $this->qrService->generateEtaQrData($eta);
        $result = $this->qrService->verifyQrCode($qrData);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('expired', $result['error']);
        $this->assertArrayHasKey('expired_on', $result);
    }

    /** @test */
    public function it_supports_legacy_eta_qr_format()
    {
        // Create legacy format QR (without passport)
        $eta = EtaApplication::factory()->create([
            'status' => 'approved',
            'eta_number' => 'GH-ETA-20260101-LEGACY',
            'passport_number_encrypted' => Crypt::encryptString('P00000000'),
            'first_name_encrypted' => Crypt::encryptString('Jane'),
            'last_name_encrypted' => Crypt::encryptString('Smith'),
            'nationality_encrypted' => Crypt::encryptString('GB'),
            'approved_at' => now(),
            'expires_at' => now()->addDays(90),
        ]);

        $legacyPayload = [
            'type' => 'GHETA',
            'eta' => 'GH-ETA-20260101-LEGACY',
            'ref' => $eta->reference_number,
            'v' => 1,
            'issued' => $eta->approved_at->format('Ymd'),
            'expires' => $eta->expires_at->format('Ymd'),
        ];

        $legacyQr = 'GH-ETA:' . base64_encode(json_encode($legacyPayload));
        
        $result = $this->qrService->verifyQrCode($legacyQr);

        $this->assertTrue($result['valid']);
        $this->assertEquals('eta', $result['type']);
        $this->assertNull($result['passport_number']); // Legacy format doesn't include passport
        $this->assertTrue($result['requires_manual_passport_check']);
    }
}
