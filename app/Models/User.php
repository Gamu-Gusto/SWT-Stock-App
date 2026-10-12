<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'phone', 'password', 'role_id', 'is_active', 'must_change_password', 'created_by'];

    protected $hidden = ['password', 'remember_token'];

    /** @var array<string, bool>|null */
    protected ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isOwner(): bool
    {
        return $this->role?->name === Role::OWNER;
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        if ($this->isOwner()) {
            return Permission::query()->orderBy('name')->pluck('name')->all();
        }

        return $this->role?->permissions()->orderBy('name')->pluck('name')->all() ?? [];
    }

    public function hasPermission(string $permission): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->isOwner()) {
            return true;
        }
        if ($this->permissionCache === null) {
            $this->permissionCache = array_fill_keys($this->permissionNames(), true);
        }

        return isset($this->permissionCache[$permission]);
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
    }
}
