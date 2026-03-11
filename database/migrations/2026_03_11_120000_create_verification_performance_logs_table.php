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
        Schema::create('verification_performance_logs', function (Blueprint $table) {
            $table->id();
            $table->string('verification_type', 50)->index(); // ETA, VISA, TAID, PASSPORT
            $table->decimal('response_time', 8, 3); // Response time in seconds
            $table->boolean('success')->index();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('created_at')->index();
            
            // Indexes for performance
            $table->index(['created_at', 'success']);
            $table->index(['created_at', 'response_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verification_performance_logs');
    }
};
