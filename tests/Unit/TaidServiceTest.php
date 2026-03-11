<?php

namespace Tests\Unit;

use App\Models\Application;
use App\Models\EtaApplication;
use App\Services\TaidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaidServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TaidService $taidService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taidService = new TaidService();
    }

    public function test_taid_generation_format()
    {
        $taid = $this->taidService->generate();

        // Should match format: GH-TA-YYYYMMDD-XXXX (per specification)
        $this->assertMatchesRegularExpression('/^GH-TA-\d{8}-[A-Z0-9]{4,10}$/', $taid);
    }

    public function test_taid_uniqueness()
    {
        $taid1 = $this->taidService->generate();
        $taid2 = $this->taidService->generate();

        $this->assertNotEquals($taid1, $taid2);
    }

    public function test_taid_contains_current_date()
    {
        $taid = $this->taidService->generate();
        $expectedDate = date('Ymd');

        $this->assertStringContainsString($expectedDate, $taid);
    }

    public function test_assign_taid_to_application()
    {
        $application = Application::factory()->create(['taid' => null]);

        $this->assertNull($application->taid);

        $this->taidService->assignToApplication($application);
        $application->refresh();

        $this->assertNotNull($application->taid);
        $this->assertMatchesRegularExpression('/^GH-TA-\d{8}-[A-Z0-9]{4,10}$/', $application->taid);
        
        // Verify travel_authorizations record was created
        $this->assertDatabaseHas('travel_authorizations', [
            'taid' => $application->taid,
            'authorization_type' => 'VISA',
        ]);
    }

    public function test_assign_taid_to_eta()
    {
        $eta = EtaApplication::factory()->create(['taid' => null]);

        $this->assertNull($eta->taid);

        $this->taidService->assignToEta($eta);
        $eta->refresh();

        $this->assertNotNull($eta->taid);
        $this->assertMatchesRegularExpression('/^GH-TA-\d{8}-[A-Z0-9]{4,10}$/', $eta->taid);
        
        // Verify travel_authorizations record was created
        $this->assertDatabaseHas('travel_authorizations', [
            'taid' => $eta->taid,
            'authorization_type' => 'ETA',
        ]);
    }

    public function test_does_not_reassign_existing_taid()
    {
        $originalTaid = 'GH-TA-20260311-TEST';
        $application = Application::factory()->create(['taid' => $originalTaid]);

        $this->taidService->assignToApplication($application);
        $application->refresh();

        $this->assertEquals($originalTaid, $application->taid);
    }

    public function test_find_by_taid_returns_eta()
    {
        $eta = EtaApplication::factory()->create();

        $result = $this->taidService->findByTaid($eta->taid);

        $this->assertNotNull($result);
        $this->assertEquals('eta', $result['type']);
        $this->assertEquals($eta->id, $result['record']->id);
        $this->assertEquals($eta->eta_number, $result['authorization_number']);
    }

    public function test_find_by_taid_returns_visa()
    {
        $application = Application::factory()->create();

        $result = $this->taidService->findByTaid($application->taid);

        $this->assertNotNull($result);
        $this->assertEquals('visa', $result['type']);
        $this->assertEquals($application->id, $result['record']->id);
    }

    public function test_find_by_taid_returns_null_for_invalid()
    {
        $result = $this->taidService->findByTaid('GH-TA-20260311-XXXX');

        $this->assertNull($result);
    }

    public function test_get_taid_from_reference_number_for_application()
    {
        $application = Application::factory()->create();

        $taid = $this->taidService->getTaidFromReference($application->reference_number);

        $this->assertEquals($application->taid, $taid);
    }

    public function test_get_taid_from_reference_number_for_eta()
    {
        $eta = EtaApplication::factory()->create();

        $taid = $this->taidService->getTaidFromReference($eta->reference_number);

        $this->assertEquals($eta->taid, $taid);
    }

    public function test_get_taid_from_reference_returns_null_for_invalid()
    {
        $taid = $this->taidService->getTaidFromReference('INVALID-REF-123');

        $this->assertNull($taid);
    }

    public function test_is_valid_format_accepts_valid_taid()
    {
        $validTaids = [
            'GH-TA-20260311-ABC1',
            'GH-TA-20260311-A1B2',
            'GH-TA-20260311-1234',
            'GH-TA-20260311-ABCD1234', // 8 chars
        ];

        foreach ($validTaids as $taid) {
            $this->assertTrue(
                $this->taidService->isValidFormat($taid),
                "Failed asserting that {$taid} is valid"
            );
        }
    }

    public function test_is_valid_format_rejects_invalid_taid()
    {
        $invalidTaids = [
            'GH-TA-2026031-ABC1',       // Wrong date length
            'GH-TA-20260311-abc1',      // Lowercase
            'GH-TA-20260311-AB',        // Too short (< 4 chars)
            'INVALID-20260311-ABC1',    // Wrong prefix
            '20260311-ABC1',            // Missing prefix
            'GH-TA-20260311',           // Missing code
            'TAID-20260311-ABC1',       // Old format
        ];

        foreach ($invalidTaids as $taid) {
            $this->assertFalse(
                $this->taidService->isValidFormat($taid),
                "Failed asserting that {$taid} is invalid"
            );
        }
    }

    public function test_get_authorization_stats_for_eta()
    {
        $eta = EtaApplication::factory()->approved()->create();

        $stats = $this->taidService->getAuthorizationStats($eta->taid);

        $this->assertTrue($stats['found']);
        $this->assertEquals('eta', $stats['type']);
        $this->assertEquals($eta->taid, $stats['taid']);
        $this->assertEquals($eta->reference_number, $stats['reference_number']);
        $this->assertEquals($eta->eta_number, $stats['eta_number']);
        $this->assertArrayHasKey('border_crossings', $stats);
    }

    public function test_get_authorization_stats_for_visa()
    {
        $application = Application::factory()->approved()->create();

        $stats = $this->taidService->getAuthorizationStats($application->taid);

        $this->assertTrue($stats['found']);
        $this->assertEquals('visa', $stats['type']);
        $this->assertEquals($application->taid, $stats['taid']);
        $this->assertEquals($application->reference_number, $stats['reference_number']);
        $this->assertArrayHasKey('visa_type', $stats);
        $this->assertArrayHasKey('border_crossings', $stats);
    }

    public function test_get_authorization_stats_returns_not_found()
    {
        $stats = $this->taidService->getAuthorizationStats('GH-TA-20260311-NONE');

        $this->assertFalse($stats['found']);
        $this->assertEquals('GH-TA-20260311-NONE', $stats['taid']);
    }

    public function test_backfill_assigns_taids_to_applications_without_them()
    {
        // Create applications without TAIDs
        Application::factory()->count(5)->create(['taid' => null]);
        EtaApplication::factory()->count(3)->create(['taid' => null]);

        $stats = $this->taidService->backfillExistingRecords();

        $this->assertEquals(5, $stats['applications_updated']);
        $this->assertEquals(3, $stats['eta_updated']);
        $this->assertEquals(0, $stats['errors']);

        // Verify all have TAIDs now
        $this->assertEquals(0, Application::whereNull('taid')->count());
        $this->assertEquals(0, EtaApplication::whereNull('taid')->count());
    }

    public function test_backfill_skips_records_with_existing_taids()
    {
        // Create applications with TAIDs
        Application::factory()->count(3)->create();
        EtaApplication::factory()->count(2)->create();

        // Create some without TAIDs
        Application::factory()->count(2)->create(['taid' => null]);
        EtaApplication::factory()->count(1)->create(['taid' => null]);

        $stats = $this->taidService->backfillExistingRecords();

        // Should only update the ones without TAIDs
        $this->assertEquals(2, $stats['applications_updated']);
        $this->assertEquals(1, $stats['eta_updated']);
    }
}
