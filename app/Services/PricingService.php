<?php

namespace App\Services;

use App\Models\Application;
use App\Models\VisaType;

/**
 * Unified Pricing Service
 * 
 * Implements the official pricing specification:
 * - Base Price: $260 (uniform)
 * - Entry Multipliers:
 *   - Single = 1.0
 *   - Multiple = 1.8
 * - Processing Tier Multipliers (E-Visa only):
 *   - Standard = 1.0
 *   - Priority = 1.3
 *   - Express  = 1.7
 * 
 * Formulas:
 * - E-Visa: Base Price × Entry Multiplier × Processing Tier Multiplier
 * - Regular Visa: Base Price × Entry Multiplier
 */
class PricingService
{
    /**
     * Base price for all visa types (specification requirement)
     */
    const BASE_PRICE = 260.00;

    /**
     * Entry type multipliers
     */
    const ENTRY_MULTIPLIER_SINGLE = 1.0;
    const ENTRY_MULTIPLIER_MULTIPLE = 1.8;

    /**
     * Processing tier multipliers (E-Visa only)
     */
    const TIER_MULTIPLIERS = [
        'standard' => 1.0,
        'priority' => 1.3,
        'express'  => 1.7,
    ];

    /**
     * Calculate total price for an application.
     * 
     * @param Application|array $data Application model or array with pricing data
     * @return array Breakdown of pricing components
     */
    public function calculatePrice($data): array
    {
        // Extract data from Application model or array
        if ($data instanceof Application) {
            $visaChannel = $data->visa_channel;
            $entryType = $data->entry_type;
            $serviceTierCode = $data->serviceTier?->code ?? 'standard';
        } else {
            $visaChannel = $data['visa_channel'] ?? 'e-visa';
            $entryType = $data['entry_type'] ?? 'single';
            $serviceTierCode = $data['service_tier_code'] ?? 'standard';
        }

        // Normalize values
        $visaChannel = strtolower($visaChannel);
        $entryType = strtolower($entryType);
        $serviceTierCode = strtolower($serviceTierCode);

        // Step 1: Base Price (always $260)
        $basePrice = self::BASE_PRICE;

        // Step 2: Entry Type Multiplier
        $entryMultiplier = $this->getEntryMultiplier($entryType);

        // Step 3: Processing Tier Multiplier (E-Visa only)
        $tierMultiplier = $this->getTierMultiplier($visaChannel, $serviceTierCode);
        $additionalFee = 0.0;

        // Calculate total (spec formula)
        $total = $basePrice * $entryMultiplier * $tierMultiplier;

        // Calculate breakdown
        $entryFee = $basePrice * $entryMultiplier;
        $processingFee = $total - $entryFee;

        return [
            'base_price' => round($basePrice, 2),
            'entry_type' => $entryType,
            'entry_multiplier' => $entryMultiplier,
            'entry_fee' => round($entryFee, 2),
            'visa_channel' => $visaChannel,
            'service_tier' => $serviceTierCode,
            'tier_multiplier' => $tierMultiplier,
            'additional_fee' => round($additionalFee, 2),
            'processing_fee' => round($processingFee, 2),
            'total' => round($total, 2),
            'formula' => $this->getFormulaDescription($visaChannel, $entryType, $serviceTierCode),
        ];
    }

    /**
     * Get entry type multiplier.
     */
    protected function getEntryMultiplier(string $entryType): float
    {
        return match ($entryType) {
            'multiple' => self::ENTRY_MULTIPLIER_MULTIPLE,
            'single' => self::ENTRY_MULTIPLIER_SINGLE,
            default => self::ENTRY_MULTIPLIER_SINGLE,
        };
    }

    /**
     * Get processing tier multiplier.
     * Pure function based on specification, independent of DB.
     */
    protected function getTierMultiplier(string $visaChannel, string $serviceTierCode): float
    {
        // Regular / on-arrival visa: no tier multiplier
        if ($visaChannel === 'regular' || $visaChannel === 'on-arrival') {
            return 1.0;
        }

        // E-Visa: use hard-coded spec multipliers
        return self::TIER_MULTIPLIERS[$serviceTierCode] ?? 1.0;
    }

    /**
     * Get formula description for transparency.
     */
    protected function getFormulaDescription(string $visaChannel, string $entryType, string $serviceTierCode): string
    {
        $basePrice = self::BASE_PRICE;
        $entryMult = $this->getEntryMultiplier($entryType);
        $tierMult = $this->getTierMultiplier($visaChannel, $serviceTierCode);

        if ($visaChannel === 'e-visa') {
            return "{$basePrice} × {$entryMult} × {$tierMult}";
        }

        return "{$basePrice} × {$entryMult}";
    }

    /**
     * Calculate and update application total fee.
     */
    public function calculateAndUpdateApplicationFee(Application $application): Application
    {
        $pricing = $this->calculatePrice($application);

        $application->update([
            'total_fee' => $pricing['total'],
            'processing_fee' => $pricing['processing_fee'],
            'government_fee' => 0, // Can be configured separately if needed
            'platform_fee' => 0,   // Can be configured separately if needed
        ]);

        return $application->fresh();
    }

    /**
     * Validate that a submitted price matches the calculated price.
     * 
     * @param Application $application
     * @param float $submittedPrice
     * @param float $tolerance Acceptable difference (for rounding)
     * @return bool
     */
    public function validatePrice(Application $application, float $submittedPrice, float $tolerance = 0.01): bool
    {
        $pricing = $this->calculatePrice($application);
        $calculatedPrice = $pricing['total'];

        return abs($calculatedPrice - $submittedPrice) <= $tolerance;
    }

    /**
     * Get pricing preview for given parameters.
     * Used by API endpoint for frontend price display.
     */
    public function getPricingPreview(array $params): array
    {
        return $this->calculatePrice($params);
    }

    /**
     * Get all available service tiers with their multipliers.
     */
    public function getServiceTiers(): array
    {
        return [
            [
                'code' => 'standard',
                'name' => 'Standard Processing',
                'multiplier' => self::TIER_MULTIPLIERS['standard'],
                'description' => '3–5 business days',
            ],
            [
                'code' => 'priority',
                'name' => 'Priority Processing',
                'multiplier' => self::TIER_MULTIPLIERS['priority'],
                'description' => 'within 48 hours',
            ],
            [
                'code' => 'express',
                'name' => 'Express Processing',
                'multiplier' => self::TIER_MULTIPLIERS['express'],
                'description' => 'within 5 hours',
            ],
        ];
    }

    /**
     * Calculate example prices for documentation/display.
     */
    public function getExamplePrices(): array
    {
        $examples = [];

        // Helper to compute from spec formula
        $calc = function (string $visaChannel, string $entryType, string $tier) {
            $base = self::BASE_PRICE;
            $entryMult = $this->getEntryMultiplier($entryType);
            $tierMult = $this->getTierMultiplier($visaChannel, $tier);
            return [
                'calculation' => "{$base} × {$entryMult} × {$tierMult}",
                'price' => round($base * $entryMult * $tierMult, 2),
            ];
        };

        // E-Visa examples
        $examples[] = array_merge([
            'description' => 'E-Visa, Single Entry, Standard',
        ], $calc('e-visa', 'single', 'standard'));

        $examples[] = array_merge([
            'description' => 'E-Visa, Single Entry, Priority',
        ], $calc('e-visa', 'single', 'priority'));

        $examples[] = array_merge([
            'description' => 'E-Visa, Single Entry, Express',
        ], $calc('e-visa', 'single', 'express'));

        $examples[] = array_merge([
            'description' => 'E-Visa, Multiple Entry, Standard',
        ], $calc('e-visa', 'multiple', 'standard'));

        $examples[] = array_merge([
            'description' => 'E-Visa, Multiple Entry, Priority',
        ], $calc('e-visa', 'multiple', 'priority'));

        $examples[] = array_merge([
            'description' => 'E-Visa, Multiple Entry, Express',
        ], $calc('e-visa', 'multiple', 'express'));

        // Regular visa examples (no tier multiplier)
        $examples[] = [
            'description' => 'Regular Visa, Single Entry',
            'calculation' => '260 × 1.0',
            'price' => round(self::BASE_PRICE * self::ENTRY_MULTIPLIER_SINGLE, 2),
        ];

        $examples[] = [
            'description' => 'Regular Visa, Multiple Entry',
            'calculation' => '260 × 1.8',
            'price' => round(self::BASE_PRICE * self::ENTRY_MULTIPLIER_MULTIPLE, 2),
        ];

        return $examples;
    }
}
