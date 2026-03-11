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
            $table->string('health_infectious_travel')->nullable()->after('health_condition_details');
            $table->text('health_infectious_countries')->nullable()->after('health_infectious_travel');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn([
                'health_infectious_travel',
                'health_infectious_countries',
            ]);
        });
    }
};
