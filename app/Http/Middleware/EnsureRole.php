<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Handle an incoming request.
     * Usage: middleware('role:admin,gis_officer')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(403, __('auth.unauthorized_role'));
        }

        // Check if user has any of the required roles
        $hasRole = false;
        foreach ($roles as $role) {
            // First check column-based role
            $allowedRoles = $this->expandRole($role);
            if (in_array($user->role, $allowedRoles)) {
                $hasRole = true;
                break;
            }
            
            // Also check Spatie roles if available
            $mappedRoles = $this->mapRole($role);
            $rolesToCheck = is_array($mappedRoles) ? $mappedRoles : [$mappedRoles];
            
            foreach ($rolesToCheck as $mappedRole) {
                if ($user->hasRole($mappedRole)) {
                    $hasRole = true;
                    break 2;
                }
            }
        }

        if (!$hasRole) {
            abort(403, __('auth.unauthorized_role'));
        }

        if (!$user->is_active) {
            abort(403, __('auth.account_deactivated'));
        }

        return $next($request);
    }

    /**
     * Expand role groups to individual roles (column-based)
     */
    private function expandRole(string $role): array
    {
        return match($role) {
            'gis_officer' => ['gis_officer', 'gis_reviewer', 'gis_approver', 'gis_admin'],
            'gis_reviewer' => ['gis_reviewer', 'gis_officer', 'gis_admin'],
            'gis_approver' => ['gis_approver', 'gis_officer', 'gis_admin'],
            'gis_admin' => ['gis_admin'],
            'mfa_officer' => ['mfa_reviewer', 'mfa_approver', 'mfa_admin'],
            'mfa_reviewer' => ['mfa_reviewer', 'mfa_admin'],
            'mfa_approver' => ['mfa_approver', 'mfa_admin'],
            'mfa_admin' => ['mfa_admin'],
            'airline' => ['airline_staff', 'airline_admin'],
            'border' => ['border_officer', 'border_supervisor'],
            'admin' => ['admin'],
            'applicant' => ['applicant'],
            default => [$role],
        };
    }

    /**
     * Map legacy role names to Spatie role names
     */
    private function mapRole(string $role): string|array
    {
        return match($role) {
            'gis_officer' => ['GIS_REVIEWING_OFFICER', 'GIS_APPROVAL_OFFICER', 'GIS_ADMIN'],
            'gis_reviewer' => 'GIS_REVIEWING_OFFICER',
            'gis_approver' => 'GIS_APPROVAL_OFFICER',
            'gis_admin' => 'GIS_ADMIN',
            'mfa_officer' => ['MFA_REVIEWING_OFFICER', 'MFA_APPROVAL_OFFICER', 'MFA_ADMIN'],
            'mfa_reviewer' => 'MFA_REVIEWING_OFFICER',
            'mfa_approver' => 'MFA_APPROVAL_OFFICER',
            'mfa_admin' => 'MFA_ADMIN',
            'airline' => ['AIRLINE_STAFF', 'AIRLINE_ADMIN'],
            'border' => ['BORDER_OFFICER', 'BORDER_SUPERVISOR'],
            'admin' => 'SYSTEM_ADMIN',
            'applicant' => 'APPLICANT',
            default => $role,
        };
    }
}
