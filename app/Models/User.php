<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles, HasPushSubscriptions;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<string>|bool
     */
    protected $guarded = ['id'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Query Scopes

    /**
     * Scope for users who can receive push notifications
     */
    public function scopeNotifiable($query)
    {
        return $query->where(function ($q) {
            $q->whereHas('pushSubscriptions')
              ->orWhereNotNull('telegram_user_id');
        });
    }

    /**
     * Scope for users with specific role
     */
    public function scopeWithRole($query, string $role)
    {
        return $query->role($role);
    }

    /**
     * Scope for active users (example - adjust based on your needs)
     */
    public function scopeActive($query)
    {
        return $query->whereNotNull('email_verified_at');
    }

    // Notification Routing

    /**
     * Route notifications for the Telegram channel.
     */
    public function routeNotificationForTelegram(): ?string
    {
        return $this->telegram_user_id;
    }
}