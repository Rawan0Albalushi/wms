<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Services\StockLedger;
use App\Services\WarehouseBoard;
use Illuminate\Validation\ValidationException;

class AlertController extends Controller
{
    public function index(WarehouseBoard $board, StockLedger $ledger): array
    {
        $ledger->syncAlerts();

        return $board->alerts();
    }

    public function resolve(Alert $alert): never
    {
        throw ValidationException::withMessages(['alert' => 'alert_live']);
    }
}
