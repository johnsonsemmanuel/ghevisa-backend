<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            self::logAudit('created', $model, null, $model->getAttributes());
        });

        static::updated(function ($model) {
            $original = $model->getOriginal();
            $changes = $model->getChanges();
            self::logAudit('updated', $model, $original, $changes);
        });

        static::deleted(function ($model) {
            self::logAudit('deleted', $model, $model->getAttributes(), null);
        });
    }

    protected static function logAudit(string $action, $model, ?array $oldValues, ?array $newValues): void
    {
        try {
            AuditLog::create([
                'user_id'        => Auth::id(),
                'action'         => class_basename($model) . '.' . $action,
                'auditable_type' => get_class($model),
                'auditable_id'   => $model->getKey(),
                'old_values'     => $oldValues,
                'new_values'     => $newValues,
                'ip_address'     => Request::ip(),
                'user_agent'     => Request::userAgent(),
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Audit log failed: ' . $e->getMessage());
        }
    }

    /**
     * SEC-04/COMP-01: Explicitly log a data access event (read/view/download).
     */
    public function logAccess(string $action = 'viewed'): void
    {
        static::logAudit($action, $this, null, null);
    }
}
