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
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('metric', 100)->index();
            $table->string('condition', 10);
            $table->decimal('threshold', 10, 2);
            $table->integer('duration_minutes')->default(0);
            $table->enum('severity', ['info', 'warning', 'high', 'critical'])->index();
            $table->json('channels');
            $table->json('recipients');
            $table->integer('cooldown_minutes')->default(30);
            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();
            
            // Indexes for common queries
            $table->index(['enabled', 'metric']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
