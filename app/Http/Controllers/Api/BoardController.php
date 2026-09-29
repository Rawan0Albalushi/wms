<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StockLedger;
use App\Services\WarehouseBoard;

class BoardController extends Controller
{
    public function show(WarehouseBoard $board, StockLedger $ledger): array
    {
        $ledger->syncAlerts();

        return $board->board();
    }
}
