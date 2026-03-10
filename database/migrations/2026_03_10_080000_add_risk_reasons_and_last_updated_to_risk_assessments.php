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
        Schema::table('risk_assessments', function (Blueprint $table) {
            // Add risk_reasons JSON field if it doesn't exist
            if (!Schema::hasColumn('risk_assessments', 'risk_reasons')) {
                $table->json('risk_reasons')->nullable()->after('factors');
            }
            
            // Add risk_last_updated timestamp if it doesn't exist
            if (!Schema::hasColumn('risk_assessments', 'risk_last_updated')) {
                $table->timestamp('risk_last_updated')->nullable()->after('assessed_at');
            }
            
            // Add index for risk_last_updated for performance
            $table->index('risk_last_updated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('risk_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('risk_assessments', 'risk_reasons')) {
                $table->dropColumn('risk_reasons');
            }
            if (Schema::hasColumn('risk_assessments', 'risk_last_updated')) {
                $table->dropIndex(['risk_last_updated']);
                $table->dropColumn('risk_last_updated');
            }
        });
    }
};
