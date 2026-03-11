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
        // Copy any existing risk data from applications to risk_assessments before removing
        // This ensures we don't lose data if any applications have risk data set directly
        $now = now();
        \DB::statement("
            INSERT INTO risk_assessments (application_id, risk_level, assessed_at, created_at, updated_at, risk_last_updated)
            SELECT 
                id, 
                risk_level, 
                risk_assessed_at, 
                ?, 
                ?,
                risk_assessed_at
            FROM applications 
            WHERE risk_level IS NOT NULL 
            AND id NOT IN (SELECT application_id FROM risk_assessments WHERE application_id IS NOT NULL)
        ", [$now, $now]);

        // Remove redundant risk fields from applications table
        Schema::table('applications', function (Blueprint $table) {
            // Drop indexes first if they exist
            try {
                $table->dropIndex('applications_risk_level_index');
            } catch (\Exception $e) {
                // Index might not exist, continue
            }
            
            if (Schema::hasColumn('applications', 'risk_level')) {
                $table->dropColumn('risk_level');
            }
            if (Schema::hasColumn('applications', 'risk_assessed_at')) {
                $table->dropColumn('risk_assessed_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add back the columns if we need to rollback
        Schema::table('applications', function (Blueprint $table) {
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])->nullable()->after('risk_screening_status');
            $table->timestamp('risk_assessed_at')->nullable()->after('risk_level');
        });
    }
};
