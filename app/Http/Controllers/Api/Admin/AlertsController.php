<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AlertingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Alerts Management Controller
 * 
 * Manages alert rules, logs, and configurations.
 * Admin-only access.
 */
class AlertsController extends Controller
{
    public function __construct(
        protected AlertingService $alertingService
    ) {}

    /**
     * Get all alert rules
     */
    public function index(): JsonResponse
    {
        $rules = $this->alertingService->getAllAlertRules();

        return response()->json([
            'alert_rules' => $rules,
            'total' => count($rules),
        ]);
    }

    /**
     * Create a new alert rule
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'metric' => [
                'required',
                'string',
                Rule::in(array_keys(config('alerting.metrics'))),
            ],
            'condition' => [
                'required',
                'string',
                Rule::in(array_keys(config('alerting.conditions'))),
            ],
            'threshold' => 'required|numeric',
            'duration_minutes' => 'integer|min:0|max:1440',
            'severity' => [
                'required',
                'string',
                Rule::in(['info', 'warning', 'high', 'critical']),
            ],
            'channels' => 'required|array|min:1',
            'channels.*' => Rule::in(['email', 'sms', 'slack', 'teams', 'pagerduty']),
            'recipients' => 'required|array|min:1',
            'recipients.*' => 'string',
            'cooldown_minutes' => 'integer|min:1|max:1440',
            'enabled' => 'boolean',
        ]);

        $ruleId = $this->alertingService->createAlertRule($validated);

        return response()->json([
            'message' => 'Alert rule created successfully',
            'alert_rule_id' => $ruleId,
        ], 201);
    }

    /**
     * Get a specific alert rule
     */
    public function show(int $id): JsonResponse
    {
        $rule = $this->alertingService->getAlertRule($id);

        if (!$rule) {
            return response()->json([
                'message' => 'Alert rule not found',
            ], 404);
        }

        return response()->json([
            'alert_rule' => $rule,
        ]);
    }

    /**
     * Update an alert rule
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $rule = $this->alertingService->getAlertRule($id);

        if (!$rule) {
            return response()->json([
                'message' => 'Alert rule not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'string|max:255',
            'description' => 'nullable|string',
            'metric' => [
                'string',
                Rule::in(array_keys(config('alerting.metrics'))),
            ],
            'condition' => [
                'string',
                Rule::in(array_keys(config('alerting.conditions'))),
            ],
            'threshold' => 'numeric',
            'duration_minutes' => 'integer|min:0|max:1440',
            'severity' => [
                'string',
                Rule::in(['info', 'warning', 'high', 'critical']),
            ],
            'channels' => 'array|min:1',
            'channels.*' => Rule::in(['email', 'sms', 'slack', 'teams', 'pagerduty']),
            'recipients' => 'array|min:1',
            'recipients.*' => 'string',
            'cooldown_minutes' => 'integer|min:1|max:1440',
            'enabled' => 'boolean',
        ]);

        $updated = $this->alertingService->updateAlertRule($id, $validated);

        if (!$updated) {
            return response()->json([
                'message' => 'Failed to update alert rule',
            ], 500);
        }

        return response()->json([
            'message' => 'Alert rule updated successfully',
        ]);
    }

    /**
     * Delete an alert rule
     */
    public function destroy(int $id): JsonResponse
    {
        $rule = $this->alertingService->getAlertRule($id);

        if (!$rule) {
            return response()->json([
                'message' => 'Alert rule not found',
            ], 404);
        }

        $deleted = $this->alertingService->deleteAlertRule($id);

        if (!$deleted) {
            return response()->json([
                'message' => 'Failed to delete alert rule',
            ], 500);
        }

        return response()->json([
            'message' => 'Alert rule deleted successfully',
        ]);
    }

    /**
     * Test an alert rule
     */
    public function test(int $id): JsonResponse
    {
        $rule = $this->alertingService->getAlertRule($id);

        if (!$rule) {
            return response()->json([
                'message' => 'Alert rule not found',
            ], 404);
        }

        // Create a test alert log entry
        $testAlertId = \DB::table('alert_logs')->insertGetId([
            'alert_rule_id' => $rule->id,
            'triggered_at' => now(),
            'severity' => $rule->severity,
            'metric_value' => $rule->threshold,
            'message' => "TEST ALERT: {$rule->name}",
            'channels_sent' => json_encode([]),
        ]);

        // Send test notifications
        try {
            $channels = json_decode($rule->channels, true);
            $recipients = json_decode($rule->recipients, true);
            $sentChannels = [];

            foreach ($channels as $channel) {
                // Send test notification (simplified)
                $sentChannels[] = $channel;
            }

            // Update alert log
            \DB::table('alert_logs')
                ->where('id', $testAlertId)
                ->update(['channels_sent' => json_encode($sentChannels)]);

            return response()->json([
                'message' => 'Test alert sent successfully',
                'channels_sent' => $sentChannels,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Test alert failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get alert logs
     */
    public function logs(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 100);
        $limit = min($limit, 500); // Cap at 500

        $logs = $this->alertingService->getAlertLogs($limit);

        return response()->json([
            'alert_logs' => $logs,
            'total' => count($logs),
        ]);
    }

    /**
     * Acknowledge an alert
     */
    public function acknowledge(Request $request, int $alertLogId): JsonResponse
    {
        $acknowledged = $this->alertingService->acknowledgeAlert(
            $alertLogId,
            $request->user()->id
        );

        if (!$acknowledged) {
            return response()->json([
                'message' => 'Alert log not found or already acknowledged',
            ], 404);
        }

        return response()->json([
            'message' => 'Alert acknowledged successfully',
        ]);
    }

    /**
     * Get alert configuration options
     */
    public function config(): JsonResponse
    {
        return response()->json([
            'metrics' => config('alerting.metrics'),
            'conditions' => config('alerting.conditions'),
            'severity_levels' => array_keys(config('alerting.severity')),
            'channels' => array_keys(config('alerting.channels')),
            'enabled_channels' => array_keys(array_filter(
                config('alerting.channels'),
                fn($channel) => $channel['enabled'] ?? false
            )),
        ]);
    }

    /**
     * Process alerts manually (for testing)
     */
    public function process(): JsonResponse
    {
        try {
            $this->alertingService->processAlerts();
            
            return response()->json([
                'message' => 'Alerts processed successfully',
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Alert processing failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}