<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportBatch extends Model
{
    use HasFactory;

    /**
     * State machine: UPLOAD -> QUEUE SCAN -> PREVIEW -> QUEUE IMPORT.
     * Scan dan import keduanya async; browser tidak menunggu Excel.
     */
    public const STATUS_PENDING_SCAN = 'pending_scan';

    public const STATUS_PROCESSING_SCAN = 'processing_scan';

    public const STATUS_SCAN_COMPLETED = 'scan_completed';

    public const STATUS_SCAN_FAILED = 'scan_failed';

    public const STATUS_PENDING_IMPORT = 'pending_import';

    public const STATUS_PROCESSING_IMPORT = 'processing_import';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** Alias kompatibilitas alur lama. */
    public const STATUS_PENDING = self::STATUS_PENDING_IMPORT;

    public const STATUS_PROCESSING = self::STATUS_PROCESSING_IMPORT;

    public const STATUSES = [
        self::STATUS_PENDING_SCAN,
        self::STATUS_PROCESSING_SCAN,
        self::STATUS_SCAN_COMPLETED,
        self::STATUS_SCAN_FAILED,
        self::STATUS_PENDING_IMPORT,
        self::STATUS_PROCESSING_IMPORT,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_SCAN_COMPLETED,
        self::STATUS_SCAN_FAILED,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    public const SCAN_ACTIVE_STATUSES = [
        self::STATUS_PENDING_SCAN,
        self::STATUS_PROCESSING_SCAN,
    ];

    public const IMPORT_ACTIVE_STATUSES = [
        self::STATUS_PENDING_IMPORT,
        self::STATUS_PROCESSING_IMPORT,
    ];

    protected $fillable = [
        'user_id',
        'filename',
        'path',
        'jenis_tarif_id',
        'status',
        'total_rows',
        'processed_rows',
        'inserted',
        'skipped_duplicate',
        'skipped_error',
        'providers_created',
        'services_created',
        'classes_created',
        'error_message',
        'scan_total_rows',
        'scan_processed_rows',
        'scan_summary',
        'scan_candidates',
        'scan_preview',
        'scan_errors',
        'scan_error_message',
    ];

    protected function casts(): array
    {
        return [
            'scan_summary' => 'array',
            'scan_candidates' => 'array',
            'scan_preview' => 'array',
            'scan_errors' => 'array',
        ];
    }

    /**
     * @return BelongsTo<JenisTarif, $this>
     */
    public function jenisTarif(): BelongsTo
    {
        return $this->belongsTo(JenisTarif::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isScanActive(): bool
    {
        return in_array($this->status, self::SCAN_ACTIVE_STATUSES, true);
    }

    public function isImportActive(): bool
    {
        return in_array($this->status, self::IMPORT_ACTIVE_STATUSES, true);
    }

    public function progressPercent(): int
    {
        if ($this->total_rows <= 0) {
            return $this->isTerminal() ? 100 : 0;
        }

        return (int) min(100, round($this->processed_rows / $this->total_rows * 100));
    }

    public function scanPercent(): int
    {
        if ($this->scan_total_rows <= 0) {
            return in_array($this->status, [self::STATUS_SCAN_COMPLETED, self::STATUS_SCAN_FAILED], true) ? 100 : 0;
        }

        return (int) min(100, round($this->scan_processed_rows / $this->scan_total_rows * 100));
    }

    public static function badgeClass(string $status): string
    {
        return match ($status) {
            self::STATUS_COMPLETED, self::STATUS_SCAN_COMPLETED => 'bg-green-100 text-green-800',
            self::STATUS_PROCESSING_IMPORT, self::STATUS_PROCESSING_SCAN => 'bg-blue-100 text-blue-800',
            self::STATUS_FAILED, self::STATUS_SCAN_FAILED => 'bg-red-100 text-red-800',
            default => 'bg-yellow-100 text-yellow-800',
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING_SCAN => 'PENDING SCAN',
            self::STATUS_PROCESSING_SCAN => 'SCANNING',
            self::STATUS_SCAN_COMPLETED => 'SCAN COMPLETED',
            self::STATUS_SCAN_FAILED => 'SCAN FAILED',
            self::STATUS_PENDING_IMPORT => 'PENDING IMPORT',
            self::STATUS_PROCESSING_IMPORT => 'PROCESSING',
            self::STATUS_COMPLETED => 'COMPLETED',
            self::STATUS_FAILED => 'FAILED',
            default => strtoupper($status),
        };
    }
}
