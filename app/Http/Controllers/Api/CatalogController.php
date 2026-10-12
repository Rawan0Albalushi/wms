<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\CountLine;
use App\Models\Item;
use App\Models\JobLine;
use App\Models\Location;
use App\Models\Movement;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Services\StockLedger;
use App\Services\WarehouseBoard;
use App\Support\StoredImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    public function items(Request $request, WarehouseBoard $board): array
    {
        return $board->items($request->string('q')->toString());
    }

    public function locations(WarehouseBoard $board): array
    {
        return $board->locations();
    }

    public function options(WarehouseBoard $board): array
    {
        return $board->options();
    }

    public function storeItem(Request $request, StockLedger $ledger): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('items', 'code')],
            'part_number' => ['nullable', 'string', 'max:80'],
            'barcode' => ['nullable', 'string', 'max:40'],
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'min_level' => ['required', 'integer', 'min:0'],
            'unit_cost' => ['required', 'integer', 'min:0'],
        ]);

        $data['barcode'] = ($data['barcode'] ?? null) ?: $data['code'];
        $data['part_number'] = $data['part_number'] ?? null;
        $data['supplier_id'] = $data['supplier_id'] ?? null;
        $item = Item::query()->create($data);
        $ledger->deferAlerts([$item->id]);

        return response()->json(['ok' => true, 'id' => $item->id, 'code' => $item->code], 201);
    }

    public function item(Item $item, WarehouseBoard $board): array
    {
        return $board->item($item);
    }

    public function updateItem(Request $request, Item $item, StockLedger $ledger): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('items', 'code')->ignore($item->id)],
            'part_number' => ['nullable', 'string', 'max:80'],
            'barcode' => ['nullable', 'string', 'max:40'],
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'min_level' => ['required', 'integer', 'min:0'],
            'unit_cost' => ['required', 'integer', 'min:0'],
        ]);

        $data['barcode'] = ($data['barcode'] ?? null) ?: $data['code'];
        $data['part_number'] = $data['part_number'] ?? null;
        $data['supplier_id'] = $data['supplier_id'] ?? null;
        $item->update($data);
        $ledger->deferAlerts([$item->id]);

        return response()->json(['ok' => true, 'id' => $item->id, 'code' => $item->code]);
    }

    public function destroyItem(Item $item, StockLedger $ledger): JsonResponse
    {
        $this->refuseIfUsed(
            $item->batches()->exists()
            || PurchaseRequest::query()->where('item_id', $item->id)->exists()
            || PurchaseOrderLine::query()->where('item_id', $item->id)->exists()
            || JobLine::query()->where('item_id', $item->id)->exists()
            || Movement::query()->where('item_id', $item->id)->exists()
            || CountLine::query()->where('item_id', $item->id)->exists(),
        );

        $path = $item->image_path;
        $itemId = $item->id;
        $item->delete();
        StoredImage::delete($path);
        $ledger->deferAlerts([$itemId]);

        return response()->json(['ok' => true]);
    }

    public function suppliers(WarehouseBoard $board): array
    {
        return $board->suppliers();
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100', Rule::unique('locations', 'code')],
            'zone' => ['required', 'string', 'max:20'],
            'rack' => ['nullable', 'string', 'max:10'],
            'shelf' => ['nullable', 'string', 'max:10'],
            'kind' => ['required', Rule::in(['storage', 'dock', 'quarantine'])],
        ]);

        $location = Location::query()->create($data);

        return response()->json(['ok' => true, 'id' => $location->id, 'code' => $location->code], 201);
    }

    public function updateLocation(Request $request, Location $location): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100', Rule::unique('locations', 'code')->ignore($location->id)],
            'zone' => ['required', 'string', 'max:20'],
            'rack' => ['nullable', 'string', 'max:10'],
            'shelf' => ['nullable', 'string', 'max:10'],
            'kind' => ['required', Rule::in(['storage', 'dock', 'quarantine'])],
        ]);

        $data['rack'] = $data['rack'] ?? null;
        $data['shelf'] = $data['shelf'] ?? null;
        $location->update($data);

        return response()->json(['ok' => true, 'id' => $location->id, 'code' => $location->code]);
    }

    public function destroyLocation(Location $location): JsonResponse
    {
        $this->refuseIfUsed(
            $location->balances()->exists()
            || $location->recordedPositions()->exists()
            || CountLine::query()->where('location_id', $location->id)->exists()
            || Movement::query()->where(function ($query) use ($location) {
                $query->where('from_location_id', $location->id)
                    ->orWhere('to_location_id', $location->id);
            })->exists(),
        );

        $path = $location->image_path;
        $location->delete();
        StoredImage::delete($path);

        return response()->json(['ok' => true]);
    }

    public function storeItemImage(Request $request, Item $item): JsonResponse
    {
        $path = StoredImage::put($request, 'items', $item->image_path);
        $item->update(['image_path' => $path]);

        return response()->json(['ok' => true, 'image_url' => StoredImage::url($path)]);
    }

    public function destroyItemImage(Item $item): JsonResponse
    {
        StoredImage::delete($item->image_path);
        $item->update(['image_path' => null]);

        return response()->json(['ok' => true, 'image_url' => null]);
    }

    public function storeLocationImage(Request $request, Location $location): JsonResponse
    {
        $path = StoredImage::put($request, 'locations', $location->image_path);
        $location->update(['image_path' => $path]);

        return response()->json(['ok' => true, 'image_url' => StoredImage::url($path)]);
    }

    public function destroyLocationImage(Location $location): JsonResponse
    {
        StoredImage::delete($location->image_path);
        $location->update(['image_path' => null]);

        return response()->json(['ok' => true, 'image_url' => null]);
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('suppliers', 'code')],
            'name' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
        ]);

        $supplier = Supplier::query()->create($data);

        return response()->json(['ok' => true, 'id' => $supplier->id, 'code' => $supplier->code], 201);
    }

    public function updateSupplier(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('suppliers', 'code')->ignore($supplier->id)],
            'name' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
        ]);

        $data['contact_name'] = $data['contact_name'] ?? null;
        $data['phone'] = $data['phone'] ?? null;
        $data['email'] = $data['email'] ?? null;
        $supplier->update($data);

        return response()->json(['ok' => true, 'id' => $supplier->id, 'code' => $supplier->code]);
    }

    public function destroySupplier(Supplier $supplier): JsonResponse
    {
        $this->refuseIfUsed(
            $supplier->items()->exists()
            || $supplier->orders()->exists()
            || Batch::query()->where('supplier_id', $supplier->id)->exists(),
        );

        $supplier->delete();

        return response()->json(['ok' => true]);
    }

    private function refuseIfUsed(bool $used): void
    {
        if ($used) {
            throw ValidationException::withMessages(['record' => 'in_use']);
        }
    }
}
