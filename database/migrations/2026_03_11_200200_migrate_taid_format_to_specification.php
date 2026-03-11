<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Migrate existing TAID records from old format (TAID-*) to new format (GH-TA-*)
     * and populate the central travel_authorizations table.
     */
    public function up(): void
    {
        Log::info('Starting TAID format migration');
        
        $migratedApplications = 0;
        $migratedEtas = 0;
        $errors = 0;

        // Migrate applications table
        DB::table('applications')
            ->whereNotNull('taid')
            ->where('taid', 'like', 'TAID-%')
            ->orderBy('id')
            ->chunk(100, function ($applications) use (&$migratedApplications, &$errors) {
                foreach ($applications as $app) {
                    try {
                        // Convert TAID-YYYYMMDD-XXXXXX to GH-TA-YYYYMMDD-XXXX
                        $oldTaid = $app->taid;
                        $parts = explode('-', $oldTaid);
                        
                        if (count($parts) >= 3) {
                            $date = $parts[1];
                            $suffix = substr($parts[2], 0, 4); // Take first 4 chars
                            $newTaid = "GH-TA-{$date}-{$suffix}";
                            
                            // Check if new TAID already exists
                            $exists = DB::table('travel_authorizations')->where('taid', $newTaid)->exists();
                            if ($exists) {
                                // Generate unique suffix
                                $suffix = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));
                                $newTaid = "GH-TA-{$date}-{$suffix}";
                            }
                            
                            // Create travel_authorizations record
                            DB::table('travel_authorizations')->insert([
                                'taid' => $newTaid,
                                'passport_number_encrypted' => $app->passport_number_encrypted,
                                'nationality' => $app->nationality_encrypted ?? $app->nationality ?? 'XX',
                                'authorization_type' => 'VISA',
                                'status' => 'active',
                                'created_at' => $app->created_at,
                                'updated_at' => $app->updated_at,
                            ]);
                            
                            // Update application with new TAID
                            DB::table('applications')
                                ->where('id', $app->id)
                                ->update(['taid' => $newTaid]);
                            
                            $migratedApplications++;
                        }
                    } catch (\Exception $e) {
                        Log::error('Failed to migrate application TAID', [
                            'id' => $app->id,
                            'old_taid' => $app->taid,
                            'error' => $e->getMessage(),
                        ]);
                        $errors++;
                    }
                }
            });

        // Migrate eta_applications table
        DB::table('eta_applications')
            ->whereNotNull('taid')
            ->where('taid', 'like', 'TAID-%')
            ->orderBy('id')
            ->chunk(100, function ($etas) use (&$migratedEtas, &$errors) {
                foreach ($etas as $eta) {
                    try {
                        // Convert TAID-YYYYMMDD-XXXXXX to GH-TA-YYYYMMDD-XXXX
                        $oldTaid = $eta->taid;
                        $parts = explode('-', $oldTaid);
                        
                        if (count($parts) >= 3) {
                            $date = $parts[1];
                            $suffix = substr($parts[2], 0, 4); // Take first 4 chars
                            $newTaid = "GH-TA-{$date}-{$suffix}";
                            
                            // Check if new TAID already exists
                            $exists = DB::table('travel_authorizations')->where('taid', $newTaid)->exists();
                            if ($exists) {
                                // Generate unique suffix
                                $suffix = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));
                                $newTaid = "GH-TA-{$date}-{$suffix}";
                            }
                            
                            // Create travel_authorizations record
                            DB::table('travel_authorizations')->insert([
                                'taid' => $newTaid,
                                'passport_number_encrypted' => $eta->passport_number_encrypted,
                                'nationality' => $eta->nationality_encrypted ?? $eta->nationality ?? 'XX',
                                'authorization_type' => 'ETA',
                                'status' => 'active',
                                'created_at' => $eta->created_at,
                                'updated_at' => $eta->updated_at,
                            ]);
                            
                            // Update ETA with new TAID
                            DB::table('eta_applications')
                                ->where('id', $eta->id)
                                ->update(['taid' => $newTaid]);
                            
                            $migratedEtas++;
                        }
                    } catch (\Exception $e) {
                        Log::error('Failed to migrate ETA TAID', [
                            'id' => $eta->id,
                            'old_taid' => $eta->taid,
                            'error' => $e->getMessage(),
                        ]);
                        $errors++;
                    }
                }
            });

        // Update border_crossings table
        DB::table('border_crossings')
            ->whereNotNull('taid')
            ->where('taid', 'like', 'TAID-%')
            ->orderBy('id')
            ->chunk(100, function ($crossings) {
                foreach ($crossings as $crossing) {
                    $oldTaid = $crossing->taid;
                    $parts = explode('-', $oldTaid);
                    
                    if (count($parts) >= 3) {
                        $date = $parts[1];
                        $suffix = substr($parts[2], 0, 4);
                        $newTaid = "GH-TA-{$date}-{$suffix}";
                        
                        DB::table('border_crossings')
                            ->where('id', $crossing->id)
                            ->update(['taid' => $newTaid]);
                    }
                }
            });

        // Update boarding_authorizations table
        DB::table('boarding_authorizations')
            ->whereNotNull('taid')
            ->where('taid', 'like', 'TAID-%')
            ->orderBy('id')
            ->chunk(100, function ($authorizations) {
                foreach ($authorizations as $auth) {
                    $oldTaid = $auth->taid;
                    $parts = explode('-', $oldTaid);
                    
                    if (count($parts) >= 3) {
                        $date = $parts[1];
                        $suffix = substr($parts[2], 0, 4);
                        $newTaid = "GH-TA-{$date}-{$suffix}";
                        
                        DB::table('boarding_authorizations')
                            ->where('id', $auth->id)
                            ->update(['taid' => $newTaid]);
                    }
                }
            });

        Log::info('TAID format migration completed', [
            'applications_migrated' => $migratedApplications,
            'etas_migrated' => $migratedEtas,
            'errors' => $errors,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Log::warning('Reversing TAID format migration - converting back to old format');
        
        // Convert back from GH-TA-* to TAID-*
        DB::table('applications')
            ->where('taid', 'like', 'GH-TA-%')
            ->orderBy('id')
            ->chunk(100, function ($applications) {
                foreach ($applications as $app) {
                    $newTaid = $app->taid;
                    $oldTaid = str_replace('GH-TA-', 'TAID-', $newTaid);
                    
                    DB::table('applications')
                        ->where('id', $app->id)
                        ->update(['taid' => $oldTaid]);
                }
            });

        DB::table('eta_applications')
            ->where('taid', 'like', 'GH-TA-%')
            ->orderBy('id')
            ->chunk(100, function ($etas) {
                foreach ($etas as $eta) {
                    $newTaid = $eta->taid;
                    $oldTaid = str_replace('GH-TA-', 'TAID-', $newTaid);
                    
                    DB::table('eta_applications')
                        ->where('id', $eta->id)
                        ->update(['taid' => $oldTaid]);
                }
            });
    }
};
