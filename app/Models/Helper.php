<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Helper extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'operator_pct',
        'anesthesia_pct',
        'room_pct',
        'child_pct',
        'status',
    ];

    protected $casts = [
        'operator_pct' => 'decimal:2',
        'anesthesia_pct' => 'decimal:2',
        'room_pct' => 'decimal:2',
        'child_pct' => 'decimal:2',
    ];
}
