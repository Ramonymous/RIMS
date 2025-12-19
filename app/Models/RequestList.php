<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestList extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_FULFILLED = 'fulfilled';

    public static function statuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_FULFILLED];
    }

    protected $fillable = [
        'request_id',
        'part_id',
        'quantity',
        'is_urgent',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'is_urgent' => 'boolean',
        ];
    }

    // Relationships

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class, 'request_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Singlepart::class, 'part_id');
    }

    // Query Scopes

    /**
     * Scope for pending request items
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope for fulfilled request items
     */
    public function scopeFulfilled($query)
    {
        return $query->where('status', self::STATUS_FULFILLED);
    }

    /**
     * Scope for urgent items
     */
    public function scopeUrgent($query)
    {
        return $query->where('is_urgent', true);
    }

    /**
     * Scope for specific part
     */
    public function scopeForPart($query, int $partId)
    {
        return $query->where('part_id', $partId);
    }

    /**
     * Scope for active request items (pending requests that are active)
     */
    public function scopeActiveRequests($query)
    {
        return $query->whereHas('request', function($q) {
            $q->active();
        });
    }
}