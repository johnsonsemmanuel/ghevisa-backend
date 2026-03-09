<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Register a new applicant account.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => 'required|email|unique:users,email',
            'password'   => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'phone'      => 'nullable|string|max:20',
            'locale'     => 'nullable|in:en,fr',
        ]);

        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name'  => $validated['last_name'],
            'email'      => $validated['email'],
            'password'   => Hash::make($validated['password']),
            'phone'      => $validated['phone'] ?? null,
            'role'       => 'applicant',
            'locale'     => $validated['locale'] ?? 'en',
        ]);

        // Generate verification token and send email
        $verificationToken = bin2hex(random_bytes(32));
        $user->update([
            'email_verification_token' => $verificationToken,
            'email_verification_expires_at' => now()->addHours(24),
        ]);

        \App\Jobs\SendEmailVerification::dispatch($user);

        return response()->json([
            'message' => 'Registration successful! Please check your email to verify your account.',
            'requires_email_verification' => true,
            'email' => $user->email,
            'user'    => $this->userResource($user),
        ], 201);
    }

    /**
     * Authenticate a user and return a token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        // SEC-03: Check account lockout before attempting auth
        $user = User::where('email', $validated['email'])->first();
        if ($user && $user->locked_until && now()->lt($user->locked_until)) {
            $minutesLeft = now()->diffInMinutes($user->locked_until);
            return response()->json([
                'message' => "Account temporarily locked due to too many failed attempts. Try again in {$minutesLeft} minute(s).",
            ], 429);
        }

        if (!Auth::attempt($validated)) {
            // SEC-03: Track failed login attempts
            if ($user) {
                $attempts = ($user->failed_login_attempts ?? 0) + 1;
                $lockData = ['failed_login_attempts' => $attempts];

                if ($attempts >= 5) {
                    $lockData['locked_until'] = now()->addMinutes(15);
                    \Illuminate\Support\Facades\Log::warning('Account locked after failed attempts', [
                        'email' => $validated['email'],
                        'attempts' => $attempts,
                        'ip' => $request->ip(),
                    ]);
                }

                $user->update($lockData);
            }

            return response()->json([
                'message' => __('auth.failed'),
            ], 401);
        }

        // Reset failed attempts on successful login
        if ($user) {
            $user->update([
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ]);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => __('auth.account_deactivated'),
            ], 403);
        }

        // Check if email is verified
        if (!$user->email_verified_at) {
            return response()->json([
                'message' => 'Please verify your email address before logging in. Check your inbox for the verification email.',
                'requires_email_verification' => true,
                'email' => $user->email,
            ], 403);
        }

        // HIGH-08: Officer MFA
        $officerRoles = ['gis_admin', 'gis_reviewer', 'gis_approver', 'gis_officer', 'mfa_admin', 'mfa_reviewer', 'mfa_approver', 'admin'];
        if (in_array($user->role, $officerRoles)) {
            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $user->update([
                'mfa_token' => $otp,
                'mfa_expires_at' => now()->addMinutes(10),
            ]);

            \Illuminate\Support\Facades\Log::info("MFA Token for {$user->email}: {$otp}");

            return response()->json([
                'message' => 'MFA required. Please check your email for the OTP.',
                'requires_mfa' => true,
                'email' => $user->email,
            ]);
        }

        $primaryRole = $user->roles->first()?->name ?? 'user';
        $token = $user->createToken($primaryRole . '-token')->plainTextToken;

        return response()->json([
            'message' => __('auth.login_success'),
            'user'    => $this->userResource($user),
            'token'   => $token,
        ]);
    }

    /**
     * Verify MFA OTP and return token.
     */
    public function verifyMfa(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'token' => 'required|string|size:6',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || $user->mfa_token !== $validated['token'] || !$user->mfa_expires_at || now()->greaterThan($user->mfa_expires_at)) {
            return response()->json([
                'message' => 'Invalid or expired MFA token.',
            ], 422);
        }

        // Token is valid, clear it and reset failed login attempts
        $user->update([
            'mfa_token' => null,
            'mfa_expires_at' => null,
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);

        $primaryRole = $user->roles->first()?->name ?? 'user';
        $token = $user->createToken($primaryRole . '-token')->plainTextToken;

        return response()->json([
            'message' => __('auth.login_success'),
            'user'    => $this->userResource($user),
            'token'   => $token,
        ]);
    }

    /**
     * Revoke the current access token (logout).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => __('auth.logged_out'),
        ]);
    }

    /**
     * Get authenticated user profile.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userResource($request->user()),
        ]);
    }

    /**
     * Update user profile.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:255',
            'last_name'  => 'sometimes|string|max:255',
            'phone'      => 'nullable|string|max:20',
            'locale'     => 'sometimes|in:en,fr',
        ]);

        $request->user()->update($validated);

        return response()->json([
            'message' => __('auth.profile_updated'),
            'user'    => $this->userResource($request->user()),
        ]);
    }

    /**
     * Change user password.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'password'         => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => __('auth.current_password_incorrect'),
            ], 422);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'message' => __('auth.password_changed'),
        ]);
    }

    /**
     * Verify email address with token.
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|size:64',
        ]);

        $user = User::where('email_verification_token', $validated['token'])->first();

        if (!$user) {
            return response()->json([
                'message' => 'Invalid or expired verification token',
            ], 422);
        }

        // HIGH-02: Enforce token expiry (24 hours)
        if ($user->email_verification_expires_at && now()->greaterThan($user->email_verification_expires_at)) {
            return response()->json([
                'message' => 'Verification token has expired. Please request a new one.',
            ], 422);
        }

        $user->update([
            'email_verified_at' => now(),
            'email_verification_token' => null,
            'email_verification_expires_at' => null,
        ]);

        return response()->json([
            'message' => 'Email verified successfully. You can now create applications.',
        ]);
    }

    /**
     * Resend verification email.
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'Email already verified',
            ], 422);
        }

        $verificationToken = bin2hex(random_bytes(32));
        $user->update([
            'email_verification_token' => $verificationToken,
            'email_verification_expires_at' => now()->addHours(24),
        ]);

        \App\Jobs\SendEmailVerification::dispatch($user);

        return response()->json([
            'message' => 'Verification email sent',
        ]);
    }

    private function userResource(User $user): array
    {
        // Map column-based role to frontend role format
        $roleMapping = [
            'gis_admin' => 'GIS_ADMIN',
            'gis_reviewer' => 'GIS_REVIEWING_OFFICER',
            'gis_approver' => 'GIS_APPROVAL_OFFICER',
            'gis_officer' => 'GIS_REVIEWING_OFFICER',
            'mfa_admin' => 'MFA_ADMIN',
            'mfa_reviewer' => 'MFA_REVIEWING_OFFICER',
            'mfa_approver' => 'MFA_APPROVAL_OFFICER',
            'admin' => 'SYSTEM_ADMIN',
            'applicant' => 'APPLICANT',
        ];

        $frontendRole = $roleMapping[$user->role] ?? strtoupper($user->role);

        // Build permissions array based on role and capabilities
        $permissions = [];
        
        // All officers can view applications
        if (in_array($user->role, ['gis_reviewer', 'gis_approver', 'gis_admin', 'mfa_reviewer', 'mfa_approver', 'mfa_admin', 'admin'])) {
            $permissions[] = 'applications.view';
            $permissions[] = 'documents.view';
            $permissions[] = 'notes.view';
            $permissions[] = 'notes.create';
        }
        
        // Review permissions
        if ($user->canReviewApplications()) {
            $permissions[] = 'applications.review';
            $permissions[] = 'applications.request_info';
            $permissions[] = 'documents.verify';
            $permissions[] = 'risk.view';
            $permissions[] = 'risk.assess';
        }
        
        // Approval permissions
        if ($user->canApproveApplications()) {
            $permissions[] = 'applications.approve';
            $permissions[] = 'applications.deny';
            $permissions[] = 'applications.escalate';
            $permissions[] = 'evisa.generate';
        }
        
        // Admin permissions
        if (in_array($user->role, ['gis_admin', 'mfa_admin', 'admin'])) {
            $permissions[] = 'reports.view';
            $permissions[] = 'reports.export';
        }

        return [
            'id'          => $user->id,
            'first_name'  => $user->first_name,
            'last_name'   => $user->last_name,
            'full_name'   => $user->full_name,
            'email'       => $user->email,
            'phone'       => $user->phone,
            'role'        => $frontendRole,
            'agency'      => $user->agency,
            'locale'      => $user->locale,
            'permissions' => $permissions,
        ];
    }
}
