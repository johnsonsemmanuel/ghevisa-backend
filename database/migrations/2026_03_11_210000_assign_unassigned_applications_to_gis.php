<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Fix: Assign applications that are in review statuses but don't have an assigned_agency.
     * This ensures they appear in the GIS case queue.
     */
    public function up(): void
    {
        // Assign applications in review statuses to GIS if they don't have an agency assigned
        DB::table('applications')
            ->whereIn('status', [
                'submitted',
                'under_review',
                'pending_approval',
                'additional_info_requested',
                'escalated',
            ])
            ->where(function ($query) {
                $query->whereNull('assigned_agency')
                      ->orWhere('assigned_agency', '');
            })
            ->update([
                'assigned_agency' => 'gis',
                'current_queue' => DB::raw("
                    CASE 
                        WHEN status = 'pending_approval' THEN 'approval_queue'
                        WHEN status IN ('submitted', 'under_review', 'additional_info_requested') THEN 'review_queue'
                        ELSE current_queue
                    END
                "),
                'updated_at' => now(),
            ]);

        // Log the fix
        $count = DB::table('applications')
            ->where('assigned_agency', 'gis')
            ->whereIn('status', [
                'submitted',
                'under_review',
                'pending_approval',
                'additional_info_requested',
                'escalated',
            ])
            ->count();

        \Log::info("Migration: Assigned {$count} applications to GIS agency");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // We don't reverse this as it's a data fix
        // Applications should remain assigned to GIS
    }
};
