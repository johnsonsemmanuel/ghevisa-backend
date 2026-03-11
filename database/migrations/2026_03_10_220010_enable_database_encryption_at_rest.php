<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * CRITICAL SECURITY FIX: Enable database encryption at rest.
     * 
     * Problem: PII stored in database is vulnerable to disk theft.
     * If someone steals the database server or backup drives, they get:
     * - Passport numbers
     * - Names and dates of birth
     * - Addresses and phone numbers
     * - All applicant PII
     * 
     * Real-world impact:
     * - Identity theft at national scale
     * - Privacy violations (GDPR breach)
     * - Government liability
     * - International embarrassment
     * 
     * This migration enables MySQL/MariaDB encryption at rest for all tables
     * containing PII. Requires MySQL 8.0+ or MariaDB 10.1.3+.
     */
    public function up(): void
    {
        // Check if database supports encryption
        $driver = config('database.default');
        $connection = config("database.connections.{$driver}.driver");
        
        if ($connection !== 'mysql') {
            Log::warning('Database encryption at rest: Skipping (not MySQL/MariaDB)', [
                'driver' => $driver,
                'connection' => $connection,
            ]);
            return;
        }

        // Check MySQL version
        $version = DB::selectOne('SELECT VERSION() as version')->version;
        Log::info('Enabling database encryption at rest', [
            'mysql_version' => $version,
        ]);

        // Enable encryption for tables containing PII
        $tables = [
            'users',                    // Email, phone, names
            'applications',             // All applicant PII (encrypted fields)
            'application_documents',    // Document metadata
            'watchlists',              // Watchlist entries (encrypted)
            'payments',                // Payment details
            'audit_logs',              // Audit trail (may contain PII)
            'internal_notes',          // Officer notes (may contain PII)
            'support_tickets',         // Support messages (may contain PII)
            'support_messages',        // Support messages
            'eta_applications',        // ETA applicant data
            'border_crossings',        // Entry/exit records
        ];

        foreach ($tables as $table) {
            try {
                // Check if table exists
                $exists = DB::select("SHOW TABLES LIKE '{$table}'");
                
                if (empty($exists)) {
                    Log::warning("Table does not exist, skipping encryption", ['table' => $table]);
                    continue;
                }

                // Enable encryption for table
                DB::statement("ALTER TABLE {$table} ENCRYPTION='Y'");
                
                Log::info('Table encryption enabled', ['table' => $table]);
            } catch (\Exception $e) {
                // Log error but continue with other tables
                Log::error('Failed to enable encryption for table', [
                    'table' => $table,
                    'error' => $e->getMessage(),
                ]);
                
                // If encryption is not supported, log critical warning
                if (str_contains($e->getMessage(), 'Unknown option')) {
                    Log::critical('Database encryption not supported', [
                        'mysql_version' => $version,
                        'recommendation' => 'Upgrade to MySQL 8.0+ or MariaDB 10.1.3+ for encryption support',
                    ]);
                    break;
                }
            }
        }

        Log::info('Database encryption at rest migration completed');
    }

    /**
     * Reverse the migrations.
     * 
     * WARNING: Disabling encryption exposes PII to disk theft.
     * Only disable in non-production environments for testing.
     */
    public function down(): void
    {
        $driver = config('database.default');
        $connection = config("database.connections.{$driver}.driver");
        
        if ($connection !== 'mysql') {
            return;
        }

        // Only allow disabling encryption in non-production
        if (config('app.env') === 'production') {
            Log::critical('Attempted to disable database encryption in PRODUCTION', [
                'blocked' => true,
            ]);
            throw new \RuntimeException(
                'CRITICAL: Cannot disable database encryption in PRODUCTION. ' .
                'This would expose PII to disk theft.'
            );
        }

        $tables = [
            'users',
            'applications',
            'application_documents',
            'watchlists',
            'payments',
            'audit_logs',
            'internal_notes',
            'support_tickets',
            'support_messages',
            'eta_applications',
            'border_crossings',
        ];

        foreach ($tables as $table) {
            try {
                $exists = DB::select("SHOW TABLES LIKE '{$table}'");
                
                if (!empty($exists)) {
                    DB::statement("ALTER TABLE {$table} ENCRYPTION='N'");
                    Log::warning('Table encryption disabled', ['table' => $table]);
                }
            } catch (\Exception $e) {
                Log::error('Failed to disable encryption for table', [
                    'table' => $table,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
};
