<?php

/**
 * HIGH-05: Configurable exchange rates.
 * In production, replace with a live rate API integration.
 * These are fallback rates only.
 */
return [
    'USD' => 1,
    'GHS' => (float) env('EXCHANGE_RATE_GHS', 12.5),
    'EUR' => (float) env('EXCHANGE_RATE_EUR', 0.92),
    'GBP' => (float) env('EXCHANGE_RATE_GBP', 0.79),
];
