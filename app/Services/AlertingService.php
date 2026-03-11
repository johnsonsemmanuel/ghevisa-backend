<?php

namespace App\Services;

use App\Jobs\SendNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class AlertingService
{
    /**
     * Process and send alerts based on current system state
     */
    public function processAlerts(): void
    {
        $activeRules = $this->getActiveAlertRules();
        
        foreach ($activeRules as $rule) {
            $this->evaluateAlertRule($rule);
        }
    }

    /**
     * Evaluate a single alert rule
     */
    private function evaluateAlertRule(object $rule): void
    {
        try {
            $currentValue = $this->getCurrentMetricValue($rule->metric);
            $shouldTrigger = $this->shouldTriggerAlert($rule, $currentValue);
            
            if ($shouldTrigger) {
                $this->triggerAlert($rule, $currentValue);
            }
        } catch (\Exception $e) {
            Log::error('Failed to evaluate alert rule', [
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if alert should be triggered
     */
    private function shouldTriggerAlert(object $rule, float $currentValue): bool
    {
        // Check if condition is met
        $conditionMet = match($rule->condition) {
            '>' => $currentValue > $rule->threshold,
            '<' => $currentValue < $rule->threshold,
            '>=' => $currentValue >= $rule->threshold,
            '<=' => $currentValue <= $rule->threshold,
            '==' => abs($currentValue - $rule->threshold) < 0.001,
            default => false,
        };

        if (!$conditionMet) {
            return false;
        }

        // Check duration requirement
        if ($rule->duration_minutes > 0) {
            $durationMet = $this->checkDurationRequirement($rule, $currentValue);
            if (!$durationMet) {
                return false;
            }
        }

        // Check cooldown period
        if ($this->isInCooldownPeriod($rule)) {
            return false;
        }

        return true;
    }

    /**
     * Check if condition has been met for required duration
     */
    private function checkDurationRequirement(object $rule, float $currentValue): bool
    {
        $startTime = now()->subMinutes($rule->duration_minutes);
        
        // This is a simplified check - in production, you'd want to store
        // metric values over time to properly validate duration
        return true;
    }

    /**
     * Check if alert is in cooldown period
     */
    private function isInCooldownPeriod(object $rule): bool
    {
        $lastAlert = DB::table('alert_logs')
            ->where('alert_rule_id', $rule->id)
            ->where('triggered_at', '>=', now()->subMinutes($rule->cooldown_minutes))
            ->orderBy('triggered_at', 'desc')
            ->first();

        return $lastAlert !== null;
    }

    /**
     * Trigger an alert
     */
    private function triggerAlert(object $rule, float $currentValue): void
    {
        // Log the alert
        $alertLogId = DB::table('alert_logs')->insertGetId([
            'alert_rule_id' => $rule->id,
            'triggered_at' => now(),
            'severity' => $rule->severity,
            'metric_value' => $currentValue,
            'message' => $this->generateAlertMessage($rule, $currentValue),
            'channels_sent' => json_encode([]),
        ]);

        // Send notifications through configured channels
        $channels = json_decode($rule->channels, true);
        $recipients = json_decode($rule->recipients, true);
        $sentChannels = [];

        foreach ($channels as $channel) {
            try {
                $this->sendAlertNotification($channel, $rule, $currentValue, $recipients);
                $sentChannels[] = $channel;
            } catch (\Exception $e) {
                Log::error('Failed to send alert notification', [
                    'channel' => $channel,
                    'rule_id' => $rule->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Update alert log with sent channels
        DB::table('alert_logs')
            ->where('id', $alertLogId)
            ->update(['channels_sent' => json_encode($sentChannels)]);

        Log::info('Alert triggered', [
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'metric_value' => $currentValue,
            'threshold' => $rule->threshold,
            'channels' => $sentChannels,
        ]);
    }

    /**
     * Send alert notification through specific channel
     */
    private function sendAlertNotification(string $channel, object $rule, float $currentValue, array $recipients): void
    {
        $message = $this->generateAlertMessage($rule, $currentValue);
        
        switch ($channel) {
            case 'email':
                $this->sendEmailAlert($rule, $message, $recipients);
                break;
            case 'sms':
                $this->sendSmsAlert($rule, $message, $recipients);
                break;
            case 'slack':
                $this->sendSlackAlert($rule, $message);
                break;
            case 'teams':
                $this->sendTeamsAlert($rule, $message);
                break;
            case 'pagerduty':
                $this->sendPagerDutyAlert($rule, $message);
                break;
            default:
                Log::warning('Unknown alert channel', ['channel' => $channel]);
        }
    }

    /**
     * Send email alert
     */
    private function sendEmailAlert(object $rule, string $message, array $recipients): void
    {
        $emailRecipients = array_filter($recipients, fn($r) => filter_var($r, FILTER_VALIDATE_EMAIL));
        
        foreach ($emailRecipients as $email) {
            Queue::push(new SendNotification([
                'type' => 'email',
                'recipient' => $email,
                'subject' => "Alert: {$rule->name}",
                'message' => $message,
                'severity' => $rule->severity,
            ]));
        }
    }

    /**
     * Send SMS alert
     */
    private function sendSmsAlert(object $rule, string $message, array $recipients): void
    {
        $phoneRecipients = array_filter($recipients, fn($r) => preg_match('/^\+?[1-9]\d{1,14}$/', $r));
        
        foreach ($phoneRecipients as $phone) {
            Queue::push(new SendNotification([
                'type' => 'sms',
                'recipient' => $phone,
                'message' => $message,
                'severity' => $rule->severity,
            ]));
        }
    }

    /**
     * Send Slack alert
     */
    private function sendSlackAlert(object $rule, string $message): void
    {
        $webhookUrl = config('alerting.channels.slack.webhook_url');
        
        if (!$webhookUrl) {
            throw new \Exception('Slack webhook URL not configured');
        }

        $payload = [
            'text' => "🚨 Alert: {$rule->name}",
            'attachments' => [
                [
                    'color' => $this->getSeverityColor($rule->severity),
                    'fields' => [
                        [
                            'title' => 'Message',
                            'value' => $message,
                            'short' => false,
                        ],
                        [
                            'title' => 'Severity',
                            'value' => strtoupper($rule->severity),
                            'short' => true,
                        ],
                        [
                            'title' => 'Time',
                            'value' => now()->toDateTimeString(),
                            'short' => true,
                        ],
                    ],
                ],
            ],
        ];

        $this->sendWebhook($webhookUrl, $payload);
    }

    /**
     * Send Teams alert
     */
    private function sendTeamsAlert(object $rule, string $message): void
    {
        $webhookUrl = config('alerting.channels.teams.webhook_url');
        
        if (!$webhookUrl) {
            throw new \Exception('Teams webhook URL not configured');
        }

        $payload = [
            '@type' => 'MessageCard',
            '@context' => 'http://schema.org/extensions',
            'themeColor' => $this->getSeverityColor($rule->severity),
            'summary' => "Alert: {$rule->name}",
            'sections' => [
                [
                    'activityTitle' => "🚨 Alert: {$rule->name}",
                    'activitySubtitle' => "Severity: " . strtoupper($rule->severity),
                    'facts' => [
                        [
                            'name' => 'Message',
                            'value' => $message,
                        ],
                        [
                            'name' => 'Time',
                            'value' => now()->toDateTimeString(),
                        ],
                    ],
                ],
            ],
        ];

        $this->sendWebhook($webhookUrl, $payload);
    }

    /**
     * Send PagerDuty alert
     */
    private function sendPagerDutyAlert(object $rule, string $message): void
    {
        $integrationKey = config('alerting.channels.pagerduty.integration_key');
        
        if (!$integrationKey) {
            throw new \Exception('PagerDuty integration key not configured');
        }

        $payload = [
            'routing_key' => $integrationKey,
            'event_action' => 'trigger',
            'payload' => [
                'summary' => "Alert: {$rule->name}",
                'source' => 'Ghana eVisa System',
                'severity' => $rule->severity,
                'custom_details' => [
                    'message' => $message,
                    'rule_name' => $rule->name,
                    'timestamp' => now()->toIso8601String(),
                ],
            ],
        ];

        $this->sendWebhook('https://events.pagerduty.com/v2/enqueue', $payload);
    }

    /**
     * Send webhook request
     */
    private function sendWebhook(string $url, array $payload): void
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \Exception("Webhook request failed with HTTP {$httpCode}: {$response}");
        }
    }

    /**
     * Get current metric value
     */
    private function getCurrentMetricValue(string $metric): float
    {
        return match($metric) {
            'average_response_time' => $this->getAverageResponseTime(),
            'success_rate' => $this->getSuccessRate(),
            'slow_request_percentage' => $this->getSlowRequestPercentage(),
            'failure_rate' => $this->getFailureRate(),
            'request_volume' => $this->getRequestVolume(),
            default => 0.0,
        };
    }

    /**
     * Get average response time for current hour
     */
    private function getAverageResponseTime(): float
    {
        $result = DB::table('verification_performance_logs')
            ->selectRaw('AVG(response_time) as avg_time')
            ->where('created_at', '>=', now()->startOfHour())
            ->first();

        return (float) ($result->avg_time ?? 0);
    }

    /**
     * Get success rate for current hour
     */
    private function getSuccessRate(): float
    {
        $result = DB::table('verification_performance_logs')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful
            ')
            ->where('created_at', '>=', now()->startOfHour())
            ->first();

        if ($result->total == 0) {
            return 100.0;
        }

        return ($result->successful / $result->total) * 100;
    }

    /**
     * Get slow request percentage for current hour
     */
    private function getSlowRequestPercentage(): float
    {
        $result = DB::table('verification_performance_logs')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN response_time > 2.0 THEN 1 ELSE 0 END) as slow
            ')
            ->where('created_at', '>=', now()->startOfHour())
            ->first();

        if ($result->total == 0) {
            return 0.0;
        }

        return ($result->slow / $result->total) * 100;
    }

    /**
     * Get failure rate for current hour
     */
    private function getFailureRate(): float
    {
        return 100.0 - $this->getSuccessRate();
    }

    /**
     * Get request volume for current hour
     */
    private function getRequestVolume(): float
    {
        $result = DB::table('verification_performance_logs')
            ->selectRaw('COUNT(*) as volume')
            ->where('created_at', '>=', now()->startOfHour())
            ->first();

        return (float) ($result->volume ?? 0);
    }

    /**
     * Get active alert rules
     */
    private function getActiveAlertRules(): array
    {
        return DB::table('alert_rules')
            ->where('enabled', true)
            ->get()
            ->toArray();
    }

    /**
     * Generate alert message
     */
    private function generateAlertMessage(object $rule, float $currentValue): string
    {
        return sprintf(
            '%s: Current value %.2f %s threshold %.2f',
            $rule->name,
            $currentValue,
            $rule->condition,
            $rule->threshold
        );
    }

    /**
     * Get severity color for notifications
     */
    private function getSeverityColor(string $severity): string
    {
        return match($severity) {
            'critical' => '#FF0000',
            'high' => '#FF8C00',
            'warning' => '#FFD700',
            'info' => '#00BFFF',
            default => '#808080',
        };
    }

    /**
     * Create a new alert rule
     */
    public function createAlertRule(array $data): int
    {
        return DB::table('alert_rules')->insertGetId([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'metric' => $data['metric'],
            'condition' => $data['condition'],
            'threshold' => $data['threshold'],
            'duration_minutes' => $data['duration_minutes'] ?? 0,
            'severity' => $data['severity'],
            'channels' => json_encode($data['channels']),
            'recipients' => json_encode($data['recipients']),
            'cooldown_minutes' => $data['cooldown_minutes'] ?? 30,
            'enabled' => $data['enabled'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Update alert rule
     */
    public function updateAlertRule(int $id, array $data): bool
    {
        $updateData = array_filter([
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'metric' => $data['metric'] ?? null,
            'condition' => $data['condition'] ?? null,
            'threshold' => $data['threshold'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'severity' => $data['severity'] ?? null,
            'channels' => isset($data['channels']) ? json_encode($data['channels']) : null,
            'recipients' => isset($data['recipients']) ? json_encode($data['recipients']) : null,
            'cooldown_minutes' => $data['cooldown_minutes'] ?? null,
            'enabled' => $data['enabled'] ?? null,
            'updated_at' => now(),
        ], fn($value) => $value !== null);

        return DB::table('alert_rules')
            ->where('id', $id)
            ->update($updateData) > 0;
    }

    /**
     * Delete alert rule
     */
    public function deleteAlertRule(int $id): bool
    {
        return DB::table('alert_rules')
            ->where('id', $id)
            ->delete() > 0;
    }

    /**
     * Get alert rule by ID
     */
    public function getAlertRule(int $id): ?object
    {
        return DB::table('alert_rules')
            ->where('id', $id)
            ->first();
    }

    /**
     * Get all alert rules
     */
    public function getAllAlertRules(): array
    {
        return DB::table('alert_rules')
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Get alert logs
     */
    public function getAlertLogs(int $limit = 100): array
    {
        return DB::table('alert_logs')
            ->join('alert_rules', 'alert_logs.alert_rule_id', '=', 'alert_rules.id')
            ->select([
                'alert_logs.*',
                'alert_rules.name as rule_name',
                'alert_rules.metric',
            ])
            ->orderBy('alert_logs.triggered_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Acknowledge alert
     */
    public function acknowledgeAlert(int $alertLogId, int $userId): bool
    {
        return DB::table('alert_logs')
            ->where('id', $alertLogId)
            ->update([
                'acknowledged_by' => $userId,
                'acknowledged_at' => now(),
            ]) > 0;
    }
}