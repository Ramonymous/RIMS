<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Request extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FULFILLED = 'fulfilled';

    public static function statuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_PARTIAL, self::STATUS_FULFILLED];
    }

    protected $fillable = [
        'destination',
        'status',
        'requested_by',
        'requested_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
        ];
    }

    // Relationships

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequestList::class, 'request_id');
    }

    // Query Scopes

    /**
     * Scope for pending requests
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope for partial requests
     */
    public function scopePartial($query)
    {
        return $query->where('status', self::STATUS_PARTIAL);
    }

    /**
     * Scope for fulfilled requests
     */
    public function scopeFulfilled($query)
    {
        return $query->where('status', self::STATUS_FULFILLED);
    }

    /**
     * Scope for active requests (pending or partial)
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PARTIAL]);
    }

    /**
     * Scope for specific destination
     */
    public function scopeForDestination($query, string $destination)
    {
        return $query->where('destination', $destination);
    }

    /**
     * Scope for recent requests
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('requested_at', '>=', now()->subDays($days));
    }
}