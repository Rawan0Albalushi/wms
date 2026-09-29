<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\AccessCatalog;
use Illuminate\Database\Seeder;

class AccessSeeder extends Seeder
{
    public function run(): void
    {
        AccessCatalog::ensure();

        $this->account('admin@wms.test', 'مدير المستودع', 'administrator', 'مدير المستودع');
        $this->account('keeper@wms.test', 'راشد الكندي', 'storekeeper', 'راشد الكندي');
        $this->account('supervisor@wms.test', 'فاطمة السيابية', 'supervisor', 'فاطمة السيابية');

        $administrator = Role::query()->where('name', 'administrator')->firstOrFail();

        User::query()->whereDoesntHave('roles')->each(function (User $user) use ($administrator) {
            $user->roles()->sync([$administrator->id]);
            $this->linkEmployee($user, $user->name);
        });
    }

    private function account(string $email, string $name, string $role, string $employeeName): void
    {
        $employee = Employee::query()->firstOrCreate(['name' => $employeeName], ['active' => true]);
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => 'password',
                'active' => true,
                'employee_id' => $employee->id,
            ],
        );

        $this->linkEmployee($user, $employeeName);

        if ($user->roles()->doesntExist()) {
            $roleId = Role::query()->where('name', $role)->value('id');
            $user->roles()->sync([$roleId]);
        }
    }

    private function linkEmployee(User $user, string $employeeName): void
    {
        if ($user->employee_id) {
            return;
        }

        $employee = Employee::query()->firstOrCreate(['name' => $employeeName], ['active' => true]);
        $taken = User::query()->where('employee_id', $employee->id)->whereKeyNot($user->id)->exists();

        if ($taken) {
            $employee = Employee::query()->create(['name' => $employeeName, 'active' => true]);
        }

        $user->employee_id = $employee->id;
        $user->save();
    }
}
