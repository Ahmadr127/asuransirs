<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'status',
    ];

    /**
     * Get all tarifs of this provider
     *
     * @return HasMany<Tarif, $this>
     */
    public function tarifs(): HasMany
    {
        return $this->hasMany(Tarif::class);
    }
}
