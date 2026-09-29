<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Actor
{
    public static function id(Request $request): int
    {
        $employeeId = $request->user()?->employee_id;
        $employee = $employeeId
            ? Employee::query()->whereKey($employeeId)->where('active', true)->first()
            : null;

        if (! $employee) {
            throw ValidationException::withMessages(['employee' => 'employee']);
        }

        return $employee->id;
    }
}
