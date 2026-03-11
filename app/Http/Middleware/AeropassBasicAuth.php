<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AeropassBasicAuth
{
    /**
     * Handle an incoming request from Aeropass.
     * Validates Basic Authentication credentials.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization');

        // Check if Authorization header exists
        if (!$authHeader) {
            return response()->json([
                'uniqueReferenceId' => $request->input('uniqueReferenceId'),
                'responseCode' => '401',
                'errorMessage' => 'Invalid Authorisation Header',
            ], 401);
        }

        // Check if it's Basic auth
        if (!str_starts_with($authHeader, 'Basic ')) {
            return response()->json([
                'uniqueReferenceId' => $request->input('uniqueReferenceId'),
                'responseCode' => '401',
                'errorMessage' => 'Invalid Authorisation Header',
            ], 401);
        }

        // Extract and decode credentials
        $encodedCredentials = substr($authHeader, 6); // Remove "Basic " prefix
        $decodedCredentials = base64_decode($encodedCredentials, true);

        if ($decodedCredentials === false) {
            return response()->json([
                'uniqueReferenceId' => $request->input('uniqueReferenceId'),
                'responseCode' => '401',
                'errorMessage' => 'Invalid Authorisation Header',
            ], 401);
        }

        // Split credentials
        $parts = explode(':', $decodedCredentials, 2);
        
        if (count($parts) !== 2) {
            return response()->json([
                'uniqueReferenceId' => $request->input('uniqueReferenceId'),
                'responseCode' => '401',
                'errorMessage' => 'Invalid Authorisation Header',
            ], 401);
        }

        [$username, $password] = $parts;

        // Validate credentials
        $expectedUsername = config('aeropass.username', env('EVISA_BASIC_USER'));
        $expectedPassword = config('aeropass.password', env('EVISA_BASIC_PASS'));

        if ($username !== $expectedUsername || $password !== $expectedPassword) {
            return response()->json([
                'uniqueReferenceId' => $request->input('uniqueReferenceId'),
                'responseCode' => '401',
                'errorMessage' => 'Invalid Authorisation Header',
            ], 401);
        }

        return $next($request);
    }
}
