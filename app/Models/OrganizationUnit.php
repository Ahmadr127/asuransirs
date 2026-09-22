<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationUnit extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'name',
        'code',
        'type_id',
        'parent_id',
        'head_id',
        'description',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the type of this organization unit
     *
     * @return BelongsTo<OrganizationType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(OrganizationType::class, 'type_id');
    }

    /**
     * Get the parent organization unit
     *
     * @return BelongsTo<OrganizationUnit, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'parent_id');
    }

    /**
     * Get all child organization units
     *
     * @return HasMany<OrganizationUnit, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(OrganizationUnit::class, 'parent_id');
    }

    /**
     * Get all descendants recursively
     *
     * @return HasMany<OrganizationUnit, $this>
     */
    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    /**
     * Get the head/manager of this unit
     *
     * @return BelongsTo<User, $this>
     */
    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_id');
    }

    /**
     * Get all users/members in this unit
     *
     * @return HasMany<User, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(User::class, 'organization_unit_id');
    }

    /**
     * Get all ancestors (parent hierarchy)
     *
     * @return Collection<int, OrganizationUnit>
     */
    public function ancestors(): Collection
    {
        $ancestors = collect();
        $parent = $this->parent;

        while ($parent) {
            $ancestors->push($parent);
            $parent = $parent->parent;
        }

        return $ancestors;
    }

    /**
     * Get the full path as string (e.g., "PT > RS > Direktorat > Departemen")
     */
    public function getFullPathAttribute(): string
    {
        return $this->ancestors()->reverse()->pluck('name')->push($this->name)->implode(' > ');
    }

    /**
     * Scope for active units only
     *
     * @param \Illuminate\Database\Eloquent\Builder<OrganizationUnit> $query
     * @return \Illuminate\Database\Eloquent\Builder<OrganizationUnit>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for root units (no parent)
     *
     * @param \Illuminate\Database\Eloquent\Builder<OrganizationUnit> $query
     * @return \Illuminate\Database\Eloquent\Builder<OrganizationUnit>
     */
    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }
}
