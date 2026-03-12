<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * SECURITY FIX: Add fields for automated security checks
     * - Interpol check tracking
     * - Duplicate passport detection
     * - Identity verification status
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // Interpol check fields
            $table->timestamp('interpol_check_triggered_at')->nullable()->after('submitted_at');
            $table->string('interpol_check_status')->nullable()->after('interpol_check_triggered_at')
                ->comment('pending, completed, failed, manual_required');
            $table->boolean('requires_manual_interpol_check')->default(false)->after('interpol_check_status');
            
            // Identity verification fields (for future SumSub/Onfido integration)
            $table->string('identity_verification_id')->nullable()->after('requires_manual_interpol_check')
                ->comment('External ID from identity verification provider');
            $table->string('identity_verification_status')->nullable()->after('identity_verification_id')
                ->comment('pending, verified, rejected, manual_review');
            $table->timestamp('identity_verified_at')->nullable()->after('identity_verification_status');
            
            // MRZ validation fields
            $table->boolean('mrz_validated')->default(false)->after('identity_verified_at');
            $table->json('mrz_data')->nullable()->after('mrz_validated')
                ->comment('Parsed MRZ data from passport');
            
            // Add indexes for performance
            $table->index('interpol_check_status');
            $table->index('identity_verification_status');
            $table->index('requires_manual_interpol_check');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex(['interpol_check_status']);
            $table->dropIndex(['identity_verification_status']);
            $table->dropIndex(['requires_manual_interpol_check']);
            
            $table->dropColumn([
                'interpol_check_triggered_at',
                'interpol_check_status',
                'requires_manual_interpol_check',
                'identity_verification_id',
                'identity_verification_status',
                'identity_verified_at',
                'mrz_validated',
                'mrz_data',
            ]);
        });
    }
};
