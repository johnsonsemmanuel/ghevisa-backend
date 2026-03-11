<?php

namespace Tests\Feature;

use App\Models\EtaApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireEtaCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_expires_etas_past_expiry_date()
    {
        // Create an expired ETA
        $expiredEta = EtaApplication::factory()->create([
            'status' => 'approved',
            'expires_at' => now()->subDays(1),
        ]);

        // Create a valid ETA
        $validEta = EtaApplication::factory()->create([
            'status' => 'approved',
            'expires_at' => now()->addDays(30),
        ]);

        // Run the command
        $this->artisan('eta:expire')
            ->expectsOutput('Checking for expired ETA applications...')
            ->expectsOutput('Found 1 expired ETA(s)')
            ->expectsOutput('Successfully expired 1 ETA application(s)')
            ->assertExitCode(0);

        // Check that expired ETA was updated
        $expiredEta->refresh();
        $this->assertEquals('expired', $expiredEta->status);

        // Check that valid ETA was not touched
        $validEta->refresh();
        $this->assertEquals('approved', $validEta->status);
    }

    /** @test */
    public function it_handles_no_expired_etas()
    {
        // Create only valid ETAs
        EtaApplication::factory()->count(3)->create([
            'status' => 'approved',
            'expires_at' => now()->addDays(30),
        ]);

        $this->artisan('eta:expire')
            ->expectsOutput('No expired ETAs found.')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_supports_dry_run_mode()
    {
        $expiredEta = EtaApplication::factory()->create([
            'status' => 'approved',
            'expires_at' => now()->subDays(1),
        ]);

        $this->artisan('eta:expire', ['--dry-run' => true])
            ->expectsOutput('DRY RUN MODE - No changes will be made')
            ->assertExitCode(0);

        // Check that ETA was NOT updated
        $expiredEta->refresh();
        $this->assertEquals('approved', $expiredEta->status);
    }

    /** @test */
    public function it_only_expires_approved_etas()
    {
        // Create expired ETAs with different statuses
        $expiredApproved = EtaApplication::factory()->create([
            'status' => 'approved',
            'expires_at' => now()->subDays(1),
        ]);

        $expiredPending = EtaApplication::factory()->create([
            'status' => 'pending',
            'expires_at' => now()->subDays(1),
        ]);

        $expiredDenied = EtaApplication::factory()->create([
            'status' => 'denied',
            'expires_at' => now()->subDays(1),
        ]);

        $this->artisan('eta:expire')
            ->expectsOutput('Found 1 expired ETA(s)')
            ->assertExitCode(0);

        // Only approved ETA should be expired
        $expiredApproved->refresh();
        $this->assertEquals('expired', $expiredApproved->status);

        // Others should remain unchanged
        $expiredPending->refresh();
        $this->assertEquals('pending', $expiredPending->status);

        $expiredDenied->refresh();
        $this->assertEquals('denied', $expiredDenied->status);
    }
}
