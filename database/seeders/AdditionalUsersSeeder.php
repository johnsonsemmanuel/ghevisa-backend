<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdditionalUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Border Officer
        User::firstOrCreate(
            ['email' => 'border@ghevisa.gov.gh'],
            [
                'first_name' => 'Border',
                'last_name' => 'Officer',
                'password' => Hash::make('password'),
                'role' => 'border_officer',
                'agency' => 'GIS',
                'is_active' => true,
                'locale' => 'en',
                'email_verified_at' => now(),
            ]
        );

        // Airline Staff
        User::firstOrCreate(
            ['email' => 'airline@example.com'],
            [
                'first_name' => 'Airline',
                'last_name' => 'Staff',
                'password' => Hash::make('password'),
                'role' => 'airline_staff',
                'agency' => 'AIRLINE',
                'is_active' => true,
                'locale' => 'en',
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('✅ Additional users created successfully!');
    }
}
