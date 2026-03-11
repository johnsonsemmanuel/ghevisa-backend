<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AttachAuthTokenFromCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        // If a bearer token is already provided, don't override it.
        $hasAuthHeader = $request->headers->has('Authorization') || $request->headers->has('authorization');

        if (!$hasAuthHeader) {
            $token = $request->cookie('auth_token');

            if (is_string($token) && $token !== '') {
                $request->headers->set('Authorization', 'Bearer '.$token);
            }
        }

        return $next($request);
    }
}

