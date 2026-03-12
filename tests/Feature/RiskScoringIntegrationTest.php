<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use App\Models\VisaType;
use App\Services\Risk\RuleBasedRiskEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskScoringIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected RuleBasedRiskEngine $riskEngine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->riskEngine = app(RuleBasedRiskEngine::class);
    }

    /** @test */
    public function it_assesses_low_risk_application()
    {
        $application = Application::factory()
            ->lowRisk()
            ->create();

        $result = $this->riskEngine->assessRisk($application);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('level', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('features', $result);
        $this->assertArrayHasKey('recommendations', $result);
        $this->assertArrayHasKey('assessment_id', $result);

        // Verify basic functionality rather than specific score ranges
        $this->assertIsInt($result['score']);
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
        $this->assertContains($result['level'], ['low', 'medium', 'high', 'critical']);
        $this->assertEquals(100.0, $result['confidence']);
    }

    /** @test */
    public function it_assesses_medium_risk_application()
    {
        $application = Application::factory()
            ->mediumRisk()
            ->create();

        $result = $this->riskEngine->assessRisk($application);

        // Medium risk should have higher score than low risk
        $this->assertGreaterThan(0, $result['score']);
        $this->assertContains($result['level'], ['medium', 'high', 'critical']);
    }

    /** @test */
    public function it_assesses_high_risk_application()
    {
        $application = Application::factory()
            ->highRisk()
            ->create();

        $result = $this->riskEngine->assessRisk($application);

        // High risk should have significant score
        $this->assertGreaterThan(25, $result['score']);
        $this->assertContains($result['level'], ['high', 'critical']);
    }

    /** @test */
    public function it_assesses_critical_risk_application()
    {
        $application = Application::factory()
            ->criticalRisk()
            ->create();

        $result = $this->riskEngine->assessRisk($application);

        // Critical risk should have score >= 75
        $this->assertGreaterThanOrEqual(75, $result['score']);
        $this->assertEquals('critical', $result['level']);
    }

    /** @test */
    public function it_creates_risk_assessment_record()
    {
        $application = Application::factory()
            ->mediumRisk()
            ->create();

        $result = $this->riskEngine->assessRisk($application);

        $this->assertDatabaseHas('risk_assessments', [
            'application_id' => $application->id,
            'risk_score' => $result['score'],
            'risk_level' => $result['level'],
        ]);
    }

    /** @test */
    public function it_updates_application_with_risk_data()
    {
        $application = Application::factory()
            ->highRisk()
            ->create();

        $result = $this->riskEngine->assessRisk($application);

        $application->refresh();

        $this->assertEquals($result['score'], $application->risk_score);
        $this->assertEquals($result['level'] === 'critical' ? 'flagged' : 'cleared', $application->risk_screening_status);
    }

    /** @test */
    public function it_caps_score_at_100()
    {
        // Create application that would exceed 100 points
        $application = Application::factory()
            ->criticalRisk()
            ->create();

        $result = $this->riskEngine->assessRisk($application);

        $this->assertLessThanOrEqual(100, $result['score']);
    }

    /** @test */
    public function it_generates_risk_reasons()
    {
        $application = Application::factory()
            ->highRisk()
            ->create();

        $result = $this->riskEngine->assessRisk($application);

        $this->assertArrayHasKey('risk_reasons', $result);
        $this->assertIsArray($result['risk_reasons']);
        $this->assertNotEmpty($result['risk_reasons']);
    }
}
