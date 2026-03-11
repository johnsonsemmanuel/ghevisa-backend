<?php

/**
 * Manual Test Script for Aeropass Integration
 * 
 * Run this from the command line to test the Aeropass connection:
 * php artisan tinker
 * include 'tests/Manual/TestAeropassConnection.php';
 */

namespace Tests\Manual;

use App\Services\AeropassService;
use Illuminate\Support\Facades\Log;

class TestAeropassConnection
{
    public static function run()
    {
        echo "=== Aeropass Integration Test ===\n\n";
        
        // Display configuration
        echo "Configuration:\n";
        echo "- Base URL: " . config('aeropass.base_url') . "\n";
        echo "- Username: " . config('aeropass.username') . "\n";
        echo "- Password: " . (config('aeropass.password') ? '***configured***' : 'NOT SET') . "\n";
        echo "- Full Endpoint: " . config('aeropass.base_url') . "/aeropass/e-visa/interpol-nominal-verification\n\n";
        
        // Test payload
        $testPayload = [
            'uniqueReferenceId' => 'TEST-' . time(),
            'firstName' => 'John',
            'surname' => 'Doe',
            'dateOfBirth' => '01/01/1990',
            'nationality' => 'US',
            'travelDocNumber' => 'A1234567',
        ];
        
        echo "Test Payload:\n";
        echo json_encode($testPayload, JSON_PRETTY_PRINT) . "\n\n";
        
        // Test the connection
        try {
            $service = app(AeropassService::class);
            echo "Sending request to Aeropass...\n";
            
            $result = $service->triggerInterpolCheck($testPayload);
            
            echo "\n✅ SUCCESS! Response received:\n";
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            
        } catch (\RuntimeException $e) {
            echo "\n❌ ERROR: " . $e->getMessage() . "\n";
            echo "This could mean:\n";
            echo "1. Aeropass server is unreachable\n";
            echo "2. Authentication credentials are incorrect\n";
            echo "3. Network/firewall issues\n";
            echo "4. Endpoint URL is incorrect\n\n";
            
        } catch (\Exception $e) {
            echo "\n❌ UNEXPECTED ERROR: " . $e->getMessage() . "\n";
            echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        }
        
        echo "\n=== Test Complete ===\n";
    }
}

// Uncomment to run immediately when included in tinker:
// TestAeropassConnection::run();
