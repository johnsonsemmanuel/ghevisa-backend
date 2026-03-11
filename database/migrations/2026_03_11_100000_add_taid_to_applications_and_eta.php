<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add TAID (Travel Authorization ID) to applications and eta_applications.
     * 
     * TAID is the internal master ID that links all authorization records.
     * Format: TAID-YYYYMMDD-XXXXXX
     * 
     * This serves as the central identifier across:
     * - ETA applications
     * - Visa applications
     * - Border crossing records
     * - Audit logs
     * - Future entry records
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('taid', 50)->nullable()->unique()->after('reference_number');
            $table->index('taid');
        });

        Schema::table('eta_applications', function (Blueprint $table) {
            $table->string('taid', 50)->nullable()->unique()->after('reference_number');
            $table->index('taid');
        });

        Schema::table('border_crossings', function (Blueprint $table) {
            $table->string('taid', 50)->nullable()->after('application_id');
            $table->index('taid');
        });

        Schema::table('boarding_authorizations', function (Blueprint $table) {
            $table->string('taid', 50)->nullable()->after('authorization_code');
            $table->index('taid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex(['taid']);
            $table->dropColumn('taid');
        });

        Schema::table('eta_applications', function (Blueprint $table) {
            $table->dropIndex(['taid']);
            $table->dropColumn('taid');
        });

        Schema::table('border_crossings', function (Blueprint $table) {
            $table->dropIndex(['taid']);
            $table->dropColumn('taid');
        });

        Schema::table('boarding_authorizations', function (Blueprint $table) {
            $table->dropIndex(['taid']);
            $table->dropColumn('taid');
        });
    }
};
