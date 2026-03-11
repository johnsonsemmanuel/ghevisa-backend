<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('merchant_ref', 20)->nullable()->after('id');
            $table->string('checkout_id')->nullable()->after('merchant_ref');
            $table->string('checkout_url', 500)->nullable()->after('checkout_id');
            $table->string('bank_ref')->nullable()->after('transaction_reference');
            $table->string('payment_option')->nullable()->after('payment_provider');
            $table->string('gateway')->default('gcb')->after('status');
            $table->json('gateway_response')->nullable()->after('gateway');
            $table->timestamp('completed_at')->nullable()->after('gateway_response');
            
            $table->index('merchant_ref');
            $table->index('checkout_id');
            $table->index('bank_ref');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['merchant_ref']);
            $table->dropIndex(['checkout_id']);
            $table->dropIndex(['bank_ref']);
            $table->dropColumn([
                'merchant_ref',
                'checkout_id', 
                'checkout_url',
                'bank_ref',
                'payment_option',
                'gateway',
                'gateway_response',
                'completed_at',
            ]);
        });
    }
};
