<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FIX #10: Add note that OCR is not yet implemented.
     * 
     * SECURITY: Prevents officers from trusting fake "verified" status.
     * All documents must be manually reviewed until OCR service is deployed.
     */
    public function up(): void
    {
        // SQLite doesn't support MODIFY COLUMN, so we just clear the data
        // Set all existing ocr_status to NULL to clear any fake data
        DB::table('application_documents')->update([
            'ocr_status' => null,
            'ocr_result' => null,
        ]);
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        // No-op for SQLite
    }
};
