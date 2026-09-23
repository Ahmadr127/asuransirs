<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Tarif extends Model
{
    use HasFactory;

    public const SURGERY_TYPES = ['SURGERY', 'NON SURGERY'];

    public const STATUSES = ['UPCOMING', 'ACTIVE', 'EXPIRED'];

    protected $fillable = [
        'jenis_tarif_id',
        'provider_id',
        'service_id',
        'class_id',
        'surgery_type',
        'helper',
        'tariff',
        'valid_date_from',
        'end_date_to',
    ];

    protected $casts = [
        'tariff' => 'decimal:2',
        'valid_date_from' => 'date',
        'end_date_to' => 'date',
    ];

    /**
     * @return BelongsTo<JenisTarif, $this>
     */
    public function jenisTarif(): BelongsTo
    {
        return $this->belongsTo(JenisTarif::class);
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<ServiceClass, $this>
     */
    public function serviceClass(): BelongsTo
    {
        return $this->belongsTo(ServiceClass::class, 'class_id');
    }

    /**
     * Status dihitung otomatis dari periode berlaku, tidak disimpan manual.
     * UPCOMING: today < valid_date_from
     * ACTIVE:   valid_date_from <= today <= end_date_to
     * EXPIRED:  today > end_date_to
     */
    public function getStatusAttribute(): string
    {
        $today = Carbon::today();
        $from = Carbon::parse($this->valid_date_from)->startOfDay();
        $to = Carbon::parse($this->end_date_to)->startOfDay();

        if ($today->lt($from)) {
            return 'UPCOMING';
        }

        if ($today->gt($to)) {
            return 'EXPIRED';
        }

        return 'ACTIVE';
    }

    public static function badgeClass(string $status): string
    {
        return match ($status) {
            'ACTIVE' => 'bg-green-100 text-green-800',
            'UPCOMING' => 'bg-blue-100 text-blue-800',
            'EXPIRED' => 'bg-red-100 text-red-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }
}
