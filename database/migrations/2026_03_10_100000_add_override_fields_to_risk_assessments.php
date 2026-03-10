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
            $table->boolean('override_flag')->default(false)->after('notes');
            $table->text('override_note')->nullable()->after('override_flag');
            $table->foreignId('override_by_id')->nullable()->constrained('users')->nullOnDelete()->after('assessed_by_id');
            $table->timestamp('override_timestamp')->nullable()->after('override_by_id');
            
            $table->index('override_flag');
            $table->index('override_by_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('risk_assessments', function (Blueprint $table) {
            $table->dropIndex(['override_flag']);
            $table->dropIndex(['override_by_id']);
            $table->dropForeign(['override_by_id']);
            $table->dropColumn(['override_flag', 'override_note', 'override_by_id', 'override_timestamp']);
        });
    }
};
