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
        Schema::create('alert_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained()->onDelete('cascade');
            $table->timestamp('triggered_at')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->enum('severity', ['info', 'warning', 'high', 'critical'])->index();
            $table->decimal('metric_value', 10, 2);
            $table->text('message');
            $table->json('channels_sent');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            
            // Indexes for common queries
            $table->index(['alert_rule_id', 'triggered_at']);
            $table->index(['triggered_at', 'severity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_logs');
    }
};
