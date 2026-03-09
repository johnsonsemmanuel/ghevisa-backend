<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditAction
{
    /**
     * Log every API request for auditing purposes.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$request->user()) {
            return $response;
        }

        $shouldLog = false;

        // Always log state-changing requests
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $shouldLog = true;
        }

        // COMP-01/SEC-04: Also log GET requests on sensitive data endpoints
        if ($request->method() === 'GET') {
            $sensitivePatterns = [
                'gis/cases/',
                'mfa/escalations/',
                'admin/applications/',
                'admin/users/',
                'border/verify',
                'airline/verify',
            ];
            $path = $request->path();
            foreach ($sensitivePatterns as $pattern) {
                if (str_contains($path, $pattern)) {
                    $shouldLog = true;
                    break;
                }
            }
        }

        if ($shouldLog) {
            AuditLog::create([
                'user_id'        => $request->user()->id,
                'action'         => 'api.' . strtolower($request->method()) . '.' . $request->path(),
                'auditable_type' => 'API',
                'auditable_id'   => 0,
                'old_values'     => null,
                'new_values'     => $request->method() === 'GET' ? null : $this->sanitiseInput($request->all()),
                'ip_address'     => $request->ip(),
                'user_agent'     => $request->userAgent(),
            ]);
        }

        return $response;
    }

    /**
     * Remove sensitive fields from logged payloads.
     */
    private function sanitiseInput(array $data): array
    {
        $redacted = ['password', 'password_confirmation', 'card_number', 'cvc', 'cvv'];

        foreach ($redacted as $field) {
            if (isset($data[$field])) {
                $data[$field] = '***REDACTED***';
            }
        }

        return $data;
    }
}
