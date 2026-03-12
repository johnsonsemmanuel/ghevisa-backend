<?php

namespace App\Services\Payment\Contracts;

use App\Models\Application;
use App\Models\Payment;

/**
 * Payment Provider Interface
 * 
 * All payment providers must implement this interface to ensure
 * consistent behavior across different payment gateways.
 */
interface PaymentProviderInterface
{
    /**
     * Initialize a payment transaction.
     * 
     * @param Application $application
     * @param float $amount
     * @param string $currency
     * @param string $callbackUrl
     * @return array ['success' => bool, 'authorization_url' => string, 'reference' => string, 'message' => string]
     */
    public function initializePayment(
        Application $application,
        float $amount,
        string $currency,
        string $callbackUrl
    ): array;

    /**
     * Verify a payment transaction.
     * 
     * @param string $reference Transaction reference
     * @return array ['success' => bool, 'status' => string, 'amount' => float, 'message' => string]
     */
    public function verifyPayment(string $reference): array;

    /**
     * Handle webhook callback from provider.
     * 
     * @param array $payload
     * @return array ['success' => bool, 'reference' => string, 'status' => string]
     */
    public function handleWebhook(array $payload): array;

    /**
     * Get provider name.
     * 
     * @return string
     */
    public function getName(): string;

    /**
     * Check if provider is available for given country.
     * 
     * @param string $countryCode
     * @return bool
     */
    public function isAvailableForCountry(string $countryCode): bool;
}
