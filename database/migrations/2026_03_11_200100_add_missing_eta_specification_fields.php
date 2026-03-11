<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add missing ETA fields as per official specification.
     * 
     * The specification requires:
     * - valid_from: Issue date
     * - valid_until: valid_from + 90 days
     * - entry_date: Populated when traveler enters Ghana
     * - port_of_entry: Populated on entry confirmation
     */
    public function up(): void
    {
        Schema::table('eta_applications', function (Blueprint $table) {
            // Add valid_from and valid_until for ETA validity tracking
            $table->timestamp('valid_from')->nullable()->after('approved_at');
            $table->timestamp('valid_until')->nullable()->after('valid_from');
            
            // Note: entry_date and port_of_entry_used already exist from previous migration
            // (2026_03_11_000002_add_entry_consumption_to_eta_applications.php)
            
            // Add index for validity queries
            $table->index(['valid_until', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('eta_applications', function (Blueprint $table) {
            $table->dropIndex(['valid_until', 'status']);
            $table->dropColumn(['valid_from', 'valid_until']);
        });
    }
};
