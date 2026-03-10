<?php

namespace Tests\Unit;

use App\Services\PassportVerificationService;
use PHPUnit\Framework\TestCase;

class PassportVerificationServiceTest extends TestCase
{
    public function test_expired_passport_is_flagged(): void
    {
        $service = new PassportVerificationService();
        $expiry = now()->subDay();

        $result = $service->validateExpiry($expiry);

        $this->assertFalse($result['valid']);
        $this->assertSame('expired', $result['code']);
    }

    public function test_passport_with_less_than_six_months_is_near_expiry(): void
    {
        $service = new PassportVerificationService();
        $expiry = now()->addMonths(3);

        $result = $service->validateExpiry($expiry);

        $this->assertTrue($result['valid']);
        $this->assertSame('near_expiry', $result['code']);
        $this->assertArrayHasKey('months_remaining', $result);
    }

    public function test_passport_with_more_than_six_months_is_ok(): void
    {
        $service = new PassportVerificationService();
        $expiry = now()->addYear();

        $result = $service->validateExpiry($expiry);

        $this->assertTrue($result['valid']);
        $this->assertSame('ok', $result['code']);
    }
}

