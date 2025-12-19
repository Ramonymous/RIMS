<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Movement extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'part_id',
        'type',
        'pic',
        'qty',
        'final_qty',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'final_qty' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    // Relationships

    public function part(): BelongsTo
    {
        return $this->belongsTo(Singlepart::class, 'part_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic');
    }

    // Query Scopes

    /**
     * Scope for incoming movements
     */
    public function scopeIncoming($query)
    {
        return $query->where('type', 'in');
    }

    /**
     * Scope for outgoing movements
     */
    public function scopeOutgoing($query)
    {
        return $query->where('type', 'out');
    }

    /**
     * Scope for specific part
     */
    public function scopeForPart($query, int $partId)
    {
        return $query->where('part_id', $partId);
    }

    /**
     * Scope for specific user/PIC
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('pic', $userId);
    }

    /**
     * Scope for date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope for today's movements
     */
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    /**
     * Scope for recent movements
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}