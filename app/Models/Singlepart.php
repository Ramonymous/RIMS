<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Singlepart extends Model
{
    use HasFactory;

    protected $fillable = [
         'part_number',
         'part_name',
         'customer_code',
         'supplier_code',
         'model',
         'variant',
         'standard_packing',
         'stock',
         'address',
         'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'stock' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (Singlepart $part) {
            // Invalidate all part search cache entries
            Cache::tags(['part_search'])->flush();
            Cache::forget('parts_options_list');
        });

        static::deleted(function (Singlepart $part) {
            Cache::tags(['part_search'])->flush();
            Cache::forget('parts_options_list');
        });
    }

    // Relationships

    public function receivings(): HasMany
    {
        return $this->hasMany(Receiving::class, 'part_id');
    }

    public function requestLists(): HasMany
    {
        return $this->hasMany(RequestList::class, 'part_id');
    }

    public function outgoings(): HasMany
    {
        return $this->hasMany(Outgoing::class, 'part_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class, 'part_id');
    }

    // Query Scopes

    /**
     * Scope to filter only active parts
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter parts with stock available
     */
    public function scopeInStock($query)
    {
        return $query->where('stock', '>', 0);
    }

    /**
     * Scope to search by part number, customer code, or supplier code
     * FIXED: Wrapped in closure to prevent SQL logic errors
     */
    public function scopeSearchByCode($query, string $code)
    {
        return $query->where(function($q) use ($code) {
            $q->where('part_number', $code)
              ->orWhere('customer_code', $code)
              ->orWhere('supplier_code', $code);
        });
    }

    /**
     * Scope to filter by model
     */
    public function scopeByModel($query, string $model)
    {
        return $query->where('model', $model);
    }

    /**
     * Scope to filter by variant
     */
    public function scopeByVariant($query, string $variant)
    {
        return $query->where('variant', $variant);
    }
}