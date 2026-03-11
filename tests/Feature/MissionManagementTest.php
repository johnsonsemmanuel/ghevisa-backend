<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\MfaMission;
use App\Models\MissionCountryMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MissionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $mfaAdmin;
    protected User $gisAdmin;
    protected MfaMission $mission;

    protected function setUp(): void
    {
        parent::setUp();

        // Create MFA admin
        $this->mfaAdmin = User::factory()->create([
            'role' => 'mfa_admin',
            'agency' => 'mfa',
            'is_active' => true,
        ]);

        // Create GIS admin (should not have access)
        $this->gisAdmin = User::factory()->create([
            'role' => 'gis_admin',
            'agency' => 'gis',
            'is_active' => true,
        ]);

        // Create a test mission
        $this->mission = MfaMission::create([
            'code' => 'NY-CONSULATE',
            'name' => 'New York Consulate',
            'city' => 'New York',
            'country_code' => 'US',
            'country_name' => 'United States',
            'region' => 'North America',
            'mission_type' => 'consulate',
            'can_issue_visa' => true,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function mfa_admin_can_list_missions()
    {
        $response = $this->actingAs($this->mfaAdmin, 'sanctum')
            ->getJson('/api/admin/missions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'missions' => [
                    '*' => [
                        'id',
                        'code',
                        'name',
                        'city',
                        'country_code',
                        'is_active',
                        'total_officers',
                        'total_applications',
                    ],
                ],
            ]);
    }

    /** @test */
    public function mfa_admin_can_view_mission_details()
    {
        $response = $this->actingAs($this->mfaAdmin, 'sanctum')
            ->getJson("/api/admin/missions/{$this->mission->id}");

        $response->assertStatus(200)
            ->assertJson([
                'mission' => [
                    'id' => $this->mission->id,
                    'code' => 'NY-CONSULATE',
                    'name' => 'New York Consulate',
                ],
            ]);
    }

    /** @test */
    public function mfa_admin_can_create_mission()
    {
        $missionData = [
            'code' => 'LONDON-MISSION',
            'name' => 'London Mission',
            'city' => 'London',
            'country_code' => 'GB',
            'country_name' => 'United Kingdom',
            'region' => 'Europe',
            'mission_type' => 'embassy',
            'can_issue_visa' => true,
            'is_active' => true,
        ];

        $response = $this->actingAs($this->mfaAdmin, 'sanctum')
            ->postJson('/api/admin/missions', $missionData);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Mission created successfully',
                'mission' => [
                    'code' => 'LONDON-MISSION',
                    'name' => 'London Mission',
                ],
            ]);

        $this->assertDatabaseHas('mfa_missions', [
            'code' => 'LONDON-MISSION',
            'name' => 'London Mission',
        ]);
    }

    /** @test */
    public function mfa_admin_can_update_mission()
    {
        $updateData = [
            'name' => 'New York Consulate General',
            'phone' => '+1-212-555-0100',
        ];

        $response = $this->actingAs($this->mfaAdmin, 'sanctum')
            ->putJson("/api/admin/missions/{$this->mission->id}", $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Mission updated successfully',
            ]);

        $this->assertDatabaseHas('mfa_missions', [
            'id' => $this->mission->id,
            'name' => 'New York Consulate General',
            'phone' => '+1-212-555-0100',
        ]);
    }

    /** @test */
    public function mfa_admin_can_deactivate_mission_without_active_applications()
    {
        $response = $this->actingAs($this->mfaAdmin, 'sanctum')
            ->deleteJson("/api/admin/missions/{$this->mission->id}");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Mission deactivated successfully',
            ]);

        $this->assertDatabaseHas('mfa_missions', [
            'id' => $this->mission->id,
            'is_active' => false,
        ]);
    }

    /** @test */
    public function cannot_deactivate_mission_with_active_applications()
    {
        // Create an active application for this mission
        Application::factory()->create([
            'owner_mission_id' => $this->mission->id,
            'status' => 'under_review',
        ]);

        $response = $this->actingAs($this->mfaAdmin, 'sanctum')
            ->deleteJson("/api/admin/missions/{$this->mission->id}");

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Cannot delete mission with active applications',
            ]);

        $this->assertDatabaseHas('mfa_missions', [
            'id' => $this->mission->id,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function mfa_admin_can_add_country_mapping()
    {
        $mappingData = [
            'country_code' => 'CA',
            'country_name' => 'Canada',
            'is_primary' => false,
        ];

        $response = $this->actingAs($this->mfaAdmin, 'sanctum')
            ->postJson("/api/admin/missions/{$this->mission->id}/countries", $mappingData);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Country mapping added successfully',
            ]);

        $this->assertDatabaseHas('mission_country_mappings', [
            'mfa_mission_id' => $this->mission->id,
            'country_code' => 'CA',
            'country_name' => 'Canada',
        ]);
    }

    /** @test */
    public function cannot_add_duplicate_country_mapping()
    {
        // Create existing mapping
        MissionCountryMapping::create([
            'mfa_mission_id' => $this->mission->id,
            'country_code' => 'US',
            'country_name' => 'United States',
        ]);

        $mappingData = [
            'country_code' => 'US',
            'country_name' => 'United States',
        ];

        $response = $this->actingAs($this->mfaAdmin, 'sanctum')
            ->postJson("/api/admin/missions/{$this->mission->id}/countries", $mappingData);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Country mapping already exists for this mission',
            ]);
    }

    /** @test */
    public function mfa_admin_can_remove_country_mapping()
    {
        $mapping = MissionCountryMapping::create([
            'mfa_mission_id' => $this->mission->id,
            'country_code' => 'CA',
            'country_name' => 'Canada',
        ]);

        $response = $this->actingAs($this->mfaAdmin, 'sanctum')
            ->deleteJson("/api/admin/missions/{$this->mission->id}/countries/{$mapping->id}");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Country mapping removed successfully',
            ]);

        $this->assertDatabaseMissing('mission_country_mappings', [
            'id' => $mapping->id,
        ]);
    }

    /** @test */
    public function mfa_admin_can_assign_officer_to_mission()
    {
        $officer = User::factory()->create([
            'role' => 'mfa_reviewer',
            'agency' => 'mfa',
            'mfa_mission_id' => null,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->mfaAdmin, 'sanctum')
            ->postJson("/api/admin/missions/{$this->mission->id}/officers", [
                'user_id' => $officer->id,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Officer assigned to mission successfully',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $officer->id,
            'mfa_mission_id' => $this->mission->id,
        ]);
    }

    /** @test */
    public function cannot_assign_non_mfa_officer_to_mission()
    {
        $gisOfficer = User::factory()->create([
            'role' => 'gis_reviewer',
            'agency' => 'gis',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->mfaAdmin, 'sanctum')
            ->postJson("/api/admin/missions/{$this->mission->id}/officers", [
                'user_id' => $gisOfficer->id,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'User must be an MFA officer to be assigned to a mission',
            ]);
    }

    /** @test */
    public function cannot_assign_officer_already_assigned_to_another_mission()
    {
        $otherMission = MfaMission::create([
            'code' => 'LONDON',
            'name' => 'London Mission',
            'city' => 'London',
            'country_code' => 'GB',
            'country_name' => 'United Kingdom',
            'mission_type' => 'embassy',
            'is_active' => true,
        ]);

        $officer = User::factory()->create([
            'role' => 'mfa_reviewer',
            'agency' => 'mfa',
            'mfa_mission_id' => $otherMission->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->mfaAdmin, 'sanctum')
            ->postJson("/api/admin/missions/{$this->mission->id}/officers", [
                'user_id' => $officer->id,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Officer is already assigned to another mission',
            ]);
    }

    /** @test */
    public function mfa_admin_can_remove_officer_from_mission()
    {
        $officer = User::factory()->create([
            'role' => 'mfa_reviewer',
            'agency' => 'mfa',
            'mfa_mission_id' => $this->mission->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->mfaAdmin, 'sanctum')
            ->deleteJson("/api/admin/missions/{$this->mission->id}/officers/{$officer->id}");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Officer removed from mission successfully',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $officer->id,
            'mfa_mission_id' => null,
        ]);
    }

    /** @test */
    public function cannot_remove_officer_with_active_applications()
    {
        $officer = User::factory()->create([
            'role' => 'mfa_reviewer',
            'agency' => 'mfa',
            'mfa_mission_id' => $this->mission->id,
            'is_active' => true,
        ]);

        // Create active application assigned to this officer
        Application::factory()->create([
            'reviewing_officer_id' => $officer->id,
            'status' => 'under_review',
        ]);

        $response = $this->actingAs($this->mfaAdmin, 'sanctum')
            ->deleteJson("/api/admin/missions/{$this->mission->id}/officers/{$officer->id}");

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Cannot remove officer with active applications',
            ]);
    }

    /** @test */
    public function mfa_admin_can_get_mission_statistics()
    {
        // Create some test data
        $officer = User::factory()->create([
            'role' => 'mfa_reviewer',
            'mfa_mission_id' => $this->mission->id,
            'is_active' => true,
        ]);

        Application::factory()->create([
            'owner_mission_id' => $this->mission->id,
            'status' => 'under_review',
            'current_queue' => 'review_queue',
        ]);

        Application::factory()->create([
            'owner_mission_id' => $this->mission->id,
            'status' => 'approved',
            'decided_at' => now(),
        ]);

        $response = $this->actingAs($this->mfaAdmin, 'sanctum')
            ->getJson("/api/admin/missions/{$this->mission->id}/statistics");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'statistics' => [
                    'total_officers',
                    'total_applications',
                    'pending_review',
                    'pending_approval',
                    'approved_this_month',
                    'denied_this_month',
                    'countries_covered',
                ],
            ]);
    }

    /** @test */
    public function mfa_admin_can_get_available_officers()
    {
        // Create unassigned MFA officers (not admins, as they're typically pre-assigned)
        User::factory()->count(3)->create([
            'role' => 'mfa_reviewer',
            'agency' => 'mfa',
            'mfa_mission_id' => null,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->mfaAdmin, 'sanctum')
            ->getJson('/api/admin/missions/available-officers');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'officers' => [
                    '*' => [
                        'id',
                        'name',
                        'email',
                        'role',
                    ],
                ],
            ]);
        
        // Should have at least 3 officers (the ones we created)
        $this->assertGreaterThanOrEqual(3, count($response->json('officers')));
    }

    /** @test */
    public function gis_admin_cannot_access_mission_management()
    {
        $response = $this->actingAs($this->gisAdmin, 'sanctum')
            ->getJson('/api/admin/missions');

        $response->assertStatus(403);
    }

    /** @test */
    public function unauthenticated_user_cannot_access_mission_management()
    {
        $response = $this->getJson('/api/admin/missions');

        $response->assertStatus(401);
    }
}
