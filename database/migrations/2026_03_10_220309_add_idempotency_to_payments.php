<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * CRITICAL SECURITY FIX: Add idempotency to payments.
     * 
     * Problem: Without idempotency, duplicate payment requests can cause:
     * - Double charging applicants
     * - Multiple payment records for same transaction
     * - Financial reconciliation nightmares
     * - Applicant complaints and refund requests
     * 
     * Real-world scenario:
     * 1. Applicant clicks "Pay Now"
     * 2. Network is slow, they click again
     * 3. Two payment requests sent
     * 4. Applicant charged twice
     * 5. Complaint filed, manual refund needed
     * 6. Finance team spends hours reconciling
     * 
     * Solution: Idempotency key ensures same request = same result.
     * If payment already initiated with same key, return existing payment.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Idempotency key: unique identifier for each payment attempt
            // Format: {user_id}_{application_id}_{timestamp}_{random}
            if (!Schema::hasColumn('payments', 'idempotency_key')) {
                $table->string('idempotency_key', 100)->nullable()->after('id');
                $table->unique('idempotency_key');
            }
            
            // Track retry attempts
            if (!Schema::hasColumn('payments', 'retry_count')) {
                $table->integer('retry_count')->default(0)->after('status');
            }
            
            // Track last retry timestamp
            if (!Schema::hasColumn('payments', 'last_retry_at')) {
                $table->timestamp('last_retry_at')->nullable()->after('retry_count');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'retry_count', 'last_retry_at']);
        });
    }
};
