<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WarehouseBoard;

class BoardController extends Controller
{
    public function show(WarehouseBoard $board): array
    {
        return $board->board();
    }
}
