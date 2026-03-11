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
     * Build the HttpOnly auth cookie with env-configured attributes.
     *
     * Important: For local HTTP development, SESSION_SECURE_COOKIE should be false.
     * For cross-site deployments (separate frontend/backend domains), set SESSION_SAME_SITE=none and SESSION_SECURE_COOKIE=true.
     */
    private function authCookie(string $token, ?int $minutes = null): \Symfony\Component\HttpFoundation\Cookie
    {
        $minutes ??= (int) config('sanctum.expiration', 60);

        $domain = config('session.domain'); // null = current domain
        $secure = (bool) config('session.secure', false);
        $sameSite = config('session.same_site', 'lax');

        return cookie(
            'auth_token', // name
            $token,       // value
            $minutes,     // minutes
            '/',          // path
            $domain,      // domain
            $secure,      // secure (HTTPS only when true)
            true,         // httpOnly
            false,        // raw
            $sameSite     // sameSite
        );
    }
    /**
     * Register a new applicant account.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => 'required|email|unique:users,email',
            // SECURITY FIX MED-02: Enhanced password requirements
            'password'   => [
                'required',
                'confirmed',
                Password::min(12)           // Increased from 8 to 12
                    ->mixedCase()           // Require upper and lowercase
                    ->numbers()             // Require numbers
                    ->symbols()             // ADDED: Require special characters
                    ->uncompromised()       // ADDED: Check against breach database
            ],
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
                    
                    // SECURITY FIX MED-05: Send account lockout notification
                    $this->sendAccountLockoutNotification($user, $request);
                    
                    \Illuminate\Support\Facades\Log::warning('Account locked after failed attempts', [
                        'email' => $validated['email'],
                        'attempts' => $attempts,
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
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
        $officerRoles = ['gis_admin', 'gis_reviewer', 'gis_approver', 'gis_officer', 'mfa_admin', 'mfa_reviewer', 'mfa_approver', 'admin', 'border_officer', 'border_supervisor'];
        if (in_array($user->role, $officerRoles)) {
            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $user->update([
                'mfa_token' => $otp,
                'mfa_expires_at' => now()->addMinutes(10),
            ]);

            \Illuminate\Support\Facades\Log::info("MFA Token for {$user->email}: {$otp}");

            $response = [
                'message' => 'MFA required. Please check your email for the OTP.',
                'requires_mfa' => true,
                'email' => $user->email,
            ];

            // Include OTP in response for development/demo purposes
            if (config('app.env') !== 'production') {
                $response['dev_otp'] = $otp;
            }

            return response()->json($response);
        }

        $primaryRole = $user->roles->first()?->name ?? 'user';
        $token = $user->createToken($primaryRole . '-token')->plainTextToken;

        return response()->json([
            'message' => __('auth.login_success'),
            'user'    => $this->userResource($user),
            // Token no longer sent in response body for security
        ])->cookie($this->authCookie($token, (int) config('sanctum.expiration', 60)));
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
        ])->cookie($this->authCookie($token, (int) config('sanctum.expiration', 60)));
    }

    /**
     * Revoke the current access token (logout).
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        
        // Only delete if it's an actual token (not TransientToken from cookie auth)
        if ($token && !($token instanceof \Laravel\Sanctum\TransientToken)) {
            $token->delete();
        }

        // SECURITY FIX: Clear the HttpOnly cookie
        $cookie = cookie()->forget('auth_token');

        return response()->json([
            'message' => __('auth.logged_out'),
        ])->cookie($cookie);
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
            // SECURITY FIX MED-02: Enhanced password requirements
            'password'         => [
                'required',
                'confirmed',
                Password::min(12)
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised()
            ],
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => __('auth.current_password_incorrect'),
            ], 422);
        }

        // SECURITY FIX MED-02: Check password history (prevent reuse of last 5 passwords)
        if ($this->isPasswordReused($user, $validated['password'])) {
            return response()->json([
                'message' => 'Cannot reuse any of your last 5 passwords. Please choose a different password.',
            ], 422);
        }

        // Store old password in history before updating
        $this->storePasswordHistory($user, $user->password);

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'message' => __('auth.password_changed'),
        ]);
    }

    /**
     * Check if password was used in the last 5 password changes.
     */
    protected function isPasswordReused(User $user, string $newPassword): bool
    {
        $history = \DB::table('password_history')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->pluck('password_hash');

        foreach ($history as $oldHash) {
            if (Hash::check($newPassword, $oldHash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store password in history table.
     */
    protected function storePasswordHistory(User $user, string $passwordHash): void
    {
        \DB::table('password_history')->insert([
            'user_id' => $user->id,
            'password_hash' => $passwordHash,
            'created_at' => now(),
        ]);

        // Keep only last 5 passwords
        $keepIds = \DB::table('password_history')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->pluck('id');

        \DB::table('password_history')
            ->where('user_id', $user->id)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }

    /**
     * SECURITY FIX MED-05: Send account lockout notification email
     */
    protected function sendAccountLockoutNotification(User $user, Request $request): void
    {
        try {
            \Illuminate\Support\Facades\Mail::to($user->email)->send(
                new \App\Mail\AccountLockedNotification($user, [
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'locked_until' => now()->addMinutes(15),
                    'location' => $this->getLocationFromIp($request->ip()),
                ])
            );
        } catch (\Exception $e) {
            // Log error but don't fail the login attempt
            \Illuminate\Support\Facades\Log::error('Failed to send lockout notification', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get approximate location from IP address (optional enhancement)
     */
    protected function getLocationFromIp(string $ip): string
    {
        // For now, just return the IP
        // In production, you could use a GeoIP service
        return $ip;
    }

    /**
     * SECURITY FIX HIGH-01: Refresh authentication token
     * Allows extending session without re-login
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Delete old token
        $oldToken = $request->user()->currentAccessToken();
        if ($oldToken && !($oldToken instanceof \Laravel\Sanctum\TransientToken)) {
            $oldToken->delete();
        }

        // Create new token
        $primaryRole = $user->roles->first()?->name ?? 'user';
        $token = $user->createToken($primaryRole . '-token')->plainTextToken;

        return response()->json([
            'message' => 'Token refreshed successfully',
            'expires_in' => config('sanctum.expiration', 15) * 60, // seconds
        ])->cookie($this->authCookie($token, (int) config('sanctum.expiration', 15)));
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
            'border_officer' => 'BORDER_OFFICER',
            'border_supervisor' => 'BORDER_SUPERVISOR',
            'airline_staff' => 'AIRLINE_STAFF',
            'airline_admin' => 'AIRLINE_ADMIN',
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
