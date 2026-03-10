<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('eta_applications', function (Blueprint $table) {
            if (!Schema::hasColumn('eta_applications', 'passport_issuing_authority')) {
                $table->string('passport_issuing_authority')->nullable()->after('passport_expiry_date');
            }

            if (!Schema::hasColumn('eta_applications', 'passport_verification_status')) {
                $table->string('passport_verification_status')->nullable()->after('expires_at');
            }

            if (!Schema::hasColumn('eta_applications', 'passport_verification_source')) {
                $table->string('passport_verification_source')->nullable()->after('passport_verification_status');
            }

            if (!Schema::hasColumn('eta_applications', 'passport_verification_at')) {
                $table->timestamp('passport_verification_at')->nullable()->after('passport_verification_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('eta_applications', function (Blueprint $table) {
            if (Schema::hasColumn('eta_applications', 'passport_issuing_authority')) {
                $table->dropColumn('passport_issuing_authority');
            }
            if (Schema::hasColumn('eta_applications', 'passport_verification_status')) {
                $table->dropColumn('passport_verification_status');
            }
            if (Schema::hasColumn('eta_applications', 'passport_verification_source')) {
                $table->dropColumn('passport_verification_source');
            }
            if (Schema::hasColumn('eta_applications', 'passport_verification_at')) {
                $table->dropColumn('passport_verification_at');
            }
        });
    }
};

