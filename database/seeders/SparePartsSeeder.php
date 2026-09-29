<?php

namespace Database\Seeders;

use App\Services\StockLedger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SparePartsSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/spare-parts.json');
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($data) {
            $this->clearWarehouse();
            $unitIds = $this->insertUnits($data['units']);
            $categoryId = DB::table('categories')->insertGetId([
                'code' => 'SPR',
                'name_ar' => 'قطع غيار',
                'name_en' => 'Spare parts',
                ...$this->timestamps(),
            ]);
            $supplierId = DB::table('suppliers')->insertGetId([
                'code' => 'STORE',
                'name' => 'المخزن',
                'name_en' => 'Store',
                'notes' => 'Workbook has no supplier, so historical orders use this record.',
                ...$this->timestamps(),
            ]);
            $itemIds = $this->insertItems($data['items'], $unitIds, $categoryId);
            $locationIds = $this->insertLocations($data['locations']);
            $this->insertStock($data['stock'], $itemIds, $locationIds);
            $this->insertOrders($data['orders'], $itemIds, $supplierId);
            $this->insertIssues($data['issues'], $itemIds);
        });

        app(StockLedger::class)->syncAlerts();

        $this->command?->info('Spare parts imported. Login accounts were left in place.');
        $this->command?->info('Items '.count($data['items']).', stock lines '.count($data['stock']).', issues '.count($data['issues']).', orders '.count($data['orders']).'.');
    }

    private function clearWarehouse(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'alerts',
            'count_lines',
            'counts',
            'reservations',
            'movements',
            'job_lines',
            'job_orders',
            'shipments',
            'purchase_order_lines',
            'purchase_orders',
            'purchase_requests',
            'balances',
            'batches',
            'locations',
            'items',
            'suppliers',
            'units',
            'categories',
            'document_sequences',
        ] as $table) {
            DB::table($table)->delete();
        }

        $keptEmployees = DB::table('users')->whereNotNull('employee_id')->pluck('employee_id');
        $employees = DB::table('employees');

        if ($keptEmployees->isNotEmpty()) {
            $employees->whereNotIn('id', $keptEmployees);
        }

        $employees->delete();

        Schema::enableForeignKeyConstraints();
    }

    /**
     * @param  list<array<string, string>>  $units
     * @return array<string, int>
     */
    private function insertUnits(array $units): array
    {
        $now = $this->timestamps();
        DB::table('units')->insert(array_map(fn (array $unit) => $unit + $now, $units));

        return DB::table('units')->pluck('id', 'code')->all();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, int>  $unitIds
     * @return array<string, int>
     */
    private function insertItems(array $items, array $unitIds, int $categoryId): array
    {
        $now = $this->timestamps();
        $rows = [];

        foreach ($items as $item) {
            $rows[] = [
                'code' => $item['code'],
                'part_number' => $item['part_number'],
                'barcode' => $item['code'],
                'image_path' => null,
                'name_ar' => $item['name'],
                'name_en' => $item['name'],
                'category_id' => $categoryId,
                'unit_id' => $unitIds[$item['unit']] ?? $unitIds['EA'],
                'supplier_id' => null,
                'min_level' => (int) $item['min_level'],
                'unit_cost' => 0,
                ...$now,
            ];
        }

        $this->chunk('items', $rows);

        return DB::table('items')->pluck('id', 'code')->all();
    }

    /**
     * @param  list<array<string, mixed>>  $locations
     * @return array<string, int>
     */
    private function insertLocations(array $locations): array
    {
        $now = $this->timestamps();
        $rows = array_map(fn (array $location) => [
            'code' => $location['code'],
            'zone' => $location['zone'],
            'rack' => $location['rack'],
            'shelf' => $location['shelf'],
            'kind' => $location['kind'],
            'image_path' => null,
            ...$now,
        ], $locations);

        $this->chunk('locations', $rows);

        return DB::table('locations')->pluck('id', 'code')->all();
    }

    /**
     * @param  list<array<string, mixed>>  $stock
     * @param  array<string, int>  $itemIds
     * @param  array<string, int>  $locationIds
     */
    private function insertStock(array $stock, array $itemIds, array $locationIds): void
    {
        $now = $this->timestamps();
        $batches = [];
        $balances = [];
        $movements = [];

        foreach ($stock as $index => $row) {
            $batchNo = sprintf('B%06d', $index + 1);
            $itemId = $itemIds[$row['item']];
            $locationId = $row['location'] ? $locationIds[$row['location']] : null;
            $batches[] = [
                'item_id' => $itemId,
                'supplier_id' => null,
                'batch_no' => $batchNo,
                'received_on' => $row['received_on'],
                'condition' => $row['condition'],
                'reference' => $row['reference'],
                'expires_on' => null,
                ...$now,
            ];
            $balances[] = [
                'batch_no' => $batchNo,
                'location_id' => $locationId,
                'quantity' => (int) $row['qty'],
                'reserved' => 0,
            ];
            $movements[] = [
                'type' => 'in',
                'item_id' => $itemId,
                'batch_no' => $batchNo,
                'from_location_id' => null,
                'to_location_id' => $locationId,
                'quantity' => (int) $row['qty'],
                'employee_id' => null,
                'reason' => 'receipt',
                'department' => null,
                'reference' => $row['reference'] ?: 'OPENING',
                'happened_at' => $row['received_on'].' 08:00:00',
            ];
        }

        $this->chunk('batches', $batches);
        $batchIds = DB::table('batches')->pluck('id', 'batch_no');

        $this->chunk('balances', array_map(fn (array $row) => [
            'batch_id' => $batchIds[$row['batch_no']],
            'location_id' => $row['location_id'],
            'quantity' => $row['quantity'],
            'reserved' => 0,
            ...$now,
        ], $balances));

        $this->chunk('movements', array_map(fn (array $row) => [
            'type' => $row['type'],
            'item_id' => $row['item_id'],
            'batch_id' => $batchIds[$row['batch_no']],
            'from_location_id' => $row['from_location_id'],
            'to_location_id' => $row['to_location_id'],
            'quantity' => $row['quantity'],
            'employee_id' => null,
            'reason' => $row['reason'],
            'department' => $row['department'],
            'reference' => $row['reference'],
            'happened_at' => $row['happened_at'],
            ...$now,
        ], $movements));
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @param  array<string, int>  $itemIds
     */
    private function insertOrders(array $orders, array $itemIds, int $supplierId): void
    {
        $now = $this->timestamps();

        foreach ($orders as $order) {
            $orderId = DB::table('purchase_orders')->insertGetId([
                'number' => $order['number'],
                'supplier_id' => $supplierId,
                'purchase_request_id' => null,
                'status' => $order['status'],
                'ordered_on' => $order['ordered_on'],
                'notes' => $order['notes'],
                ...$now,
            ]);

            $this->chunk('purchase_order_lines', array_map(fn (array $line) => [
                'purchase_order_id' => $orderId,
                'item_id' => $itemIds[$line['item']],
                'quantity' => (int) $line['qty'],
                'received_quantity' => (int) $line['qty'],
                ...$now,
            ], $order['lines']));

            if ($order['shipment']) {
                DB::table('shipments')->insert([
                    'purchase_order_id' => $orderId,
                    'ci_number' => $order['shipment']['ci'],
                    'expected_on' => $order['shipment']['expected_on'],
                    'status' => $order['shipment']['status'],
                    'packing_note' => $order['shipment']['packing_note'],
                    ...$now,
                ]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  array<string, int>  $itemIds
     */
    private function insertIssues(array $issues, array $itemIds): void
    {
        $now = $this->timestamps();
        $rows = array_map(fn (array $issue) => [
            'type' => $issue['direction'],
            'item_id' => $itemIds[$issue['item']],
            'batch_id' => null,
            'from_location_id' => null,
            'to_location_id' => null,
            'quantity' => (int) $issue['qty'],
            'employee_id' => null,
            'reason' => $issue['reason'],
            'department' => null,
            'reference' => $issue['reference'],
            'happened_at' => $issue['happened_at'],
            ...$now,
        ], $issues);

        $this->chunk('movements', $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function chunk(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    /**
     * @return array{created_at: string, updated_at: string}
     */
    private function timestamps(): array
    {
        $now = now()->toDateTimeString();

        return ['created_at' => $now, 'updated_at' => $now];
    }
}
