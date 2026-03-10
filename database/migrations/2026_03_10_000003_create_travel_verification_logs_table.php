<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('travel_verification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('passport_suffix', 8)->nullable();
            $table->string('nationality', 3)->nullable();
            $table->string('user_type', 32)->nullable(); // airline, border, api, etc.
            $table->string('ip_address', 45)->nullable();
            $table->string('status', 32);
            $table->string('authorization_type', 32)->nullable();
            $table->string('eta_number')->nullable();
            $table->string('visa_reference')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_verification_logs');
    }
};

