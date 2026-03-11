<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Government-Grade Security Headers Middleware
 * Implements OWASP recommended security headers for defense-in-depth.
 */
class SecurityHeaders
{
    /**
     * Security headers to apply to all responses.
     */
    protected array $headers = [
        // Prevent XSS attacks
        'X-XSS-Protection' => '1; mode=block',
        
        // Prevent MIME type sniffing
        'X-Content-Type-Options' => 'nosniff',
        
        // Prevent clickjacking
        'X-Frame-Options' => 'DENY',
        
        // Referrer policy for privacy
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        
        // Permissions policy (formerly Feature-Policy)
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(), payment=()',
        
        // Cache control for sensitive data
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        'Pragma' => 'no-cache',
        
        // Prevent information disclosure
        'X-Powered-By' => '',
        'Server' => '',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Apply security headers
        foreach ($this->headers as $key => $value) {
            if ($value === '') {
                $response->headers->remove($key);
            } else {
                $response->headers->set($key, $value);
            }
        }

        // Content Security Policy (CSP)
        $response->headers->set(
            'Content-Security-Policy',
            $this->buildCsp()
        );

        // SECURITY FIX MED-01: Strict Transport Security (HSTS) - always enabled
        // Use shorter max-age for non-production to allow easier testing
        $maxAge = app()->environment('production') ? 31536000 : 86400; // 1 year vs 1 day
        $hsts = "max-age={$maxAge}; includeSubDomains";
        
        // Only add preload directive in production (requires HTTPS cert and DNS setup)
        if (app()->environment('production')) {
            $hsts .= '; preload';
        }
        
        $response->headers->set('Strict-Transport-Security', $hsts);

        return $response;
    }

    /**
     * Build Content Security Policy header.
     * SECURITY FIX HIGH-03: Removed unsafe-inline and unsafe-eval
     */
    protected function buildCsp(): string
    {
        // Generate nonce for inline scripts/styles (if needed)
        $nonce = base64_encode(random_bytes(16));
        request()->attributes->set('csp_nonce', $nonce);
        
        $directives = [
            "default-src 'self'",
            "script-src 'self'", // SECURITY FIX: Removed 'unsafe-inline' 'unsafe-eval'
            "style-src 'self'",  // SECURITY FIX: Removed 'unsafe-inline'
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src 'self' " . config('app.frontend_url', 'http://localhost:3000'),
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "upgrade-insecure-requests", // Force HTTPS
        ];

        return implode('; ', $directives);
    }
}
