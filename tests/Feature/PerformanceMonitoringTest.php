<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\VerificationPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PerformanceMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected VerificationPerformanceService $performanceService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->performanceService = app(VerificationPerformanceService::class);
    }

    public function test_records_verification_performance()
    {
        // Record a verification
        $this->performanceService->recordVerification('ETA', 0.5, true, null);

        // Check current hour stats
        $stats = $this->performanceService->getCurrentHourStats();

        $this->assertEquals(1, $stats['total_requests']);
        $this->assertEquals(1, $stats['successful_requests']);
        $this->assertEquals(0, $stats['failed_requests']);
        $this->assertEquals(100.0, $stats['success_rate']);
        $this->assertEquals(0.5, $stats['average_response_time']);
    }

    public function test_records_failed_verification()
    {
        // Record a failed verification
        $this->performanceService->recordVerification('VISA', 1.2, false, 'AUTHORIZATION_EXPIRED');

        // Check current hour stats
        $stats = $this->performanceService->getCurrentHourStats();

        $this->assertEquals(1, $stats['total_requests']);
        $this->assertEquals(0, $stats['successful_requests']);
        $this->assertEquals(1, $stats['failed_requests']);
        $this->assertEquals(0.0, $stats['success_rate']);
        $this->assertEquals(1.2, $stats['average_response_time']);
        $this->assertArrayHasKey('AUTHORIZATION_EXPIRED', $stats['failure_reasons']);
        $this->assertEquals(1, $stats['failure_reasons']['AUTHORIZATION_EXPIRED']);
    }

    public function test_tracks_slow_requests()
    {
        // Record a slow verification (> 2 seconds)
        $this->performanceService->recordVerification('ETA', 2.5, true, null);

        // Check current hour stats
        $stats = $this->performanceService->getCurrentHourStats();

        $this->assertEquals(1, $stats['slow_requests']);
        $this->assertEquals(100.0, $stats['slow_request_percentage']);
    }

    public function test_calculates_performance_status()
    {
        // Record excellent performance
        $this->performanceService->recordVerification('ETA', 0.3, true, null);
        $this->performanceService->recordVerification('VISA', 0.4, true, null);

        $summary = $this->performanceService->getDashboardSummary();
        $status = $summary['performance_status'];

        $this->assertEquals('excellent', $status['status']);
        $this->assertTrue($status['meets_sla']);
        $this->assertLessThan(1.0, $status['average_response_time']);
    }

    public function test_dashboard_api_endpoint()
    {
        // Create admin user
        $admin = User::factory()->create(['role' => 'admin']);

        // Record some performance data
        $this->performanceService->recordVerification('ETA', 0.5, true, null);
        $this->performanceService->recordVerification('VISA', 0.8, true, null);

        // Test dashboard endpoint
        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/verification-stats/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'current_hour' => [
                    'total_requests',
                    'successful_requests',
                    'failed_requests',
                    'success_rate',
                    'average_response_time',
                    'by_type',
                ],
                'last_24_hours_summary' => [
                    'total_requests',
                    'hourly_breakdown',
                ],
                'performance_status' => [
                    'status',
                    'message',
                    'meets_sla',
                ],
            ]);

        $data = $response->json();
        $this->assertEquals(2, $data['current_hour']['total_requests']);
        $this->assertEquals(100.0, $data['current_hour']['success_rate']);
    }

    public function test_verification_endpoint_tracks_performance()
    {
        // Create airline staff user
        $user = User::factory()->create(['role' => 'airline_staff']);

        // Create an ETA application for testing
        $eta = \App\Models\EtaApplication::factory()->create([
            'eta_number' => 'GH-ETA-20260311-0001',
            'passport_number' => 'A1234567',
            'status' => 'approved',
            'valid_until' => now()->addDays(30),
        ]);

        // Make verification request
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/verify-travel', [
                'passport_number' => 'A1234567',
                'nationality' => 'US',
                'eta_number' => 'GH-ETA-20260311-0001',
            ]);

        $response->assertStatus(200);

        // Check that performance was recorded
        $stats = $this->performanceService->getCurrentHourStats();
        $this->assertGreaterThan(0, $stats['total_requests']);
    }

    protected function tearDown(): void
    {
        // Clear cache after each test
        Cache::flush();
        parent::tearDown();
    }
}