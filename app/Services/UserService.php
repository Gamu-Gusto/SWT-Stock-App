<?php

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Employee account administration. There is no public registration: only users with users.manage
 * create accounts. Only the owner can grant or remove the owner role, and the last active owner
 * can never be deactivated or demoted.
 */
class UserService
{
    /** @return array{user:User, temporary_password:?string} */
    public function create(array $data, User $actor): array
    {
        $role = Role::query()->findOrFail($data['role_id']);
        $this->assertCanAssign($role, $actor);
        $temporary = empty($data['password']) ? Str::password(14, symbols: false) : null;

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'phone' => $data['phone'] ?? null,
            'role_id' => $role->id,
            'password' => Hash::make($data['password'] ?? $temporary),
            'is_active' => $data['is_active'] ?? true,
            'must_change_password' => true,
            'created_by' => $actor->id,
        ]);
        Audit::log('user.created', $user, "Created employee {$user->email} as {$role->label}", null, ['name' => $user->name, 'email' => $user->email, 'role' => $role->name]);

        return ['user' => $user, 'temporary_password' => $temporary];
    }

    public function update(User $user, array $data, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor) {
            $original = $user->getOriginal();
            if (isset($data['role_id']) && (int) $data['role_id'] !== $user->role_id) {
                $role = Role::query()->findOrFail($data['role_id']);
                $this->assertCanAssign($role, $actor);
                if ($user->isOwner() && ! $actor->isOwner()) {
                    throw new InventoryException('Only the owner can change an owner account.', 403, 'forbidden');
                }
                if ($user->isOwner()) {
                    $this->assertNotLastOwner($user);
                }
                Audit::log('user.role_changed', $user, "Role of {$user->email} changed to {$role->label}", ['role_id' => $user->role_id], ['role_id' => $role->id]);
            }
            if ($user->isOwner() && ! $actor->isOwner()) {
                throw new InventoryException('Only the owner can edit an owner account.', 403, 'forbidden');
            }
            $user->fill(array_intersect_key($data, array_flip(['name', 'email', 'phone', 'role_id'])));
            if (isset($data['email'])) {
                $user->email = strtolower($data['email']);
            }
            $user->save();
            Audit::changes('user.updated', $user, $original, "Updated employee {$user->email}");

            return $user->fresh('role');
        });
    }

    public function setActive(User $user, bool $active, User $actor): User
    {
        if ($user->id === $actor->id && ! $active) {
            throw new InventoryException('You cannot deactivate your own account.', 422, 'self_deactivate');
        }
        if ($user->isOwner()) {
            if (! $actor->isOwner()) {
                throw new InventoryException('Only the owner can change an owner account.', 403, 'forbidden');
            }
            if (! $active) {
                $this->assertNotLastOwner($user);
            }
        }
        $user->forceFill(['is_active' => $active])->save();
        if (! $active) {
            // End all of the user's sessions immediately.
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->forceFill(['remember_token' => Str::random(60)])->save();
        }
        Audit::log($active ? 'user.activated' : 'user.deactivated', $user, ($active ? 'Activated ' : 'Deactivated ').$user->email);

        return $user;
    }

    /** Administrator password reset: returns a one-time temporary password that must be changed at next login. */
    public function resetPassword(User $user, User $actor): string
    {
        if ($user->isOwner() && ! $actor->isOwner()) {
            throw new InventoryException('Only the owner can reset an owner password.', 403, 'forbidden');
        }
        $temporary = Str::password(14, symbols: false);
        $user->forceFill(['password' => Hash::make($temporary), 'must_change_password' => true, 'remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        Audit::log('user.password_reset', $user, "Password reset by administrator for {$user->email}");

        return $temporary;
    }

    protected function assertCanAssign(Role $role, User $actor): void
    {
        if ($role->name === Role::OWNER && ! $actor->isOwner()) {
            throw new InventoryException('Only the owner can assign the owner role.', 403, 'forbidden');
        }
    }

    protected function assertNotLastOwner(User $user): void
    {
        $owners = User::query()->where('is_active', true)->whereHas('role', fn ($q) => $q->where('name', Role::OWNER))->count();
        if ($owners <= 1) {
            throw new InventoryException('At least one active owner account must remain.', 422, 'last_owner');
        }
    }
}
