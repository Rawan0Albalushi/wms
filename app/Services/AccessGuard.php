<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AccessGuard
{
    /**
     * @param  list<int>  $roleIds
     */
    public function assertUserChange(User $actor, User $subject, bool $active, array $roleIds): void
    {
        if ($actor->is($subject) && ! $active) {
            throw ValidationException::withMessages(['active' => 'self']);
        }

        $grantsAccess = $active && Role::query()
            ->whereIn('id', $roleIds)
            ->whereHas('permissions', fn ($query) => $query->where('name', 'access.manage'))
            ->exists();

        if (! $grantsAccess && ! $this->hasAnotherManager($subject->id)) {
            throw ValidationException::withMessages(['role_ids' => 'last_admin']);
        }
    }

    /**
     * @param  list<int>  $permissionIds
     */
    public function assertRoleChange(Role $role, array $permissionIds): void
    {
        $grantsAccess = Permission::query()
            ->whereKey($permissionIds)
            ->where('name', 'access.manage')
            ->exists();

        if ($role->name === 'administrator' && ! $grantsAccess) {
            throw ValidationException::withMessages(['permission_ids' => 'admin_role']);
        }

        $usedByActiveUser = $role->users()->where('users.active', true)->exists();

        if (! $grantsAccess && $usedByActiveUser && ! $this->hasAnotherManager(exceptRoleId: $role->id)) {
            throw ValidationException::withMessages(['permission_ids' => 'last_admin']);
        }
    }

    private function hasAnotherManager(?int $exceptUserId = null, ?int $exceptRoleId = null): bool
    {
        return User::query()
            ->where('active', true)
            ->when($exceptUserId, fn ($query) => $query->whereKeyNot($exceptUserId))
            ->whereHas('roles', function ($query) use ($exceptRoleId) {
                $query->when($exceptRoleId, fn ($roles) => $roles->where('roles.id', '!=', $exceptRoleId))
                    ->whereHas('permissions', fn ($permissions) => $permissions->where('name', 'access.manage'));
            })
            ->exists();
    }
}
