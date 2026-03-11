<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MfaMission;
use App\Models\MissionCountryMapping;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MissionManagementController extends Controller
{
    /**
     * Get all missions with statistics.
     */
    public function index(Request $request): JsonResponse
    {
        $query = MfaMission::query()
            ->withCount([
                'users as total_officers',
                'applications as total_applications',
                'applications as pending_applications' => function ($q) {
                    $q->whereIn('status', ['under_review', 'pending_approval']);
                },
            ]);

        // Filter by active status
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        // Filter by region
        if ($request->filled('region')) {
            $query->where('region', $request->input('region'));
        }

        // Search by name or code
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%");
            });
        }

        $missions = $query->orderBy('name')->get();

        return response()->json([
            'missions' => $missions,
        ]);
    }

    /**
     * Get a single mission with detailed information.
     */
    public function show(int $id): JsonResponse
    {
        $mission = MfaMission::with([
            'countryMappings',
            'users' => function ($query) {
                $query->where('is_active', true)
                      ->select('id', 'first_name', 'last_name', 'email', 'role', 'can_review', 'can_approve', 'mfa_mission_id');
            },
        ])
        ->withCount([
            'applications as total_applications',
            'applications as pending_applications' => function ($q) {
                $q->whereIn('status', ['under_review', 'pending_approval']);
            },
            'applications as approved_applications' => function ($q) {
                $q->where('status', 'approved');
            },
        ])
        ->findOrFail($id);

        return response()->json([
            'mission' => $mission,
        ]);
    }

    /**
     * Create a new mission.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:50|unique:mfa_missions,code',
            'name' => 'required|string|max:255',
            'city' => 'required|string|max:100',
            'country_code' => 'required|string|size:2',
            'country_name' => 'required|string|max:100',
            'region' => 'nullable|string|max:100',
            'mission_type' => 'required|in:embassy,consulate,high_commission',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'timezone' => 'nullable|string|max:50',
            'can_issue_visa' => 'boolean',
            'requires_interview' => 'boolean',
            'default_sla_hours' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $mission = MfaMission::create($validator->validated());

        return response()->json([
            'message' => 'Mission created successfully',
            'mission' => $mission,
        ], 201);
    }

    /**
     * Update an existing mission.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $mission = MfaMission::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'code' => 'sometimes|string|max:50|unique:mfa_missions,code,' . $id,
            'name' => 'sometimes|string|max:255',
            'city' => 'sometimes|string|max:100',
            'country_code' => 'sometimes|string|size:2',
            'country_name' => 'sometimes|string|max:100',
            'region' => 'nullable|string|max:100',
            'mission_type' => 'sometimes|in:embassy,consulate,high_commission',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'timezone' => 'nullable|string|max:50',
            'can_issue_visa' => 'boolean',
            'requires_interview' => 'boolean',
            'default_sla_hours' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $mission->update($validator->validated());

        return response()->json([
            'message' => 'Mission updated successfully',
            'mission' => $mission->fresh(),
        ]);
    }

    /**
     * Delete a mission (soft delete).
     */
    public function destroy(int $id): JsonResponse
    {
        $mission = MfaMission::findOrFail($id);

        // Check if mission has active applications
        $activeApplications = DB::table('applications')
            ->where('owner_mission_id', $id)
            ->whereIn('status', ['under_review', 'pending_approval'])
            ->whereNull('deleted_at')
            ->count();

        if ($activeApplications > 0) {
            return response()->json([
                'message' => 'Cannot delete mission with active applications',
                'active_applications' => $activeApplications,
            ], 422);
        }

        // Deactivate instead of delete
        $mission->update(['is_active' => false]);

        return response()->json([
            'message' => 'Mission deactivated successfully',
        ]);
    }

    /**
     * Get country mappings for a mission.
     */
    public function getCountryMappings(int $id): JsonResponse
    {
        $mission = MfaMission::findOrFail($id);
        $mappings = $mission->countryMappings()->orderBy('country_name')->get();

        return response()->json([
            'mappings' => $mappings,
        ]);
    }

    /**
     * Add country mapping to a mission.
     */
    public function addCountryMapping(Request $request, int $id): JsonResponse
    {
        $mission = MfaMission::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'country_code' => 'required|string|size:2',
            'country_name' => 'required|string|max:100',
            'is_primary' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Check if mapping already exists
        $existing = MissionCountryMapping::where('country_code', strtoupper($request->country_code))
            ->where('mfa_mission_id', $id)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Country mapping already exists for this mission',
            ], 422);
        }

        $mapping = MissionCountryMapping::create([
            'mfa_mission_id' => $id,
            'country_code' => strtoupper($request->country_code),
            'country_name' => $request->country_name,
            'is_primary' => $request->boolean('is_primary', false),
        ]);

        return response()->json([
            'message' => 'Country mapping added successfully',
            'mapping' => $mapping,
        ], 201);
    }

    /**
     * Remove country mapping from a mission.
     */
    public function removeCountryMapping(int $id, int $mappingId): JsonResponse
    {
        $mission = MfaMission::findOrFail($id);
        $mapping = MissionCountryMapping::where('id', $mappingId)
            ->where('mfa_mission_id', $id)
            ->firstOrFail();

        $mapping->delete();

        return response()->json([
            'message' => 'Country mapping removed successfully',
        ]);
    }

    /**
     * Get officers assigned to a mission.
     */
    public function getOfficers(int $id): JsonResponse
    {
        $mission = MfaMission::findOrFail($id);
        
        $officers = $mission->users()
            ->where('is_active', true)
            ->whereIn('role', ['mfa_reviewer', 'mfa_approver', 'mfa_admin'])
            ->select('id', 'first_name', 'last_name', 'email', 'role', 'can_review', 'can_approve', 'created_at')
            ->orderBy('last_name')
            ->get()
            ->map(function ($officer) {
                return [
                    'id' => $officer->id,
                    'name' => $officer->first_name . ' ' . $officer->last_name,
                    'email' => $officer->email,
                    'role' => $officer->role,
                    'can_review' => $officer->can_review,
                    'can_approve' => $officer->can_approve,
                    'joined_at' => $officer->created_at->format('Y-m-d'),
                ];
            });

        return response()->json([
            'officers' => $officers,
        ]);
    }

    /**
     * Assign an officer to a mission.
     */
    public function assignOfficer(Request $request, int $id): JsonResponse
    {
        $mission = MfaMission::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::findOrFail($request->user_id);

        // Verify user is an MFA officer
        if (!in_array($user->role, ['mfa_reviewer', 'mfa_approver', 'mfa_admin'])) {
            return response()->json([
                'message' => 'User must be an MFA officer to be assigned to a mission',
            ], 422);
        }

        // Check if already assigned to another mission
        if ($user->mfa_mission_id && $user->mfa_mission_id !== $id) {
            return response()->json([
                'message' => 'Officer is already assigned to another mission',
                'current_mission_id' => $user->mfa_mission_id,
            ], 422);
        }

        $user->update(['mfa_mission_id' => $id]);

        return response()->json([
            'message' => 'Officer assigned to mission successfully',
            'officer' => [
                'id' => $user->id,
                'name' => $user->first_name . ' ' . $user->last_name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    /**
     * Remove an officer from a mission.
     */
    public function removeOfficer(int $id, int $userId): JsonResponse
    {
        $mission = MfaMission::findOrFail($id);
        $user = User::where('id', $userId)
            ->where('mfa_mission_id', $id)
            ->firstOrFail();

        // Check if officer has active applications
        $activeApplications = DB::table('applications')
            ->where(function ($q) use ($userId) {
                $q->where('reviewing_officer_id', $userId)
                  ->orWhere('approval_officer_id', $userId);
            })
            ->whereIn('status', ['under_review', 'pending_approval'])
            ->count();

        if ($activeApplications > 0) {
            return response()->json([
                'message' => 'Cannot remove officer with active applications',
                'active_applications' => $activeApplications,
            ], 422);
        }

        $user->update(['mfa_mission_id' => null]);

        return response()->json([
            'message' => 'Officer removed from mission successfully',
        ]);
    }

    /**
     * Get mission statistics.
     */
    public function getStatistics(int $id): JsonResponse
    {
        $mission = MfaMission::findOrFail($id);

        $stats = [
            'total_officers' => $mission->users()->where('is_active', true)->count(),
            'total_applications' => $mission->applications()->count(),
            'pending_review' => $mission->applications()
                ->where('current_queue', 'review_queue')
                ->where('status', 'under_review')
                ->count(),
            'pending_approval' => $mission->applications()
                ->where('current_queue', 'approval_queue')
                ->where('status', 'pending_approval')
                ->count(),
            'approved_this_month' => $mission->applications()
                ->where('status', 'approved')
                ->whereMonth('decided_at', now()->month)
                ->whereYear('decided_at', now()->year)
                ->count(),
            'denied_this_month' => $mission->applications()
                ->where('status', 'denied')
                ->whereMonth('decided_at', now()->month)
                ->whereYear('decided_at', now()->year)
                ->count(),
            'average_processing_time_hours' => $this->calculateAverageProcessingTime($mission),
            'countries_covered' => $mission->countryMappings()->count(),
        ];

        return response()->json([
            'statistics' => $stats,
        ]);
    }

    /**
     * Get available officers (not assigned to any mission).
     */
    public function getAvailableOfficers(): JsonResponse
    {
        $officers = User::whereNull('mfa_mission_id')
            ->where('is_active', true)
            ->whereIn('role', ['mfa_reviewer', 'mfa_approver', 'mfa_admin'])
            ->select('id', 'first_name', 'last_name', 'email', 'role', 'can_review', 'can_approve')
            ->orderBy('last_name')
            ->get()
            ->map(function ($officer) {
                return [
                    'id' => $officer->id,
                    'name' => $officer->first_name . ' ' . $officer->last_name,
                    'email' => $officer->email,
                    'role' => $officer->role,
                    'can_review' => $officer->can_review,
                    'can_approve' => $officer->can_approve,
                ];
            });

        return response()->json([
            'officers' => $officers,
        ]);
    }

    /**
     * Calculate average processing time in hours for a mission.
     * Uses database-agnostic approach.
     */
    protected function calculateAverageProcessingTime(MfaMission $mission): ?float
    {
        $applications = $mission->applications()
            ->whereNotNull('decided_at')
            ->whereNotNull('submitted_at')
            ->select('submitted_at', 'decided_at')
            ->get();

        if ($applications->isEmpty()) {
            return null;
        }

        $totalHours = $applications->sum(function ($app) {
            return $app->submitted_at->diffInHours($app->decided_at);
        });

        return round($totalHours / $applications->count(), 2);
    }
}
