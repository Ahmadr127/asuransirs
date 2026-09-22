<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'level',
        'description'
    ];

    /**
     * Get all organization units of this type
     *
     * @return HasMany<OrganizationUnit, $this>
     */
    public function organizationUnits(): HasMany
    {
        return $this->hasMany(OrganizationUnit::class, 'type_id');
    }
}
