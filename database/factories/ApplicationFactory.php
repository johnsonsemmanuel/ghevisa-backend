<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\User;
use App\Models\VisaType;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    public function definition(): array
    {
        $taidService = app(\App\Services\TaidService::class);
        
        return [
            'user_id' => User::factory(),
            'visa_type_id' => VisaType::factory(),
            'reference_number' => 'TEST-' . date('Y') . '-' . str_pad($this->faker->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'taid' => $taidService->generate(),
            'status' => 'draft',
            'first_name_encrypted' => $this->faker->firstName,
            'last_name_encrypted' => $this->faker->lastName,
            'email_encrypted' => $this->faker->email,
            'phone_encrypted' => $this->faker->phoneNumber,
            'passport_number_encrypted' => $this->faker->bothify('??#######'),
            'nationality_encrypted' => $this->faker->country,
            'date_of_birth_encrypted' => $this->faker->date('Y-m-d', '-30 years'),
            'intended_arrival' => $this->faker->dateTimeBetween('+1 week', '+3 months'),
            'duration_days' => $this->faker->numberBetween(7, 90),
            'purpose_of_visit' => $this->faker->randomElement(['Tourism', 'Business', 'Study', 'Medical']),
            'visa_channel' => 'e-visa',
            'tier' => 'tier_1',
            'processing_tier' => 'fast_track',
            'assigned_agency' => null,
            'current_queue' => null,
            'sla_deadline' => null,
            'submitted_at' => null,
            'decided_at' => null,
            'decision_notes' => null,
            'risk_screening_status' => 'pending',
            'assigned_officer_id' => null,
        ];
    }

    public function underReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'under_review',
            'assigned_agency' => 'gis',
            'current_queue' => 'review_queue',
            'sla_deadline' => now()->addHours(72),
            'submitted_at' => now()->subHours(2),
        ]);
    }

    public function pendingApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending_approval',
            'assigned_agency' => 'gis',
            'current_queue' => 'approval_queue',
            'sla_deadline' => now()->addHours(24),
            'submitted_at' => now()->subHours(12),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'assigned_agency' => 'gis',
            'current_queue' => null,
            'sla_deadline' => now()->addHours(24),
            'submitted_at' => now()->subHours(48),
            'decided_at' => now()->subHours(1),
        ]);
    }

    public function issued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'issued',
            'assigned_agency' => 'gis',
            'current_queue' => null,
            'sla_deadline' => now()->addHours(24),
            'submitted_at' => now()->subHours(72),
            'decided_at' => now()->subHours(24),
        ]);
    }

    /**
     * Low-risk application state - no rules triggered.
     */
    public function lowRisk(): static
    {
        return $this->state(fn (array $attributes) => [
            'passport_expiry' => now()->addYears(2), // Valid passport
            'passport_issue_date' => now()->subYears(2), // Not recently issued
            'nationality_encrypted' => 'US', // Not in flagged list
            'duration_days' => 14, // Reasonable duration
            'return_date' => now()->addDays(14), // Has return ticket
            'accommodation_type' => 'hotel', // Has accommodation
            'hotel_name' => 'Accra Beach Hotel',
            'high_risk_travel' => false,
            'entry_denied_before' => false,
            'overstayed_before' => false,
            'visited_ghana_before' => false,
        ]);
    }

    /**
     * Medium-risk application state - some rules triggered (25-49 points).
     */
    public function mediumRisk(): static
    {
        return $this->state(fn (array $attributes) => [
            'passport_expiry' => now()->addMonths(5), // Expires within 6 months (25 points)
            'passport_issue_date' => now()->subYears(1), // Not recently issued
            'nationality_encrypted' => 'US', // Not flagged
            'duration_days' => 30, // Reasonable duration
            'return_date' => null, // Missing return ticket (10 points)
            'accommodation_type' => null, // Missing accommodation (10 points)
            'high_risk_travel' => false,
            'entry_denied_before' => false,
            'overstayed_before' => false,
        ]);
    }

    /**
     * High-risk application state - many rules triggered (50-74 points).
     */
    public function highRisk(): static
    {
        return $this->state(fn (array $attributes) => [
            'passport_expiry' => now()->addMonths(4), // Expires within 6 months (25 points)
            'passport_issue_date' => now()->subMonths(3), // Recently issued (10 points)
            'nationality_encrypted' => 'PK', // Medium-risk nationality (15 points)
            'duration_days' => 90, // Long duration
            'return_date' => null, // Missing return ticket (10 points)
            'accommodation_type' => null, // Missing accommodation (10 points)
            'high_risk_travel' => false, // Don't add more points
            'entry_denied_before' => false, // Don't add more points
            'overstayed_before' => false,
        ]);
    }

    /**
     * Critical-risk application state - severe rules triggered (75+ points).
     */
    public function criticalRisk(): static
    {
        return $this->state(fn (array $attributes) => [
            'passport_expiry' => now()->addMonths(3), // Expires within 6 months (25 points)
            'passport_issue_date' => now()->subMonths(2), // Recently issued (10 points)
            'nationality_encrypted' => 'IR', // High-risk nationality (15 points)
            'duration_days' => 90, // Long duration
            'return_date' => null, // Missing return ticket (10 points)
            'accommodation_type' => null, // Missing accommodation (10 points)
            'high_risk_travel' => false,
            'entry_denied_before' => true, // Prior refusal (20 points)
            'overstayed_before' => false, // Don't add overstay to keep it reasonable
        ]);
    }

    /**
     * Random risk profile for property-based testing.
     */
    public function randomRisk(): static
    {
        return $this->state(fn (array $attributes) => [
            'passport_expiry' => $this->faker->dateTimeBetween('now', '+3 years'),
            'passport_issue_date' => $this->faker->dateTimeBetween('-5 years', 'now'),
            'nationality_encrypted' => $this->faker->randomElement(['US', 'GB', 'NG', 'PK', 'IR', 'SY']),
            'duration_days' => $this->faker->numberBetween(1, 180),
            'return_date' => $this->faker->optional(0.7)->dateTimeBetween('+1 week', '+6 months'),
            'accommodation_type' => $this->faker->optional(0.8)->randomElement(['hotel', 'host', 'rental']),
            'hotel_name' => $this->faker->optional(0.6)->company,
            'high_risk_travel' => $this->faker->boolean(20),
            'entry_denied_before' => $this->faker->boolean(10),
            'overstayed_before' => $this->faker->boolean(5),
        ]);
    }

    /**
     * Application with expired passport.
     */
    public function withExpiredPassport(): static
    {
        return $this->state(fn (array $attributes) => [
            'passport_expiry' => now()->addMonths(3),
            'intended_arrival' => now()->addMonths(1),
        ]);
    }

    /**
     * Application with watchlist match (requires manual setup).
     */
    public function withWatchlistMatch(): static
    {
        return $this->state(fn (array $attributes) => [
            'first_name_encrypted' => 'John',
            'last_name_encrypted' => 'Doe',
            'passport_number_encrypted' => 'WL123456',
        ]);
    }

    /**
     * Application with missing documents.
     */
    public function withMissingDocuments(): static
    {
        return $this->state(fn (array $attributes) => [
            'return_date' => null,
            'accommodation_type' => null,
            'hotel_name' => null,
            'host_name' => null,
        ]);
    }
}
