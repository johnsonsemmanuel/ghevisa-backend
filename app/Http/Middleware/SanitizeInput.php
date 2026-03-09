<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FIX-20: Sanitize all text inputs by stripping HTML/script tags.
 * Provides defense-in-depth against XSS alongside Laravel's output escaping.
 */
class SanitizeInput
{
    /**
     * Fields that should NOT be sanitized (e.g., rich text, passwords).
     */
    protected array $except = [
        'password',
        'password_confirmation',
        'current_password',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $input = $request->all();
        $request->merge($this->sanitize($input));

        return $next($request);
    }

    /**
     * Recursively strip HTML/script tags from input values.
     */
    protected function sanitize(array $data, string $prefix = ''): array
    {
        foreach ($data as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;

            if (in_array($key, $this->except)) {
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->sanitize($value, $fullKey);
            } elseif (is_string($value)) {
                $data[$key] = strip_tags($value);
            }
        }

        return $data;
    }
}
