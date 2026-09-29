<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockCount;
use App\Services\StockLedger;
use App\Services\WarehouseBoard;
use App\Support\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CountController extends Controller
{
    public function index(WarehouseBoard $board): array
    {
        return $board->counts();
    }

    public function store(StockLedger $ledger): JsonResponse
    {
        $ledger->openCount();

        return response()->json(['ok' => true], 201);
    }

    public function update(Request $request, StockCount $count, StockLedger $ledger): array
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.counted_qty' => ['nullable', 'integer', 'min:0'],
        ]);

        $ledger->recordLines($count, $data['lines']);

        return ['ok' => true];
    }

    public function approve(Request $request, StockCount $count, StockLedger $ledger): array
    {
        $ledger->approveCount($count, Actor::id($request));

        return ['ok' => true];
    }
}
