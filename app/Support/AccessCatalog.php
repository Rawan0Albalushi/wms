<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

class AccessCatalog
{
    /**
     * @return list<string>
     */
    public static function permissions(): array
    {
        return [
            'floor.view',
            'items.view',
            'items.manage',
            'locations.view',
            'locations.manage',
            'suppliers.view',
            'suppliers.manage',
            'procurement.view',
            'procurement.manage',
            'inventory.view',
            'receiving.post',
            'issuing.post',
            'transfers.post',
            'jobs.view',
            'jobs.manage',
            'counts.view',
            'counts.manage',
            'counts.approve',
            'alerts.view',
            'reports.view',
            'access.manage',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function roles(): array
    {
        $all = self::permissions();

        return [
            'administrator' => $all,
            'supervisor' => array_values(array_filter($all, fn (string $permission) => $permission !== 'access.manage')),
            'storekeeper' => [
                'floor.view',
                'items.view',
                'locations.view',
                'suppliers.view',
                'procurement.view',
                'inventory.view',
                'receiving.post',
                'issuing.post',
                'transfers.post',
                'jobs.view',
                'jobs.manage',
                'counts.view',
                'counts.manage',
                'alerts.view',
            ],
            'buyer' => [
                'floor.view',
                'items.view',
                'suppliers.view',
                'suppliers.manage',
                'procurement.view',
                'procurement.manage',
                'alerts.view',
                'reports.view',
            ],
            'viewer' => [
                'floor.view',
                'items.view',
                'locations.view',
                'suppliers.view',
                'procurement.view',
                'inventory.view',
                'jobs.view',
                'counts.view',
                'alerts.view',
                'reports.view',
            ],
        ];
    }

    public static function ensure(): void
    {
        DB::transaction(function () {
            foreach (self::permissions() as $name) {
                Permission::query()->firstOrCreate(['name' => $name]);
            }

            $ids = Permission::query()->pluck('id', 'name');

            foreach (self::roles() as $name => $permissions) {
                $role = Role::query()->firstOrCreate(['name' => $name]);
                $permissionIds = collect($permissions)->map(fn (string $permission) => $ids[$permission])->all();

                if ($name === 'administrator' || ! $role->permissions()->exists()) {
                    $role->permissions()->sync($permissionIds);
                }
            }
        });
    }
}
