<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessGuard;
use App\Support\AccessCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AccessController extends Controller
{
    public function index(): array
    {
        AccessCatalog::ensure();

        return [
            'users' => User::query()->with('roles')->orderBy('name')->get()->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'active' => $user->active,
                'role_ids' => $user->roles->pluck('id')->values(),
            ]),
            'roles' => Role::query()->with('permissions')->orderBy('name')->get()->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'permission_ids' => $role->permissions->pluck('id')->values(),
            ]),
            'permissions' => Permission::query()->orderBy('name')->get(['id', 'name']),
        ];
    }

    public function storeUser(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data) {
            $employee = Employee::query()->create([
                'name' => $data['name'],
                'active' => true,
            ]);

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'active' => true,
                'employee_id' => $employee->id,
            ]);
            $user->roles()->sync($data['role_ids']);

            return $user;
        });

        return response()->json(['ok' => true, 'id' => $user->id], 201);
    }

    public function updateUser(UpdateUserRequest $request, User $user, AccessGuard $guard): JsonResponse
    {
        $data = $request->validated();
        $guard->assertUserChange($request->user(), $user, $data['active'], $data['role_ids']);

        DB::transaction(function () use ($data, $user) {
            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->active = $data['active'];

            if (filled($data['password'] ?? null)) {
                $user->password = $data['password'];
            }

            $employee = $user->employee;

            if (! $employee) {
                $employee = Employee::query()->create([
                    'name' => $data['name'],
                    'active' => $data['active'],
                ]);
                $user->employee_id = $employee->id;
            } else {
                $employee->update([
                    'name' => $data['name'],
                    'active' => $data['active'],
                ]);
            }

            $user->save();
            $user->roles()->sync($data['role_ids']);
        });

        return response()->json(['ok' => true]);
    }

    public function updateRole(UpdateRoleRequest $request, Role $role, AccessGuard $guard): JsonResponse
    {
        $permissionIds = $request->validated('permission_ids');
        $guard->assertRoleChange($role, $permissionIds);
        $role->permissions()->sync($permissionIds);

        return response()->json(['ok' => true]);
    }
}
