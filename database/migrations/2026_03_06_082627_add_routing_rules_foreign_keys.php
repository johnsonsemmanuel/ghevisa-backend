<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mfa_missions') && Schema::hasTable('routing_rules')) {
            try {
                Schema::table('routing_rules', function (Blueprint $table) {
                    $table->foreign('mfa_mission_id')
                          ->references('id')
                          ->on('mfa_missions')
                          ->nullOnDelete();
                });
            } catch (\Exception $e) {
                // Ignore if the foreign key already exists or table cannot be referenced
                \Log::warning('Skipped adding foreign key routing_rules_mfa_mission_id_foreign: ' . $e->getMessage());
            }
        }
    }

    public function down(): void
    {
        Schema::table('routing_rules', function (Blueprint $table) {
            $table->dropForeign(['mfa_mission_id']);
        });
    }
};
