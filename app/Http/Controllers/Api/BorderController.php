<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\BorderCrossing;
use App\Models\EtaApplication;
use App\Services\QrCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class BorderController extends Controller
{
    /**
     * Verify a traveler's visa/ETA at the border.
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => 'required|in:evisa,eta',
            'reference_number' => 'nullable|string',
            'evisa_number' => 'nullable|string',
            'eta_number' => 'nullable|string',
            'passport_number' => 'required|string',
        ]);

        Log::info('border_lookup_attempt', [
            'document_type' => $validated['document_type'],
            'reference_number' => $validated['reference_number'] ?? null,
            'passport_suffix' => substr($validated['passport_number'], -4),
            'ip' => $request->ip(),
            'user_id' => $request->user()?->id,
        ]);

        if ($validated['document_type'] === 'evisa') {
            return $this->verifyEvisa($validated);
        }

        return $this->verifyEta($validated);
    }

    /**
     * Verify eVisa document.
     */
    protected function verifyEvisa(array $data): JsonResponse
    {
        $query = Application::where('status', 'approved');

        if (!empty($data['reference_number'])) {
            $query->where('reference_number', $data['reference_number']);
        } elseif (!empty($data['evisa_number'])) {
            $query->where('evisa_qr_code', 'like', '%' . $data['evisa_number'] . '%');
        } else {
            return response()->json([
                'valid' => false,
                'message' => 'Reference number or eVisa number required',
            ], 422);
        }

        $application = $query->first();

        if (!$application) {
            return response()->json([
                'valid' => false,
                'status' => 'not_found',
                'message' => 'eVisa not found in system',
            ], 404);
        }

        // Verify passport number matches
        $storedPassport = $application->passport_number;
        if (strtoupper($storedPassport) !== strtoupper($data['passport_number'])) {
            return response()->json([
                'valid' => false,
                'status' => 'invalid',
                'message' => 'Passport number does not match eVisa record',
            ], 403);
        }

        // Check if visa is still valid (not expired)
        $visaType = $application->visaType;
        $expiryDate = $application->decided_at?->addDays($visaType?->max_duration_days ?? 90);
        $isExpired = $expiryDate && $expiryDate < now();

        if ($isExpired) {
            return response()->json([
                'valid' => false,
                'status' => 'expired',
                'message' => 'eVisa has expired',
                'expired_on' => $expiryDate->format('Y-m-d'),
            ]);
        }

        // Check risk flags
        $riskWarnings = [];
        if ($application->watchlist_flagged) {
            $riskWarnings[] = 'WATCHLIST FLAG - Secondary inspection required';
        }
        if ($application->risk_level === 'high' || $application->risk_level === 'critical') {
            $riskWarnings[] = 'HIGH RISK - Manual verification recommended';
        }

        return response()->json([
            'valid' => true,
            'status' => 'valid',
            'message' => 'eVisa verified successfully',
            'document' => [
                'type' => 'evisa',
                'reference_number' => $application->reference_number,
                'holder_name' => $application->first_name . ' ' . $application->last_name,
                'nationality' => $application->nationality,
                'passport_number_masked' => substr($storedPassport, 0, 3) . '****',
                'visa_type' => $visaType?->name,
                'entry_type' => $visaType?->entry_type ?? 'single',
                'valid_until' => $expiryDate?->format('Y-m-d'),
                'approved_on' => $application->decided_at?->format('Y-m-d'),
            ],
            'risk_warnings' => $riskWarnings,
            'previous_entries' => $this->getPreviousEntries($application->id, null),
        ]);
    }

    /**
     * Offline mode cache data for the current day/port.
     */
    public function offlineCache(Request $request): JsonResponse
    {
        $port = $request->query('port');
        $date = $request->query('date', today()->format('Y-m-d'));
        $limit = min((int) $request->query('limit', 250), 500);

        $query = BorderCrossing::whereDate('crossed_at', $date)
            ->where('verification_status', 'valid')
            ->where('crossing_type', 'entry');

        if ($port) {
            $query->where('port_of_entry', $port);
        }

        $authorizations = $query->orderByDesc('crossed_at')
            ->limit($limit)
            ->get()
            ->map(fn($c) => [
                'crossing_id' => $c->id,
                'passport_masked' => $c->passport_number_masked,
                'traveler_name' => $c->traveler_name,
                'document_type' => $c->application_id ? 'evisa' : 'eta',
                'port' => $c->port_of_entry,
                'cached_at' => $c->crossed_at->toIso8601String(),
            ]);

        return response()->json([
            'date' => $date,
            'port' => $port ?? 'all',
            'count' => $authorizations->count(),
            'authorizations' => $authorizations,
            'mode' => 'offline_cache_ready',
        ]);
    }

    /**
     * Verify ETA document.
     */
    protected function verifyEta(array $data): JsonResponse
    {
        $query = EtaApplication::where('status', 'approved');

        if (!empty($data['eta_number'])) {
            $query->where('eta_number', $data['eta_number']);
        } elseif (!empty($data['reference_number'])) {
            $query->where('reference_number', $data['reference_number']);
        } else {
            return response()->json([
                'valid' => false,
                'message' => 'ETA number or reference number required',
            ], 422);
        }

        $eta = $query->first();

        if (!$eta) {
            return response()->json([
                'valid' => false,
                'status' => 'not_found',
                'message' => 'ETA not found in system',
            ], 404);
        }

        // Verify passport number
        $storedPassport = Crypt::decryptString($eta->passport_number_encrypted);
        if (strtoupper($storedPassport) !== strtoupper($data['passport_number'])) {
            return response()->json([
                'valid' => false,
                'status' => 'invalid',
                'message' => 'Passport number does not match ETA record',
            ], 403);
        }

        // Check expiry
        if ($eta->expires_at && $eta->expires_at < now()) {
            return response()->json([
                'valid' => false,
                'status' => 'expired',
                'message' => 'ETA has expired',
                'expired_on' => $eta->expires_at->format('Y-m-d'),
            ]);
        }

        return response()->json([
            'valid' => true,
            'status' => 'valid',
            'message' => 'ETA verified successfully',
            'document' => [
                'type' => 'eta',
                'eta_number' => $eta->eta_number,
                'reference_number' => $eta->reference_number,
                'holder_name' => Crypt::decryptString($eta->first_name_encrypted) . ' ' . Crypt::decryptString($eta->last_name_encrypted),
                'nationality' => Crypt::decryptString($eta->nationality_encrypted),
                'passport_number_masked' => substr($storedPassport, 0, 3) . '****',
                'entry_type' => $eta->entry_type,
                'valid_until' => $eta->expires_at?->format('Y-m-d'),
                'approved_on' => $eta->approved_at?->format('Y-m-d'),
            ],
            'previous_entries' => $this->getPreviousEntries(null, $eta->id),
        ]);
    }

    /**
     * Record border crossing (entry/exit).
     */
    public function recordCrossing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'crossing_type' => 'required|in:entry,exit',
            'port_of_entry' => 'required|string|max:100',
            'document_type' => 'required|in:evisa,eta',
            'application_id' => 'nullable|integer|exists:applications,id',
            'eta_application_id' => 'nullable|integer|exists:eta_applications,id',
            'passport_number' => 'required|string',
            'traveler_name' => 'required|string',
            'nationality' => 'nullable|string|size:2',
            'verification_status' => 'required|in:valid,invalid,expired,not_found,secondary_inspection',
            'verification_notes' => 'nullable|string|max:1000',
            'flight_number' => 'nullable|string|max:20',
            'airline' => 'nullable|string|max:100',
        ]);

        $notesUpper = strtoupper($validated['verification_notes'] ?? '');
        $isSupervisorOverride = str_contains($notesUpper, 'BP-A03') || str_contains($notesUpper, 'OVERRIDE');
        $userRole = $request->user()?->role;

        if ($isSupervisorOverride && !in_array($userRole, ['border_supervisor', 'admin'], true)) {
            return response()->json([
                'message' => 'Supervisor override requires border_supervisor or admin role',
            ], 403);
        }

        $crossing = BorderCrossing::create([
            'application_id' => $validated['application_id'] ?? null,
            'eta_application_id' => $validated['eta_application_id'] ?? null,
            'crossing_type' => $validated['crossing_type'],
            'port_of_entry' => $validated['port_of_entry'],
            'passport_number_encrypted' => Crypt::encryptString($validated['passport_number']),
            'nationality' => $validated['nationality'] ?? null,
            'traveler_name_encrypted' => Crypt::encryptString($validated['traveler_name']),
            'verification_status' => $validated['verification_status'],
            'verification_notes' => $validated['verification_notes'] ?? null,
            'flight_number' => $validated['flight_number'] ?? null,
            'airline' => $validated['airline'] ?? null,
            'officer_id' => $request->user()?->id,
            'crossed_at' => now(),
        ]);

        $auditChecksum = hash('sha256', implode('|', [
            $crossing->id,
            $crossing->crossing_type,
            $crossing->port_of_entry,
            $crossing->verification_status,
            $crossing->crossed_at?->toIso8601String(),
            config('app.key'),
        ]));

        Log::info('border_crossing_audit', [
            'crossing_id' => $crossing->id,
            'officer_id' => $crossing->officer_id,
            'port' => $crossing->port_of_entry,
            'status' => $crossing->verification_status,
            'audit_checksum' => $auditChecksum,
        ]);

        $incident = null;
        if (in_array($crossing->verification_status, ['invalid', 'expired', 'not_found'], true)) {
            $incident = [
                'incident_id' => 'INC-' . now()->format('YmdHis') . '-' . $crossing->id,
                'status' => 'open',
                'type' => 'red_outcome',
                'created_at' => now()->toIso8601String(),
            ];

            Log::warning('border_incident_created', [
                'crossing_id' => $crossing->id,
                'incident_id' => $incident['incident_id'],
                'port' => $crossing->port_of_entry,
                'officer_id' => $crossing->officer_id,
            ]);
        }

        return response()->json([
            'message' => ucfirst($validated['crossing_type']) . ' recorded successfully',
            'crossing' => [
                'id' => $crossing->id,
                'crossing_type' => $crossing->crossing_type,
                'port_of_entry' => $crossing->port_of_entry,
                'verification_status' => $crossing->verification_status,
                'crossed_at' => $crossing->crossed_at->format('Y-m-d H:i:s'),
            ],
            'audit_checksum' => $auditChecksum,
            'incident' => $incident,
        ], 201);
    }

    /**
     * Get border crossing statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        $port = $request->query('port');
        $date = $request->query('date', today()->format('Y-m-d'));

        $query = BorderCrossing::whereDate('crossed_at', $date);

        if ($port) {
            $query->where('port_of_entry', $port);
        }

        $entries = (clone $query)->where('crossing_type', 'entry')->count();
        $exits = (clone $query)->where('crossing_type', 'exit')->count();

        $byStatus = (clone $query)
            ->selectRaw('verification_status, COUNT(*) as count')
            ->groupBy('verification_status')
            ->pluck('count', 'verification_status');

        $byNationality = (clone $query)
            ->selectRaw('nationality, COUNT(*) as count')
            ->groupBy('nationality')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'nationality');

        return response()->json([
            'date' => $date,
            'port' => $port ?? 'all',
            'entries' => $entries,
            'exits' => $exits,
            'total' => $entries + $exits,
            'by_status' => $byStatus,
            'by_nationality' => $byNationality,
        ]);
    }

    /**
     * Arrivals report by port/flight.
     */
    public function arrivalsReport(Request $request): JsonResponse
    {
        $port = $request->query('port');
        $date = $request->query('date', today()->format('Y-m-d'));

        $query = BorderCrossing::whereDate('crossed_at', $date)
            ->where('crossing_type', 'entry');

        if ($port) {
            $query->where('port_of_entry', $port);
        }

        $byHour = (clone $query)
            ->selectRaw('HOUR(crossed_at) as hour, COUNT(*) as count')
            ->groupBy('hour')
            ->orderBy('hour')
            ->pluck('count', 'hour');

        $byFlight = (clone $query)
            ->whereNotNull('flight_number')
            ->selectRaw('flight_number, airline, COUNT(*) as count')
            ->groupBy('flight_number', 'airline')
            ->orderByDesc('count')
            ->limit(20)
            ->get();

        $byPort = (clone $query)
            ->selectRaw('port_of_entry, COUNT(*) as count')
            ->groupBy('port_of_entry')
            ->pluck('count', 'port_of_entry');

        $peakHour = $byHour->sortDesc()->keys()->first();

        return response()->json([
            'date' => $date,
            'total_arrivals' => $query->count(),
            'by_hour' => $byHour,
            'by_flight' => $byFlight,
            'by_port' => $byPort,
            'peak_hour' => $peakHour,
        ]);
    }

    /**
     * Entry outcomes report (admit vs secondary vs deny).
     */
    public function outcomesReport(Request $request): JsonResponse
    {
        $port = $request->query('port');
        $startDate = $request->query('start_date', today()->subDays(7)->format('Y-m-d'));
        $endDate = $request->query('end_date', today()->format('Y-m-d'));

        $query = BorderCrossing::whereBetween('crossed_at', [$startDate, $endDate . ' 23:59:59']);

        if ($port) {
            $query->where('port_of_entry', $port);
        }

        $byStatus = (clone $query)
            ->selectRaw('verification_status, COUNT(*) as count')
            ->groupBy('verification_status')
            ->pluck('count', 'verification_status');

        $total = $byStatus->sum();
        $admitRate = $total > 0 ? round(($byStatus['valid'] ?? 0) / $total * 100, 1) : 0;
        $secondaryRate = $total > 0 ? round(($byStatus['secondary_inspection'] ?? 0) / $total * 100, 1) : 0;
        $denyRate = $total > 0 ? round(($byStatus['invalid'] ?? 0) / $total * 100, 1) : 0;

        return response()->json([
            'period' => ['start' => $startDate, 'end' => $endDate],
            'total' => $total,
            'by_status' => $byStatus,
            'rates' => [
                'admit' => $admitRate,
                'secondary' => $secondaryRate,
                'deny' => $denyRate,
            ],
        ]);
    }

    /**
     * Alerts and watchlist hits report.
     */
    public function alertsReport(Request $request): JsonResponse
    {
        $port = $request->query('port');
        $date = $request->query('date', today()->format('Y-m-d'));

        $query = BorderCrossing::whereDate('crossed_at', $date)
            ->where(function ($q) {
                $q->where('verification_status', 'invalid')
                  ->orWhere('verification_notes', 'like', '%watchlist%')
                  ->orWhere('verification_notes', 'like', '%BP-D03%');
            });

        if ($port) {
            $query->where('port_of_entry', $port);
        }

        $alerts = $query->orderBy('crossed_at', 'desc')->limit(50)->get()->map(fn($c) => [
            'id' => $c->id,
            'traveler_name' => $c->traveler_name,
            'nationality' => $c->nationality,
            'port' => $c->port_of_entry,
            'status' => $c->verification_status,
            'notes' => $c->verification_notes,
            'time' => $c->crossed_at->format('H:i:s'),
        ]);

        return response()->json([
            'date' => $date,
            'total_alerts' => $alerts->count(),
            'watchlist_hits' => $alerts->filter(fn($a) => str_contains($a['notes'] ?? '', 'BP-D03'))->count(),
            'fraud_flags' => $alerts->filter(fn($a) => str_contains($a['notes'] ?? '', 'BP-D04'))->count(),
            'alerts' => $alerts,
        ]);
    }

    /**
     * Officer productivity report.
     */
    public function productivityReport(Request $request): JsonResponse
    {
        $port = $request->query('port');
        $date = $request->query('date', today()->format('Y-m-d'));

        $query = BorderCrossing::whereDate('crossed_at', $date);

        if ($port) {
            $query->where('port_of_entry', $port);
        }

        $byOfficer = (clone $query)
            ->whereNotNull('officer_id')
            ->selectRaw('officer_id, COUNT(*) as cases_processed')
            ->groupBy('officer_id')
            ->with('officer:id,first_name,last_name')
            ->orderByDesc('cases_processed')
            ->get()
            ->map(fn($r) => [
                'officer_id' => $r->officer_id,
                'officer_name' => $r->officer?->full_name ?? 'Unknown',
                'cases_processed' => $r->cases_processed,
            ]);

        $totalCases = $query->count();
        $avgPerOfficer = $byOfficer->count() > 0 ? round($totalCases / $byOfficer->count(), 1) : 0;

        return response()->json([
            'date' => $date,
            'total_cases' => $totalCases,
            'officers_active' => $byOfficer->count(),
            'avg_per_officer' => $avgPerOfficer,
            'by_officer' => $byOfficer,
        ]);
    }

    /**
     * Exceptions report (overrides, downtime, offline usage).
     */
    public function exceptionsReport(Request $request): JsonResponse
    {
        $port = $request->query('port');
        $date = $request->query('date', today()->format('Y-m-d'));

        $query = BorderCrossing::whereDate('crossed_at', $date)
            ->where(function ($q) {
                $q->where('verification_notes', 'like', '%BP-A03%')
                  ->orWhere('verification_notes', 'like', '%override%');
            });

        if ($port) {
            $query->where('port_of_entry', $port);
        }

        $overrides = $query->with('officer:id,first_name,last_name')
            ->orderBy('crossed_at', 'desc')
            ->get()
            ->map(fn($c) => [
            'id' => $c->id,
            'traveler_name' => $c->traveler_name,
            'port' => $c->port_of_entry,
            'officer' => $c->officer?->full_name,
            'notes' => $c->verification_notes,
            'time' => $c->crossed_at->format('H:i:s'),
        ]);

        return response()->json([
            'date' => $date,
            'total_overrides' => $overrides->count(),
            'overrides' => $overrides,
            'system_downtime' => 0,
            'offline_usage' => 0,
        ]);
    }

    /**
     * Get recent crossings for dashboard.
     */
    public function recentCrossings(Request $request): JsonResponse
    {
        $port = $request->query('port');
        $limit = min($request->query('limit', 50), 100);

        $query = BorderCrossing::with('officer:id,first_name,last_name')
            ->orderBy('crossed_at', 'desc');

        if ($port) {
            $query->where('port_of_entry', $port);
        }

        $crossings = $query->limit($limit)->get()->map(fn($c) => [
            'id' => $c->id,
            'crossing_type' => $c->crossing_type,
            'port_of_entry' => $c->port_of_entry,
            'traveler_name' => $c->traveler_name,
            'passport_masked' => $c->passport_number_masked,
            'nationality' => $c->nationality,
            'verification_status' => $c->verification_status,
            'flight_number' => $c->flight_number,
            'officer' => $c->officer?->full_name,
            'crossed_at' => $c->crossed_at->format('Y-m-d H:i:s'),
        ]);

        return response()->json(['crossings' => $crossings]);
    }

    /**
     * Get previous entries for an application.
     */
    protected function getPreviousEntries(?int $applicationId, ?int $etaApplicationId): array
    {
        $query = BorderCrossing::where('crossing_type', 'entry');

        if ($applicationId) {
            $query->where('application_id', $applicationId);
        } elseif ($etaApplicationId) {
            $query->where('eta_application_id', $etaApplicationId);
        } else {
            return [];
        }

        return $query->orderBy('crossed_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn($c) => [
                'port' => $c->port_of_entry,
                'date' => $c->crossed_at->format('Y-m-d'),
            ])
            ->toArray();
    }

    /**
     * Get list of ports of entry.
     */
    public function ports(): JsonResponse
    {
        $ports = [
            ['code' => 'KIA', 'name' => 'Kotoka International Airport', 'type' => 'air'],
            ['code' => 'ACC', 'name' => 'Accra (Tema Port)', 'type' => 'sea'],
            ['code' => 'TKD', 'name' => 'Takoradi Port', 'type' => 'sea'],
            ['code' => 'AFL', 'name' => 'Aflao Border', 'type' => 'land'],
            ['code' => 'ELB', 'name' => 'Elubo Border', 'type' => 'land'],
            ['code' => 'PKW', 'name' => 'Paga-Kulungugu-Wa Border', 'type' => 'land'],
        ];

        return response()->json(['ports' => $ports]);
    }

    /**
     * Verify QR code at border entry point.
     */
    public function verifyQr(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_data' => 'required|string',
            'port_of_entry' => 'required|string',
            'passport_number' => 'nullable|string',
        ]);

        $qrService = app(QrCodeService::class);
        $result = $qrService->verifyQrCode($validated['qr_data']);

        if (!$result['valid']) {
            return response()->json([
                'verified' => false,
                'status' => 'invalid',
                'message' => $result['error'] ?? 'QR verification failed',
                'action' => 'DENY_ENTRY',
            ], 400);
        }

        // Additional passport verification if provided
        if (!empty($validated['passport_number']) && isset($result['passport_number'])) {
            $storedPassport = $result['passport_number'] ?? '';
            if (strtoupper($storedPassport) !== strtoupper($validated['passport_number'])) {
                return response()->json([
                    'verified' => false,
                    'status' => 'mismatch',
                    'message' => 'Passport number does not match document',
                    'action' => 'SECONDARY_INSPECTION',
                ], 403);
            }
        }

        // Check for alerts/flags
        $alerts = [];
        if ($result['type'] === 'evisa') {
            $app = Application::where('reference_number', $result['reference_number'])->first();
            if ($app && $app->watchlist_flagged) {
                $alerts[] = ['type' => 'watchlist', 'message' => 'WATCHLIST FLAG - Refer to supervisor'];
            }
            if ($app && in_array($app->risk_level, ['high', 'critical'])) {
                $alerts[] = ['type' => 'risk', 'message' => 'HIGH RISK - Additional screening required'];
            }
        }

        return response()->json([
            'verified' => true,
            'status' => 'valid',
            'message' => 'Document verified successfully',
            'action' => empty($alerts) ? 'ADMIT_ENTRY' : 'SECONDARY_INSPECTION',
            'document' => $result,
            'alerts' => $alerts,
            'port_of_entry' => $validated['port_of_entry'],
            'verified_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Quick scan endpoint for high-volume processing.
     */
    public function quickScan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_data' => 'required|string',
        ]);

        $qrService = app(QrCodeService::class);
        $result = $qrService->verifyQrCode($validated['qr_data']);

        // Simplified response for quick processing
        return response()->json([
            'valid' => $result['valid'],
            'type' => $result['type'] ?? null,
            'holder' => $result['holder_name'] ?? null,
            'expires' => $result['valid_until'] ?? null,
            'action' => $result['valid'] ? 'PROCEED' : 'STOP',
        ]);
    }

    /**
     * Record entry with QR verification in one step.
     */
    public function verifyAndRecordEntry(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_data' => 'required|string',
            'port_of_entry' => 'required|string',
            'passport_number' => 'required|string',
            'flight_number' => 'nullable|string',
            'airline' => 'nullable|string',
        ]);

        $qrService = app(QrCodeService::class);
        $result = $qrService->verifyQrCode($validated['qr_data']);

        $verificationStatus = 'valid';
        $notes = null;

        if (!$result['valid']) {
            $verificationStatus = 'invalid';
            $notes = $result['error'] ?? 'QR verification failed';
        }

        // Determine application/ETA IDs
        $applicationId = null;
        $etaApplicationId = null;
        $travelerName = 'Unknown';
        $nationality = null;

        if ($result['valid']) {
            $travelerName = $result['holder_name'] ?? 'Unknown';
            $nationality = $result['nationality'] ?? null;

            if ($result['type'] === 'evisa') {
                $app = Application::where('reference_number', $result['reference_number'])->first();
                $applicationId = $app?->id;
            } elseif ($result['type'] === 'eta') {
                $eta = EtaApplication::where('eta_number', $result['eta_number'])->first();
                $etaApplicationId = $eta?->id;
            }
        }

        // Record the crossing
        $crossing = BorderCrossing::create([
            'application_id' => $applicationId,
            'eta_application_id' => $etaApplicationId,
            'crossing_type' => 'entry',
            'port_of_entry' => $validated['port_of_entry'],
            'passport_number_encrypted' => Crypt::encryptString($validated['passport_number']),
            'nationality' => $nationality,
            'traveler_name_encrypted' => Crypt::encryptString($travelerName),
            'verification_status' => $verificationStatus,
            'verification_notes' => $notes,
            'flight_number' => $validated['flight_number'] ?? null,
            'airline' => $validated['airline'] ?? null,
            'officer_id' => $request->user()?->id,
            'crossed_at' => now(),
        ]);

        return response()->json([
            'success' => $result['valid'],
            'verification' => $result,
            'crossing' => [
                'id' => $crossing->id,
                'status' => $verificationStatus,
                'recorded_at' => $crossing->crossed_at->toIso8601String(),
            ],
            'action' => $result['valid'] ? 'ENTRY_RECORDED' : 'ENTRY_DENIED',
        ], $result['valid'] ? 201 : 400);
    }
}
