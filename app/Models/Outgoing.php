<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Outgoing extends Model
{
    use HasFactory;

    protected $fillable = [
        'outgoing_number',
        'part_id',
        'quantity',
        'dispatched_by',
        'dispatched_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'dispatched_at' => 'datetime',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Singlepart::class, 'part_id');
    }

    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }
}
