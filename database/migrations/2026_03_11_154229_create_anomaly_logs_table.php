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
        Schema::create('anomaly_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 100)->index();
            $table->enum('severity', ['info', 'medium', 'high', 'critical'])->index();
            $table->text('message');
            $table->json('details')->nullable();
            $table->timestamp('detected_at')->index();
            $table->timestamps();
            
            // Indexes for common queries
            $table->index(['detected_at', 'severity']);
            $table->index(['type', 'detected_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anomaly_logs');
    }
};
