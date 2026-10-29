<?php

namespace App\Modules\Catalogue\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use Auditable, BelongsToStore;

    public const PACKAGING = ['rgb', 'pet', 'can', 'other'];

    protected $fillable = [
        'store_id', 'sku', 'name', 'brand', 'size_label', 'packaging', 'is_returnable', 'tracks_expiry', 'expiry_alert_days',
        'category_id', 'rebate_category_id', 'base_unit_id', 'units_per_case', 'is_active', 'image_path',
        'bottle_container_id', 'crate_container_id',
    ];

    protected $hidden = ['image_path'];

    protected $appends = ['image_url'];

    /** Relative API path of the picture (the version changes when the product is saved, so browsers refetch). */
    /** Bottles in a physical crate: the figure set on the product, else the largest pack it is sold in. */
    public function crateSize(): int
    {
        return (int) ($this->units_per_case ?: ($this->relationLoaded('units') ? $this->units->max('factor') : $this->units()->max('factor')) ?: 0);
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? "/products/{$this->id}/image?v=".($this->updated_at?->timestamp ?? 0) : null;
    }

    protected function casts(): array
    {
        return ['is_returnable' => 'boolean', 'tracks_expiry' => 'boolean', 'is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function rebateCategory(): BelongsTo
    {
        return $this->belongsTo(RebateCategory::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }
}
