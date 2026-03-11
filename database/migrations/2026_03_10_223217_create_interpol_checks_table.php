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
        Schema::create('interpol_checks', function (Blueprint $table) {
            $table->id();
            $table->string('unique_reference_id')->index();
            $table->string('first_name');
            $table->string('surname');
            $table->date('date_of_birth');
            $table->enum('interpol_nominal_matched', ['Yes', 'No']);
            $table->timestamp('checked_at');
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interpol_checks');
    }
};
