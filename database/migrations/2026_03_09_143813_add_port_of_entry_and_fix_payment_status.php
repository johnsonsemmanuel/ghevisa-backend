<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add port_of_entry to applications if missing
        if (!Schema::hasColumn('applications', 'port_of_entry')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->string('port_of_entry')->nullable()->after('address_in_ghana');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('applications', 'port_of_entry')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->dropColumn('port_of_entry');
            });
        }
    }
};
