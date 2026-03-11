<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('boarding_authorizations', function (Blueprint $table) {
            $table->id();
            $table->string('authorization_code', 50)->unique();
            $table->string('passport_number_encrypted');
            $table->string('nationality', 3);
            $table->enum('authorization_type', ['ETA', 'VISA']);
            $table->string('eta_number')->nullable();
            $table->foreignId('visa_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->timestamp('verification_timestamp');
            $table->timestamp('expiry_timestamp');
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
            
            // Custom index names to avoid MySQL 64-char limit
            $table->index(['authorization_code', 'expiry_timestamp'], 'idx_boarding_auth_code_expiry');
            $table->index('verification_timestamp', 'idx_boarding_verification_ts');
            $table->index(['passport_number_encrypted', 'authorization_type'], 'idx_boarding_passport_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('boarding_authorizations');
    }
};
