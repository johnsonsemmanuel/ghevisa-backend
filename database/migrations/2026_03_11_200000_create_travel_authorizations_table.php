<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the central travel_authorizations table.
     * 
     * This is the master TAID record table as specified in the official specification.
     * It serves as the root reference for all travel permissions (ETA and Visa).
     * 
     * TAID Format: GH-TA-YYYYMMDD-XXXX
     * Example: GH-TA-20260311-4F82
     */
    public function up(): void
    {
        Schema::create('travel_authorizations', function (Blueprint $table) {
            $table->string('taid', 50)->primary();
            $table->string('passport_number_encrypted');
            $table->string('nationality', 3);
            $table->enum('authorization_type', ['ETA', 'VISA']);
            $table->string('status')->default('active');
            $table->timestamps();
            
            // Indexes for performance (with shortened names to avoid MySQL 64-char limit)
            $table->index(['passport_number_encrypted', 'nationality'], 'idx_travel_auth_passport_nat');
            $table->index('authorization_type', 'idx_travel_auth_type');
            $table->index('status', 'idx_travel_auth_status');
            $table->index('created_at', 'idx_travel_auth_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('travel_authorizations');
    }
};
