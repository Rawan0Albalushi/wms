<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WarehouseBoard;

class ReportController extends Controller
{
    public function index(WarehouseBoard $board): array
    {
        return $board->reports();
    }
}
