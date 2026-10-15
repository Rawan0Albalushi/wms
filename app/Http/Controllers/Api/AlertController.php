<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Services\WarehouseBoard;
use Illuminate\Validation\ValidationException;

class AlertController extends Controller
{
    public function index(WarehouseBoard $board): array
    {
        return $board->alerts();
    }

    /**
     * @return array{count: int}
     */
    public function count(): array
    {
        return ['count' => Alert::query()->count()];
    }

    public function resolve(Alert $alert): never
    {
        throw ValidationException::withMessages(['alert' => 'alert_live']);
    }
}
