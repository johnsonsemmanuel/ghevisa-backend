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
        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('entry_consumed')->default(false)->after('status');
            $table->timestamp('entry_date')->nullable()->after('entry_consumed');
            $table->string('port_of_entry_used')->nullable()->after('entry_date');
            $table->foreignId('entry_officer_id')->nullable()->constrained('users')->nullOnDelete()->after('port_of_entry_used');
            
            $table->index(['entry_consumed', 'status']);
            $table->index('entry_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex(['entry_consumed', 'status']);
            $table->dropIndex(['entry_date']);
            $table->dropForeign(['entry_officer_id']);
            $table->dropColumn(['entry_consumed', 'entry_date', 'port_of_entry_used', 'entry_officer_id']);
        });
    }
};
