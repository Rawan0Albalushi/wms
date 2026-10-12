<?php

namespace App\Services;

use App\Models\Balance;
use App\Models\Category;
use App\Models\Item;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\RecordedPosition;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class CompleteSparePartsCatalog
{
    /**
     * @return array<string, int|bool>
     */
    public function complete(string $path, bool $persist): array
    {
        return $this->apply($this->read($path), $persist);
    }

    /**
     * @param  array<string, mixed>  $workbook
     * @return array<string, int|bool>
     */
    public function apply(array $workbook, bool $persist): array
    {
        $plan = $this->plan($workbook);

        if ($persist) {
            DB::transaction(function () use ($plan) {
                $this->write($plan);
            });
        }

        return ['applied' => $persist] + $plan['counts'];
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Workbook was not found.');
        }

        $result = Process::env(['PYTHONIOENCODING' => 'utf-8'])
            ->timeout(180)
            ->run(['python', base_path('scripts/read_spare_parts.py'), $path]);

        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: 'The workbook could not be read.');
        }

        $payload = json_decode($result->output(), true);

        if (! is_array($payload)) {
            throw new RuntimeException('The workbook reader returned an unreadable result.');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $workbook
     * @return array{counts: array<string, int>, items: list<array<string, mixed>>, item_updates: list<array<string, mixed>>, locations: list<array<string, mixed>>, positions: list<array<string, mixed>>, balance_ids: list<int>, orders: list<array<string, mixed>>}
     */
    private function plan(array $workbook): array
    {
        $items = Item::query()->with('unit:id,code')->get()->keyBy(fn (Item $item) => strtoupper($item->code));
        $locations = Location::query()->get()->keyBy(fn (Location $location) => strtoupper($location->code));
        $units = Unit::query()->pluck('id', 'code')->mapWithKeys(
            fn ($id, $code) => [strtoupper((string) $code) => $id],
        );
        $orderNumbers = PurchaseOrder::query()->pluck('number')->map(fn ($number) => strtoupper((string) $number))->all();
        $shipmentNumbers = Shipment::query()->pluck('ci_number')->map(fn ($number) => strtoupper((string) $number))->all();
        $positionKeys = RecordedPosition::query()->pluck('import_key')->flip();
        $categoryId = Category::query()->where('code', 'SPR')->value('id') ?? Category::query()->orderBy('id')->value('id');

        if (! $categoryId) {
            throw new RuntimeException('Create a category before completing the workbook.');
        }

        $locatedItems = Balance::query()
            ->join('batches', 'batches.id', '=', 'balances.batch_id')
            ->whereNotNull('balances.location_id')
            ->pluck('batches.item_id')
            ->map(fn ($id) => (int) $id)
            ->unique();
        $openBalances = Balance::query()
            ->join('batches', 'batches.id', '=', 'balances.batch_id')
            ->whereNull('balances.location_id')
            ->get(['balances.id', 'balances.batch_id', 'batches.item_id'])
            ->groupBy(fn ($row) => (int) $row->item_id);

        $counts = [
            'items_created' => 0,
            'items_completed' => 0,
            'units_created' => 0,
            'locations_created' => 0,
            'positions_created' => 0,
            'balances_located' => 0,
            'future_orders_created' => 0,
            'receipts_created' => 0,
            'orders_left_unchanged' => 0,
        ];
        $newItems = [];
        $itemUpdates = [];
        $newLocations = [];
        $newPositions = [];
        $newOrders = [];
        $itemLocations = [];
        $seen = [];

        foreach ($workbook['items'] ?? [] as $row) {
            $code = strtoupper(trim((string) ($row['code'] ?? '')));

            if ($code === '') {
                continue;
            }

            $unitCodes = array_values(array_filter(array_map(
                fn ($unit) => strtoupper(trim((string) $unit)),
                $row['units'] ?? ['EA'],
            )));
            $primary = $unitCodes[0] ?? 'EA';
            $existing = $items->get($code);

            if (! $existing) {
                $alt = collect($unitCodes)->first(fn (string $unit) => $unit !== $primary);
                $newItems[] = [
                    'code' => $code,
                    'part_number' => $this->nullable($row['part_number'] ?? null, 80),
                    'barcode' => $code,
                    'name_ar' => $this->text($row['name'] ?? $code, 255),
                    'name_en' => $this->text($row['name'] ?? $code, 255),
                    'category_id' => $categoryId,
                    'unit' => $primary,
                    'min_level' => max(0, (int) ($row['min_level'] ?? 0)),
                    'unit_cost' => 0,
                    'stock_status' => $this->status($row['stock_status'] ?? null),
                    'family' => $this->nullable($row['family'] ?? null, 80),
                    'alt_unit' => $alt,
                ];
                $items->put($code, new Item(['code' => $code]));
                $counts['items_created']++;

                continue;
            }

            $changes = [];
            $minimum = max(0, (int) ($row['min_level'] ?? 0));

            if ((int) $existing->min_level === 0 && $minimum > 0) {
                $changes['min_level'] = $minimum;
            }

            $status = $this->status($row['stock_status'] ?? null);

            if ($existing->stock_status === null && $status) {
                $changes['stock_status'] = $status;
            }

            $family = $this->nullable($row['family'] ?? null, 80);

            if ($existing->family === null && $family) {
                $changes['family'] = $family;
            }

            $part = $this->nullable($row['part_number'] ?? null, 80);

            if ($existing->part_number === null && $part) {
                $changes['part_number'] = $part;
            }

            $currentUnit = strtoupper((string) ($existing->unit?->code ?? ''));
            $alt = collect($unitCodes)->first(fn (string $unit) => $unit !== $currentUnit);

            if ($existing->alt_unit === null && $alt) {
                $changes['alt_unit'] = $alt;
            }

            if ($changes !== []) {
                $itemUpdates[] = ['id' => $existing->id, 'changes' => $changes];
                $counts['items_completed']++;
            }
        }

        foreach ($workbook['positions'] ?? [] as $row) {
            $code = strtoupper(trim((string) ($row['item'] ?? '')));
            $place = $this->nullable(isset($row['location']) ? strtoupper((string) $row['location']) : null, 100);
            $qty = max(0, (int) ($row['qty'] ?? 0));

            if ($code === '' || $qty === 0 || ! $items->has($code)) {
                continue;
            }

            if ($place && ! $locations->has($place)) {
                $newLocations[] = $this->place($place);
                $locations->put($place, new Location(['code' => $place]));
                $counts['locations_created']++;
            }

            $base = implode('|', [
                $code,
                $place ?? '',
                $qty,
                (string) ($row['recorded_on'] ?? ''),
                (string) ($row['reference'] ?? ''),
                (string) ($row['type'] ?? ''),
            ]);
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $key = hash('sha256', $base.'|'.$seen[$base]);

            if ($positionKeys->has($key)) {
                if ($place) {
                    $itemLocations[$code][$place] = true;
                }

                continue;
            }

            $newPositions[] = [
                'item' => $code,
                'location' => $place,
                'quantity' => $qty,
                'condition' => $this->condition($row['condition'] ?? null),
                'reference' => $this->nullable($row['reference'] ?? null, 80),
                'line_type' => $this->nullable($row['type'] ?? null, 40),
                'recorded_on' => $this->date($row['recorded_on'] ?? null),
                'import_key' => $key,
            ];
            $positionKeys->put($key, true);
            $counts['positions_created']++;

            if ($place) {
                $itemLocations[$code][$place] = true;
            }
        }

        $balanceIds = [];

        foreach ($openBalances as $itemId => $balances) {
            $item = $items->first(fn (Item $item) => (int) $item->id === (int) $itemId);

            if (! $item || $locatedItems->contains((int) $itemId)) {
                continue;
            }

            $places = array_keys($itemLocations[strtoupper($item->code)] ?? []);

            if (count($places) !== 1) {
                continue;
            }

            foreach ($balances->groupBy('batch_id') as $rows) {
                if ($rows->count() === 1) {
                    $balanceIds[] = (int) $rows->first()->id;
                }
            }
        }

        $counts['balances_located'] = count($balanceIds);

        foreach ($workbook['future'] ?? [] as $row) {
            $this->queueOrder($row, 'future', $orderNumbers, $shipmentNumbers, $items, $newOrders, $counts);
        }

        foreach ($workbook['receipts'] ?? [] as $row) {
            $this->queueOrder($row, 'receipt', $orderNumbers, $shipmentNumbers, $items, $newOrders, $counts);
        }

        foreach ($newItems as $row) {
            if (! $units->has($row['unit'])) {
                $counts['units_created']++;
                $units->put($row['unit'], 0);
            }

            if ($row['alt_unit'] && ! $units->has($row['alt_unit'])) {
                $counts['units_created']++;
                $units->put($row['alt_unit'], 0);
            }
        }

        return [
            'counts' => $counts,
            'items' => $newItems,
            'item_updates' => $itemUpdates,
            'locations' => $newLocations,
            'positions' => $newPositions,
            'balance_ids' => $balanceIds,
            'orders' => $newOrders,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function write(array $plan): void
    {
        $units = Unit::query()->pluck('id', 'code')->mapWithKeys(
            fn ($id, $code) => [strtoupper((string) $code) => $id],
        );

        foreach ($this->unitCodes($plan) as $code) {
            if ($units->has($code)) {
                continue;
            }

            $unit = Unit::query()->create(['code' => $code, 'name_ar' => $code, 'name_en' => $code]);
            $units->put($code, $unit->id);
        }

        foreach ($plan['items'] as $row) {
            $unit = $row['unit'];
            unset($row['unit']);
            $row['unit_id'] = $units[$unit];
            Item::query()->create($row);
        }

        foreach ($plan['item_updates'] as $update) {
            Item::query()->whereKey($update['id'])->update($update['changes']);
        }

        foreach ($plan['locations'] as $row) {
            Location::query()->create($row);
        }

        $itemIds = Item::query()->pluck('id', 'code')->mapWithKeys(
            fn ($id, $code) => [strtoupper((string) $code) => $id],
        );
        $locationIds = Location::query()->pluck('id', 'code')->mapWithKeys(
            fn ($id, $code) => [strtoupper((string) $code) => $id],
        );
        $now = now();
        $positionRows = [];

        foreach ($plan['positions'] as $row) {
            $itemId = $itemIds->get($row['item']);

            if (! $itemId) {
                continue;
            }

            $positionRows[] = [
                'item_id' => $itemId,
                'location_id' => $row['location'] ? $locationIds->get($row['location']) : null,
                'quantity' => $row['quantity'],
                'condition' => $row['condition'],
                'reference' => $row['reference'],
                'line_type' => $row['line_type'],
                'recorded_on' => $row['recorded_on'],
                'import_key' => $row['import_key'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($positionRows, 400) as $chunk) {
            RecordedPosition::query()->insert($chunk);
        }

        if ($plan['balance_ids'] !== []) {
            $locationByBalance = [];

            foreach ($plan['positions'] as $row) {
                if (! $row['location']) {
                    continue;
                }

                $itemId = $itemIds->get($row['item']);
                $locationId = $locationIds->get($row['location']);

                if ($itemId && $locationId) {
                    $locationByBalance[$itemId][$locationId] = true;
                }
            }

            $balances = Balance::query()->with('batch:id,item_id')->whereIn('id', $plan['balance_ids'])->get();

            foreach ($balances as $balance) {
                $places = array_keys($locationByBalance[$balance->batch->item_id] ?? []);

                if (count($places) === 1) {
                    $balance->update(['location_id' => $places[0]]);
                }
            }
        }

        $supplierId = $this->storeSupplierId();

        foreach ($plan['orders'] as $order) {
            $created = PurchaseOrder::query()->create([
                'number' => $order['number'],
                'supplier_id' => $supplierId,
                'status' => $order['status'],
                'kind' => $order['kind'],
                'ordered_on' => $order['ordered_on'],
                'notes' => $order['notes'],
            ]);

            foreach ($order['lines'] as $line) {
                $itemId = $itemIds->get($line['item']);

                if (! $itemId) {
                    continue;
                }

                PurchaseOrderLine::query()->create([
                    'purchase_order_id' => $created->id,
                    'item_id' => $itemId,
                    'quantity' => $line['qty'],
                    'received_quantity' => $line['received_quantity'],
                ]);
            }

            if ($order['shipment']) {
                Shipment::query()->create([
                    'purchase_order_id' => $created->id,
                    'ci_number' => $order['shipment']['ci'],
                    'expected_on' => $order['ordered_on'],
                    'status' => 'received',
                    'packing_note' => $order['shipment']['note'],
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $orderNumbers
     * @param  list<string>  $shipmentNumbers
     * @param  array<string, int>  $counts
     * @param  list<array<string, mixed>>  $newOrders
     */
    private function queueOrder(array $row, string $kind, array &$orderNumbers, array &$shipmentNumbers, $items, array &$newOrders, array &$counts): void
    {
        $number = strtoupper(trim((string) ($row['number'] ?? '')));
        $ci = strtoupper(trim((string) ($row['ci'] ?? '')));

        if ($number === '' || in_array($number, $orderNumbers, true) || ($ci !== '' && in_array($ci, $shipmentNumbers, true))) {
            $counts['orders_left_unchanged']++;

            return;
        }

        $lines = [];

        foreach ($row['lines'] ?? [] as $line) {
            $code = strtoupper(trim((string) ($line['item'] ?? '')));
            $qty = max(0, (int) ($line['qty'] ?? 0));

            if ($code === '' || $qty === 0 || ! $items->has($code)) {
                continue;
            }

            $lines[] = [
                'item' => $code,
                'qty' => $qty,
                'received_quantity' => $qty,
            ];
        }

        if ($lines === []) {
            return;
        }

        $notes = $kind === 'future'
            ? trim('Future arrival. '.collect([
                $row['sheet_status'] ?? null ? 'Status: '.$row['sheet_status'] : null,
                $row['stage'] ?? null ? 'Stage: '.$row['stage'] : null,
                $row['exw_on'] ?? null ? 'EXW: '.$row['exw_on'] : null,
            ])->filter()->implode('. '))
            : (string) ($row['packing_note'] ?? 'Store receipt');

        $newOrders[] = [
            'number' => $number,
            'status' => $kind === 'future' ? 'historical' : 'received',
            'kind' => $kind,
            'ordered_on' => $this->date($row['ordered_on'] ?? null) ?? now()->toDateString(),
            'notes' => $this->text($notes, 255),
            'lines' => $lines,
            'shipment' => $kind === 'receipt' ? ['ci' => $ci, 'note' => $this->nullable($row['packing_note'] ?? null, 240)] : null,
        ];
        $orderNumbers[] = $number;

        if ($ci !== '') {
            $shipmentNumbers[] = $ci;
        }

        $counts[$kind === 'future' ? 'future_orders_created' : 'receipts_created']++;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<string>
     */
    private function unitCodes(array $plan): array
    {
        $codes = [];

        foreach ($plan['items'] as $row) {
            $codes[] = $row['unit'];

            if ($row['alt_unit']) {
                $codes[] = $row['alt_unit'];
            }
        }

        return array_values(array_unique($codes));
    }

    private function storeSupplierId(): int
    {
        $supplier = Supplier::query()->firstOrCreate(
            ['code' => 'STORE'],
            ['name' => 'المخزن', 'name_en' => 'Store', 'notes' => 'Workbook receipts have no supplier.'],
        );

        return $supplier->id;
    }

    /**
     * @return array{code: string, zone: string, rack: ?string, shelf: ?string, kind: string}
     */
    private function place(string $code): array
    {
        if (preg_match('/^([A-Z]{1,3}\d?)([A-Z])(\d{1,2})$/', $code, $match) === 1) {
            return [
                'code' => $code,
                'zone' => $match[1],
                'rack' => $match[2],
                'shelf' => $match[3],
                'kind' => 'storage',
            ];
        }

        return [
            'code' => $code,
            'zone' => mb_substr($code, 0, 20),
            'rack' => null,
            'shelf' => null,
            'kind' => 'storage',
        ];
    }

    private function status(mixed $value): ?string
    {
        $status = strtolower(trim((string) $value));

        return in_array($status, ['rop', 'sufficient'], true) ? $status : null;
    }

    private function condition(mixed $value): ?string
    {
        $condition = strtolower(trim((string) $value));

        return in_array($condition, ['new', 'used'], true) ? $condition : null;
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private function nullable(mixed $value, int $limit): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }

    private function text(mixed $value, int $limit): string
    {
        return mb_substr(trim((string) $value), 0, $limit);
    }
}
