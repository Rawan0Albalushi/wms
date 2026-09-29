<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StockLedger;
use App\Services\WarehouseBoard;
use App\Support\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockController extends Controller
{
    public function inventory(WarehouseBoard $board): array
    {
        return $board->inventory();
    }

    public function receive(Request $request, StockLedger $ledger): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'batch_no' => ['required', 'string', 'max:40'],
            'quantity' => ['required', 'integer', 'min:1'],
            'condition' => ['required', Rule::in(['new', 'used', 'damaged'])],
            'reference' => ['nullable', 'string', 'max:80'],
            'received_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'shipment_id' => ['nullable', 'integer', 'exists:shipments,id'],
        ]);

        $data['employee_id'] = Actor::id($request);
        $received = $ledger->receive($data);

        return response()->json([
            'ok' => true,
            'id' => $received['batch']->id,
            'batch_no' => $received['batch']->batch_no,
            'location_id' => $received['balance']->location_id,
            'position_id' => $received['balance']->id,
        ], 201);
    }

    public function issue(Request $request, StockLedger $ledger): JsonResponse
    {
        $data = $request->validate([
            'position_id' => ['required', 'integer', 'exists:balances,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::in(['maintenance', 'other'])],
            'department' => ['nullable', 'string', 'max:80'],
            'reference' => ['nullable', 'string', 'max:80'],
        ]);

        $data['employee_id'] = Actor::id($request);
        $ledger->issue($data);

        return response()->json(['ok' => true], 201);
    }

    public function transfer(Request $request, StockLedger $ledger): JsonResponse
    {
        $data = $request->validate([
            'position_id' => ['required', 'integer', 'exists:balances,id'],
            'to_location_id' => ['required', 'integer', 'exists:locations,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $data['employee_id'] = Actor::id($request);
        $ledger->transfer($data);

        return response()->json(['ok' => true], 201);
    }
}
