<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receiving extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public static function statuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_COMPLETED, self::STATUS_CANCELLED];
    }

    protected $fillable = [
        'receiving_number',
        'part_id',
        'quantity',
        'received_by',
        'received_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    // Relationships

    public function part(): BelongsTo
    {
        return $this->belongsTo(Singlepart::class, 'part_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    // Query Scopes

    /**
     * Scope for draft receivings
     */
    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /**
     * Scope for completed receivings
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope for cancelled receivings
     */
    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    /**
     * Scope for specific receiving number
     */
    public function scopeByReceivingNumber($query, string $receivingNumber)
    {
        return $query->where('receiving_number', 'like', $receivingNumber . '%');
    }

    /**
     * Scope for date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('received_at', [$startDate, $endDate]);
    }

    /**
     * Scope for recent receivings
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('received_at', '>=', now()->subDays($days));
    }
}