<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ActivityLogService
{
    /**
     * Fields yang tidak boleh disimpan ke log (sensitif)
     */
    protected array $hiddenFields = ['password', 'remember_token', 'email_verified_at'];

    /**
     * Log untuk event updated - menyimpan old & new + diff
     */
    public function logUpdated(Model $subject, array $oldData, array $newData, ?string $description = null): ActivityLog
    {
        $old = $this->filterHidden($oldData);
        $new = $this->filterHidden($newData);
        $diff = $this->calculateDiff($old, $new);

        return $this->store(
            logName: $this->resolveLogName($subject),
            event: 'updated',
            description: $description ?? $this->defaultDescription($subject, 'updated'),
            subject: $subject,
            properties: [
                'old' => $old,
                'new' => $new,
                'attributes' => $new, // alias
                'diff' => $diff,
            ]
        );
    }

    /**
     * Log untuk event deleted - menyimpan snapshot sebelum hapus
     */
    public function logDeleted(Model $subject, ?string $description = null): ActivityLog
    {
        $old = $this->filterHidden($subject->toArray());

        return $this->store(
            logName: $this->resolveLogName($subject),
            event: 'deleted',
            description: $description ?? $this->defaultDescription($subject, 'deleted'),
            subject: $subject,
            properties: [
                'old' => $old,
                'attributes' => $old,
            ]
        );
    }

    /**
     * Log generic (bisa untuk created dll)
     */
    public function log(Model $subject, string $event, array $properties = [], ?string $description = null): ActivityLog
    {
        return $this->store(
            logName: $this->resolveLogName($subject),
            event: $event,
            description: $description ?? $this->defaultDescription($subject, $event),
            subject: $subject,
            properties: $properties
        );
    }

    protected function store(string $logName, string $event, ?string $description, Model $subject, array $properties): ActivityLog
    {
        $causer = Auth::user();
        $request = request();

        return ActivityLog::create([
            'log_name' => $logName,
            'event' => $event,
            'description' => $description,
            'subject_type' => get_class($subject),
            'subject_id' => $subject->getKey(),
            'causer_type' => $causer ? get_class($causer) : null,
            'causer_id' => $causer?->getKey(),
            'properties' => $properties,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'batch_uuid' => (string) Str::uuid(),
        ]);
    }

    protected function resolveLogName(Model $subject): string
    {
        // user -> user, OrganizationUnit -> organization_unit
        return Str::snake(class_basename($subject));
    }

    protected function defaultDescription(Model $subject, string $event): string
    {
        $name = $subject->name ?? $subject->username ?? $subject->title ?? "#{$subject->getKey()}";
        return ucfirst($event) . " " . class_basename($subject) . " {$name}";
    }

    protected function filterHidden(array $data): array
    {
        return array_diff_key($data, array_flip($this->hiddenFields));
    }

    protected function calculateDiff(array $old, array $new): array
    {
        $diff = [];
        foreach ($new as $key => $value) {
            $oldValue = $old[$key] ?? null;
            if ($oldValue !== $value) {
                $diff[$key] = ['old' => $oldValue, 'new' => $value];
            }
        }
        // field yang dihapus
        foreach ($old as $key => $value) {
            if (!array_key_exists($key, $new)) {
                $diff[$key] = ['old' => $value, 'new' => null];
            }
        }
        return $diff;
    }
}
