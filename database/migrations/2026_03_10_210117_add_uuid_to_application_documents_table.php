<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     * SECURITY FIX: Add UUID to prevent IDOR attacks on document downloads
     */
    public function up(): void
    {
        Schema::table('application_documents', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->unique('uuid');
        });

        // Generate UUIDs for existing records
        DB::table('application_documents')->whereNull('uuid')->chunkById(100, function ($documents) {
            foreach ($documents as $document) {
                DB::table('application_documents')
                    ->where('id', $document->id)
                    ->update(['uuid' => (string) Str::uuid()]);
            }
        });

        // Make UUID non-nullable after populating
        Schema::table('application_documents', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_documents', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
