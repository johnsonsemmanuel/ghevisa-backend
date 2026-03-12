<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Exchange Rate Service
 * 
 * CRITICAL SECURITY FIX: Implements live exchange rate fetching.
 * 
 * Hardcoded exchange rates cause:
 * - Government revenue loss when rates move unfavorably
 * - Applicants overpaying or underpaying
 * - Financial reporting inaccuracies
 * - Audit failures
 * 
 * This service:
 * 1. Fetches live rates from Bank of Ghana API
 * 2. Caches rates for 1 hour to reduce API calls
 * 3. Stores historical rates for audit trail
 * 4. Falls back to last known rate if API fails
 * 5. Alerts on rate fetch failures
 */
class ExchangeRateService
{
    /**
     * Get current exchange rate between two currencies.
     * 
     * @param string $from Source currency (e.g., 'USD')
     * @param string $to Target currency (e.g., 'GHS')
     * @return float Exchange rate
     * @throws \Exception If rate cannot be determined
     */
    public function getCurrentRate(string $from, string $to): float
    {
        // Same currency = 1.0
        if ($from === $to) {
            return 1.0;
        }

        $cacheKey = "exchange_rate_{$from}_{$to}";
        
        // Try cache first (1 hour TTL)
        return Cache::remember($cacheKey, 3600, function () use ($from, $to) {
            return $this->fetchLiveRate($from, $to);
        });
    }

    /**
     * Fetch live exchange rate from Bank of Ghana API.
     * 
     * @param string $from Source currency
     * @param string $to Target currency
     * @return float Exchange rate
     * @throws \Exception If rate cannot be fetched
     */
    protected function fetchLiveRate(string $from, string $to): float
    {
        try {
            // Try Bank of Ghana API first
            $rate = $this->fetchFromBankOfGhana($from, $to);
            
            if ($rate) {
                // Store for audit trail
                $this->storeHistoricalRate($from, $to, $rate, 'bank_of_ghana');
                
                Log::info('Exchange rate fetched successfully', [
                    'from' => $from,
                    'to' => $to,
                    'rate' => $rate,
                    'source' => 'bank_of_ghana',
                ]);
                
                return $rate;
            }
        } catch (\Exception $e) {
            Log::warning('Bank of Ghana API failed, trying fallback', [
                'from' => $from,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback to exchangerate-api.com (free tier: 1500 requests/month)
        try {
            $rate = $this->fetchFromExchangeRateApi($from, $to);
            
            if ($rate) {
                $this->storeHistoricalRate($from, $to, $rate, 'exchangerate_api');
                
                Log::info('Exchange rate fetched from fallback', [
                    'from' => $from,
                    'to' => $to,
                    'rate' => $rate,
                    'source' => 'exchangerate_api',
                ]);
                
                return $rate;
            }
        } catch (\Exception $e) {
            Log::error('Fallback exchange rate API failed', [
                'from' => $from,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
        }

        // Last resort: use last known rate from database
        $lastRate = $this->getLastKnownRate($from, $to);
        
        if ($lastRate) {
            Log::warning('Using last known exchange rate (APIs unavailable)', [
                'from' => $from,
                'to' => $to,
                'rate' => $lastRate,
                'age_hours' => $this->getLastRateAgeHours($from, $to),
            ]);
            
            // Alert if last rate is > 24 hours old
            if ($this->getLastRateAgeHours($from, $to) > 24) {
                Log::critical('Exchange rate is stale (>24 hours old)', [
                    'from' => $from,
                    'to' => $to,
                    'age_hours' => $this->getLastRateAgeHours($from, $to),
                ]);
            }
            
            return $lastRate;
        }

        // Complete failure - throw exception
        Log::critical('Exchange rate unavailable - all sources failed', [
            'from' => $from,
            'to' => $to,
        ]);
        
        throw new \Exception("Exchange rate unavailable for {$from} to {$to}. Please contact system administrator.");
    }

    /**
     * Fetch rate from Bank of Ghana API.
     * 
     * @param string $from Source currency
     * @param string $to Target currency
     * @return float|null Exchange rate or null if unavailable
     */
    protected function fetchFromBankOfGhana(string $from, string $to): ?float
    {
        // Bank of Ghana API endpoint (example - adjust to actual API)
        $apiUrl = config('services.bank_of_ghana.api_url', 'https://www.bog.gov.gh/api/exchange-rates');
        
        if (!$apiUrl) {
            return null;
        }

        $response = Http::timeout(10)->get($apiUrl, [
            'from' => $from,
            'to' => $to,
            'date' => now()->format('Y-m-d'),
        ]);

        if ($response->successful()) {
            $data = $response->json();
            
            // Parse response (adjust based on actual API structure)
            if (isset($data['rate'])) {
                return (float) $data['rate'];
            }
            
            // Alternative structure
            if (isset($data['rates'][$to])) {
                return (float) $data['rates'][$to];
            }
        }

        return null;
    }

    /**
     * Fetch rate from exchangerate-api.com (fallback).
     * 
     * @param string $from Source currency
     * @param string $to Target currency
     * @return float|null Exchange rate or null if unavailable
     */
    protected function fetchFromExchangeRateApi(string $from, string $to): ?float
    {
        // Free tier: https://www.exchangerate-api.com/
        $apiKey = config('services.exchangerate_api.key', '');
        
        if (!$apiKey) {
            // Use free endpoint (limited to 1500 requests/month)
            $response = Http::timeout(10)->get("https://api.exchangerate-api.com/v4/latest/{$from}");
        } else {
            // Paid tier with API key
            $response = Http::timeout(10)->get("https://v6.exchangerate-api.com/v6/{$apiKey}/latest/{$from}");
        }

        if ($response->successful()) {
            $data = $response->json();
            
            if (isset($data['rates'][$to])) {
                return (float) $data['rates'][$to];
            }
        }

        return null;
    }

    /**
     * Store historical exchange rate for audit trail.
     * 
     * @param string $from Source currency
     * @param string $to Target currency
     * @param float $rate Exchange rate
     * @param string $source API source
     * @return void
     */
    protected function storeHistoricalRate(string $from, string $to, float $rate, string $source): void
    {
        DB::table('exchange_rates')->insert([
            'from_currency' => $from,
            'to_currency' => $to,
            'rate' => $rate,
            'source' => $source,
            'fetched_at' => now(),
            'created_at' => now(),
        ]);
    }

    /**
     * Get last known exchange rate from database.
     * 
     * @param string $from Source currency
     * @param string $to Target currency
     * @return float|null Last known rate or null
     */
    protected function getLastKnownRate(string $from, string $to): ?float
    {
        $record = DB::table('exchange_rates')
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->orderBy('fetched_at', 'desc')
            ->first();

        return $record ? (float) $record->rate : null;
    }

    /**
     * Get age of last known rate in hours.
     * 
     * @param string $from Source currency
     * @param string $to Target currency
     * @return int Age in hours
     */
    protected function getLastRateAgeHours(string $from, string $to): int
    {
        $record = DB::table('exchange_rates')
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->orderBy('fetched_at', 'desc')
            ->first();

        if (!$record) {
            return PHP_INT_MAX;
        }

        return now()->diffInHours($record->fetched_at);
    }

    /**
     * Convert amount from one currency to another.
     * 
     * @param float $amount Amount to convert
     * @param string $from Source currency
     * @param string $to Target currency
     * @return float Converted amount
     */
    public function convert(float $amount, string $from, string $to): float
    {
        $rate = $this->getCurrentRate($from, $to);
        return round($amount * $rate, 2);
    }

    /**
     * Get all current rates for a base currency.
     * 
     * @param string $base Base currency
     * @return array Rates for all supported currencies
     */
    public function getAllRates(string $base = 'USD'): array
    {
        $currencies = ['USD', 'GHS', 'EUR', 'GBP'];
        $rates = [];

        foreach ($currencies as $currency) {
            if ($currency === $base) {
                $rates[$currency] = 1.0;
            } else {
                try {
                    $rates[$currency] = $this->getCurrentRate($base, $currency);
                } catch (\Exception $e) {
                    Log::error("Failed to get rate for {$base} to {$currency}", [
                        'error' => $e->getMessage(),
                    ]);
                    $rates[$currency] = null;
                }
            }
        }

        return $rates;
    }

    /**
     * Clear exchange rate cache.
     * 
     * @return void
     */
    public function clearCache(): void
    {
        $currencies = ['USD', 'GHS', 'EUR', 'GBP'];
        
        foreach ($currencies as $from) {
            foreach ($currencies as $to) {
                if ($from !== $to) {
                    Cache::forget("exchange_rate_{$from}_{$to}");
                }
            }
        }

        Log::info('Exchange rate cache cleared');
    }
}
