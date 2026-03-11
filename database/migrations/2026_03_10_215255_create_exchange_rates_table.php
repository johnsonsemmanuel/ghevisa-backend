<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * CRITICAL SECURITY FIX: Exchange rate audit trail table.
     * 
     * Stores historical exchange rates for:
     * - Financial audit compliance
     * - Rate change tracking
     * - Fallback when APIs fail
     * - Dispute resolution
     */
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('from_currency', 3); // USD, GHS, EUR, GBP
            $table->string('to_currency', 3);
            $table->decimal('rate', 12, 6); // Up to 6 decimal places for precision
            $table->string('source', 50); // bank_of_ghana, exchangerate_api, manual
            $table->timestamp('fetched_at'); // When rate was fetched
            $table->timestamps();

            // Indexes for fast lookups
            $table->index(['from_currency', 'to_currency', 'fetched_at']);
            $table->index('fetched_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
