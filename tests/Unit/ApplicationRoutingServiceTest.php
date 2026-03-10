<?php

namespace Tests\Unit;

use App\Models\Application;
use App\Services\ApplicationRoutingService;
use App\Services\RiskScreeningService;
use App\Services\SlaService;
use App\Services\TierClassificationService;
use PHPUnit\Framework\TestCase;

class ApplicationRoutingServiceTest extends TestCase
{
    private function makeService(): ApplicationRoutingService
    {
        $tier = $this->createMock(TierClassificationService::class);
        $tier->method('classify')->willReturn(null);

        $sla = $this->createMock(SlaService::class);
        $sla->method('calculateDeadline')->willReturn(now()->addHours(72));
        $sla->method('extendDeadline')->willReturnCallback(
            fn ($deadline, $hours) => $deadline->copy()->addHours($hours)
        );

        $risk = $this->createMock(RiskScreeningService::class);
        $risk->method('performRiskAssessment');

        return new ApplicationRoutingService($tier, $sla, $risk);
    }

    public function test_regular_visa_routes_to_mfa(): void
    {
        $service = $this->makeService();
        $app = new Application(['visa_channel' => 'regular', 'processing_tier' => 'standard']);

        $service->route($app);

        $this->assertSame('mfa', $app->assigned_agency);
    }

    public function test_evisa_standard_routes_to_mfa(): void
    {
        $service = $this->makeService();
        $app = new Application(['visa_channel' => 'e-visa', 'processing_tier' => 'standard']);

        $service->route($app);

        $this->assertSame('mfa', $app->assigned_agency);
    }

    public function test_evisa_priority_routes_to_gis(): void
    {
        $service = $this->makeService();
        $app = new Application(['visa_channel' => 'e-visa', 'processing_tier' => 'priority']);

        $service->route($app);

        $this->assertSame('gis', $app->assigned_agency);
    }
}

