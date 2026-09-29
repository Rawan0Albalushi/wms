<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Services\DocumentFlow;
use App\Services\WarehouseBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProcurementController extends Controller
{
    public function index(WarehouseBoard $board): array
    {
        return $board->procurement();
    }

    public function storeRequest(Request $request, DocumentFlow $flow): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $created = $flow->request($data);

        return response()->json(['ok' => true, 'id' => $created->id, 'number' => $created->number], 201);
    }

    public function storeOrder(Request $request, DocumentFlow $flow): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'purchase_request_id' => ['nullable', 'integer', 'exists:purchase_requests,id'],
            'notes' => ['nullable', 'string', 'max:200'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'distinct', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $created = $flow->order($data);

        return response()->json(['ok' => true, 'id' => $created->id, 'number' => $created->number], 201);
    }

    public function storeShipment(Request $request, DocumentFlow $flow): JsonResponse
    {
        $data = $request->validate([
            'purchase_order_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            'ci_number' => ['required', 'string', 'max:40', Rule::unique('shipments', 'ci_number')],
            'expected_on' => ['required', 'date'],
            'packing_note' => ['nullable', 'string', 'max:200'],
        ]);

        $created = $flow->shipment($data);

        return response()->json([
            'ok' => true,
            'id' => $created->id,
            'number' => $created->ci_number,
        ], 201);
    }

    public function arrive(Shipment $shipment, DocumentFlow $flow): array
    {
        $flow->arrive($shipment);

        return ['ok' => true];
    }
}
