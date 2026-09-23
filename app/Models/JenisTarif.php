<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JenisTarif extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'status',
    ];

    /**
     * Get all tarifs of this jenis
     *
     * @return HasMany<Tarif, $this>
     */
    public function tarifs(): HasMany
    {
        return $this->hasMany(Tarif::class);
    }
}
