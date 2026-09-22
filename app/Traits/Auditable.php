<?php

namespace App\Traits;

use App\Services\ActivityLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait Auditable
{
    /**
     * Boot trait - auto log updated & deleted (opsional).
     * Jika model pakai Service manual (seperti UserService), nonaktifkan auto ini
     * dengan set property $auditLogEnabled = false di model.
     */
    protected static function bootAuditable(): void
    {
        static::updated(function (Model $model) {
            if (property_exists($model, 'auditLogEnabled') && $model->auditLogEnabled === false) {
                return;
            }
            // Hindari double log jika sudah di-handle di Service
            if (app()->bound(ActivityLogService::class) && static::shouldAutoLog()) {
                $changes = $model->getChanges();
                // skip jika tidak ada perubahan signifikan selain updated_at
                $dirty = array_diff_key($changes, ['updated_at' => true]);
                if (empty($dirty)) return;

                $old = [];
                $new = [];
                foreach ($dirty as $key => $newValue) {
                    $old[$key] = $model->getOriginal($key);
                    $new[$key] = $newValue;
                }
                // ambil full snapshot untuk konteks
                $oldFull = array_merge($model->getOriginal(), $old);
                $newFull = array_merge($model->toArray(), $new);

                app(ActivityLogService::class)->logUpdated($model, $oldFull, $newFull);
            }
        });

        static::deleted(function (Model $model) {
            if (property_exists($model, 'auditLogEnabled') && $model->auditLogEnabled === false) {
                return;
            }
            if (app()->bound(ActivityLogService::class) && static::shouldAutoLog()) {
                app(ActivityLogService::class)->logDeleted($model);
            }
        });
    }

    protected static function shouldAutoLog(): bool
    {
        // Default false agar tidak double-log untuk User yang sudah pakai Service
        // Aktifkan per-model dengan override: protected static $autoAudit = true;
        return property_exists(static::class, 'autoAudit') && static::$autoAudit === true;
    }

    /**
     * @return MorphMany<\App\Models\ActivityLog, $this>
     */
    public function activityLogs(): MorphMany
    {
        return $this->morphMany(\App\Models\ActivityLog::class, 'subject')->latest();
    }
}
