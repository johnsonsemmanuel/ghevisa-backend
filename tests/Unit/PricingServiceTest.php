<?php

namespace Tests\Unit;

use App\Services\PricingService;
use PHPUnit\Framework\TestCase;

class PricingServiceTest extends TestCase
{
    public function test_single_standard_pricing_matches_spec(): void
    {
        $service = new PricingService();

        $result = $service->calculatePrice([
            'visa_channel' => 'e-visa',
            'entry_type' => 'single',
            'service_tier_code' => 'standard',
        ]);

        $this->assertSame(260.00, $result['total']);
    }

    public function test_multiple_standard_pricing_matches_spec(): void
    {
        $service = new PricingService();

        $result = $service->calculatePrice([
            'visa_channel' => 'e-visa',
            'entry_type' => 'multiple',
            'service_tier_code' => 'standard',
        ]);

        $this->assertSame(468.00, $result['total']); // 260 * 1.8 * 1.0
    }

    public function test_single_priority_pricing_matches_spec(): void
    {
        $service = new PricingService();

        $result = $service->calculatePrice([
            'visa_channel' => 'e-visa',
            'entry_type' => 'single',
            'service_tier_code' => 'priority',
        ]);

        $this->assertSame(260.00 * 1.0 * 1.3, $result['total']);
    }

    public function test_multiple_express_pricing_matches_spec(): void
    {
        $service = new PricingService();

        $result = $service->calculatePrice([
            'visa_channel' => 'e-visa',
            'entry_type' => 'multiple',
            'service_tier_code' => 'express',
        ]);

        $expected = 260.00 * 1.8 * 1.7;
        $this->assertSame($expected, $result['total']);
    }
}

