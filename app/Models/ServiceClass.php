<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master kelas layanan. Tabel `classes`, model bernama ServiceClass
 * karena `Class` adalah reserved word di PHP.
 */
class ServiceClass extends Model
{
    use HasFactory;

    protected $table = 'classes';

    protected $fillable = [
        'code',
        'name',
        'status',
    ];

    /**
     * Get all tarifs of this class
     *
     * @return HasMany<Tarif, $this>
     */
    public function tarifs(): HasMany
    {
        return $this->hasMany(Tarif::class, 'class_id');
    }
}
