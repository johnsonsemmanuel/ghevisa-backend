<?php

namespace Database\Factories;

use App\Models\EtaApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

class EtaApplicationFactory extends Factory
{
    protected $model = EtaApplication::class;

    public function definition(): array
    {
        $taidService = app(\App\Services\TaidService::class);
        
        return [
            'user_id' => User::factory(),
            'reference_number' => 'GH-ETA-REF-' . date('Ymd') . '-' . strtoupper($this->faker->bothify('???###')),
            'eta_number' => 'GH-ETA-' . date('Ymd') . '-' . strtoupper($this->faker->bothify('????')),
            'taid' => $taidService->generate(),
            'status' => 'approved',
            'first_name_encrypted' => Crypt::encryptString($this->faker->firstName),
            'last_name_encrypted' => Crypt::encryptString($this->faker->lastName),
            'date_of_birth' => $this->faker->date('Y-m-d', '-30 years'),
            'gender' => $this->faker->randomElement(['male', 'female']),
            'nationality_encrypted' => Crypt::encryptString($this->faker->countryCode),
            'passport_number_encrypted' => Crypt::encryptString($this->faker->bothify('??#######')),
            'passport_issue_date' => $this->faker->date('Y-m-d', '-2 years'),
            'passport_expiry_date' => $this->faker->date('Y-m-d', '+2 years'),
            'email_encrypted' => Crypt::encryptString($this->faker->email),
            'phone_encrypted' => Crypt::encryptString($this->faker->phoneNumber),
            'residential_address_encrypted' => Crypt::encryptString($this->faker->address),
            'intended_arrival_date' => $this->faker->dateTimeBetween('+1 week', '+3 months'),
            'port_of_entry' => $this->faker->randomElement(['Kotoka International Airport', 'Tema Port', 'Aflao Border']),
            'address_in_ghana_encrypted' => Crypt::encryptString($this->faker->address),
            'host_name' => $this->faker->name,
            'host_phone' => $this->faker->phoneNumber,
            'denied_entry_before' => false,
            'criminal_conviction' => false,
            'previous_ghana_visa' => $this->faker->boolean(30),
            'validity_days' => 90,
            'entry_type' => 'single',
            'fee_amount' => 100.00,
            'payment_status' => 'completed',
            'approved_at' => now()->subDays(1),
            'expires_at' => now()->addDays(90),
            'valid_from' => now()->subDays(1),
            'valid_until' => now()->addDays(90),
            'entry_consumed' => false,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'approved_at' => null,
            'expires_at' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'approved_at' => now()->subDays(1),
            'expires_at' => now()->addDays(90),
        ]);
    }

    public function denied(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'denied',
            'approved_at' => null,
            'expires_at' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'approved_at' => now()->subDays(100),
            'expires_at' => now()->subDays(1),
        ]);
    }

    public function consumed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'entry_consumed' => true,
        ]);
    }
}
