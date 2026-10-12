<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * In-app notification-centre entry (table: notifications). Visible to users holding `permission`,
 * or to the single `user_id` when set.
 */
class AppNotification extends Model
{
    protected $table = 'notifications';

    protected $fillable = ['type', 'severity', 'title', 'message', 'link', 'permission', 'user_id', 'dedupe_key', 'resolved_at'];

    protected $casts = ['resolved_at' => 'datetime'];

    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'notification_reads', 'notification_id', 'user_id')->withPivot('read_at');
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $q->where(function (Builder $q) use ($user) {
            $q->where('user_id', $user->id)->orWhere(function (Builder $q) use ($user) {
                $q->whereNull('user_id');
                if (! $user->isOwner()) {
                    $q->where(fn (Builder $q) => $q->whereNull('permission')->orWhereIn('permission', $user->permissionNames()));
                }
            });
        });
    }
}
