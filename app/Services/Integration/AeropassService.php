<?php

namespace App\Services\Integration;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AeropassService
{
    /**
     * Trigger Interpol nominal check with Aeropass.
     * 
     * @param array $payload Traveler data for Interpol check
     * @return array Response from Aeropass
     * @throws \RuntimeException If Aeropass is unreachable after retries
     */
    public function triggerInterpolCheck(array $payload): array
    {
        $baseUrl = config('aeropass.base_url');
        $username = config('aeropass.username');
        $password = config('aeropass.password');
        $timeout = config('aeropass.timeout', 20);
        $retries = config('aeropass.retries', 3);
        $retryDelay = config('aeropass.retry_delay_ms', 2000);

        $url = $baseUrl . '/aeropass/e-visa/interpol-nominal-verification';

        try {
            Log::info('Aeropass Interpol check request', [
                'url' => $url,
                'unique_reference_id' => $payload['uniqueReferenceId'] ?? null,
            ]);

            $response = Http::withBasicAuth($username, $password)
                ->timeout($timeout)
                ->retry($retries, $retryDelay)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($url, $payload);

            if ($response->successful()) {
                Log::info('Aeropass Interpol check successful', [
                    'unique_reference_id' => $payload['uniqueReferenceId'] ?? null,
                    'status' => $response->status(),
                ]);

                return $response->json();
            }

            Log::error('Aeropass Interpol check failed', [
                'unique_reference_id' => $payload['uniqueReferenceId'] ?? null,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('Aeropass unreachable after retries');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Aeropass connection failed', [
                'unique_reference_id' => $payload['uniqueReferenceId'] ?? null,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Aeropass unreachable after retries');
        }
    }
}
