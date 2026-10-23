<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

class ActivityLog extends Model
{
    protected $fillable = [
        'log_name',
        'event',
        'description',
        'subject_type',
        'subject_id',
        'causer_type',
        'causer_id',
        'properties',
        'ip_address',
        'user_agent',
        'batch_uuid',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Kelas model subject yang masih tersedia di codebase, atau null
     * bila tipe lama menunjuk model yang sudah dihapus/di-rename.
     */
    public static function subjectClass(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class;
    }

    public function hasResolvableSubject(): bool
    {
        return self::subjectClass($this->subject_type) !== null;
    }

    /**
     * Accessor aman untuk subject: baris log lama bisa menunjuk model
     * yang sudah tidak ada (mis. modul UnitCost). Kembalikan null
     * daripada melempar "Class not found" saat halaman dibuka.
     */
    public function getSubjectAttribute()
    {
        if (! $this->hasResolvableSubject()) {
            return null;
        }

        return $this->getRelationValue('subject');
    }

    /**
     * Scope untuk filter by log_name
     */
    public function scopeInLog($query, string $logName)
    {
        return $query->where('log_name', $logName);
    }

    public function scopeForSubject($query, Model $subject)
    {
        return $query->where('subject_type', get_class($subject))
                     ->where('subject_id', $subject->getKey());
    }
}
