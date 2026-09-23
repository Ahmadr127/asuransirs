<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'status',
    ];

    /**
     * Get all tarifs of this service
     *
     * @return HasMany<Tarif, $this>
     */
    public function tarifs(): HasMany
    {
        return $this->hasMany(Tarif::class);
    }
}
