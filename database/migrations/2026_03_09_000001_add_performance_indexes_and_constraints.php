<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Performance indexes for applications table
        Schema::table('applications', function (Blueprint $table) {
            if (!Schema::hasIndex('applications', 'applications_status_index')) {
                $table->index('status');
            }
            if (!Schema::hasIndex('applications', 'applications_assigned_agency_index')) {
                $table->index('assigned_agency');
            }
            if (!Schema::hasIndex('applications', 'applications_current_queue_index')) {
                $table->index('current_queue');
            }
            if (!Schema::hasIndex('applications', 'applications_tier_index')) {
                $table->index('tier');
            }
            if (!Schema::hasIndex('applications', 'applications_risk_level_index')) {
                $table->index('risk_level');
            }
            if (!Schema::hasIndex('applications', 'applications_status_assigned_agency_index')) {
                $table->index(['status', 'assigned_agency']);
            }
            if (!Schema::hasIndex('applications', 'applications_status_current_queue_index')) {
                $table->index(['status', 'current_queue']);
            }
            if (!Schema::hasIndex('applications', 'applications_submitted_at_index')) {
                $table->index('submitted_at');
            }
            if (!Schema::hasIndex('applications', 'applications_decided_at_index')) {
                $table->index('decided_at');
            }
            if (!Schema::hasIndex('applications', 'applications_sla_deadline_index')) {
                $table->index('sla_deadline');
            }
            if (!Schema::hasIndex('applications', 'applications_assigned_officer_id_index')) {
                $table->index('assigned_officer_id');
            }
            if (!Schema::hasIndex('applications', 'applications_reviewing_officer_id_index')) {
                $table->index('reviewing_officer_id');
            }
        });

        // Performance indexes for payments table
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasIndex('payments', 'payments_status_index')) {
                $table->index('status');
            }
            if (!Schema::hasIndex('payments', 'payments_payment_provider_index')) {
                $table->index('payment_provider');
            }
            if (!Schema::hasIndex('payments', 'payments_transaction_reference_index')) {
                $table->index('transaction_reference');
            }
            if (!Schema::hasIndex('payments', 'payments_application_id_status_index')) {
                $table->index(['application_id', 'status']);
            }
        });

        // Performance indexes for application_status_histories table
        Schema::table('application_status_histories', function (Blueprint $table) {
            if (!Schema::hasIndex('application_status_histories', 'application_status_histories_created_at_index')) {
                $table->index('created_at');
            }
            if (!Schema::hasIndex('application_status_histories', 'application_status_histories_application_id_created_at_index')) {
                $table->index(['application_id', 'created_at']);
            }
        });

        // Performance indexes for border_crossings table
        Schema::table('border_crossings', function (Blueprint $table) {
            if (!Schema::hasIndex('border_crossings', 'border_crossings_crossed_at_index')) {
                $table->index('crossed_at');
            }
            if (!Schema::hasIndex('border_crossings', 'border_crossings_port_of_entry_index')) {
                $table->index('port_of_entry');
            }
            if (!Schema::hasIndex('border_crossings', 'border_crossings_verification_status_index')) {
                $table->index('verification_status');
            }
            if (!Schema::hasIndex('border_crossings', 'border_crossings_crossed_at_port_of_entry_index')) {
                $table->index(['crossed_at', 'port_of_entry']);
            }
            if (!Schema::hasIndex('border_crossings', 'border_crossings_officer_id_index')) {
                $table->index('officer_id');
            }
        });

        // Performance indexes for internal_notes table
        Schema::table('internal_notes', function (Blueprint $table) {
            if (!Schema::hasIndex('internal_notes', 'internal_notes_created_at_index')) {
                $table->index('created_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['assigned_agency']);
            $table->dropIndex(['current_queue']);
            $table->dropIndex(['tier']);
            $table->dropIndex(['risk_level']);
            $table->dropIndex(['status', 'assigned_agency']);
            $table->dropIndex(['status', 'current_queue']);
            $table->dropIndex(['submitted_at']);
            $table->dropIndex(['decided_at']);
            $table->dropIndex(['sla_deadline']);
            $table->dropIndex(['assigned_officer_id']);
            $table->dropIndex(['reviewing_officer_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['payment_provider']);
            $table->dropIndex(['transaction_reference']);
            $table->dropIndex(['application_id', 'status']);
        });

        Schema::table('application_status_histories', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['application_id', 'created_at']);
        });

        Schema::table('border_crossings', function (Blueprint $table) {
            $table->dropIndex(['crossed_at']);
            $table->dropIndex(['port_of_entry']);
            $table->dropIndex(['verification_status']);
            $table->dropIndex(['crossed_at', 'port_of_entry']);
            $table->dropIndex(['officer_id']);
        });

        Schema::table('internal_notes', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
