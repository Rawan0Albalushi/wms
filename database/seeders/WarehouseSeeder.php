<?php

namespace Database\Seeders;

use App\Models\Balance;
use App\Models\Batch;
use App\Models\Category;
use App\Models\CountLine;
use App\Models\Employee;
use App\Models\Item;
use App\Models\JobOrder;
use App\Models\Location;
use App\Models\Movement;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Shipment;
use App\Models\StockCount;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\StockLedger;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $categories = collect([
            ['HYD', 'هيدروليك', 'Hydraulic'],
            ['MEC', 'ميكانيك', 'Mechanical'],
            ['SEL', 'إحكام', 'Sealing'],
            ['FST', 'مثبتات', 'Fasteners'],
        ])->mapWithKeys(fn (array $row) => [
            $row[0] => Category::query()->create(['code' => $row[0], 'name_ar' => $row[1], 'name_en' => $row[2]]),
        ]);

        $units = collect([
            ['EA', 'حبة', 'Each'],
            ['FT', 'قدم', 'Foot'],
            ['M', 'متر', 'Meter'],
        ])->mapWithKeys(fn (array $row) => [
            $row[0] => Unit::query()->create(['code' => $row[0], 'name_ar' => $row[1], 'name_en' => $row[2]]),
        ]);

        $gulf = Supplier::query()->create([
            'code' => 'SUP-01',
            'name' => 'قطع الخليج',
            'name_en' => 'Gulf Parts',
            'contact_name' => 'سالم الهنائي',
            'phone' => '+968 2400 1100',
            'email' => 'supply@gulfparts.om',
        ]);
        $noor = Supplier::query()->create([
            'code' => 'SUP-02',
            'name' => 'النور الصناعية',
            'name_en' => 'Al Noor Industrial',
            'contact_name' => 'مها الراشدية',
            'phone' => '+968 2455 2200',
        ]);
        $mazoon = Supplier::query()->create([
            'code' => 'SUP-03',
            'name' => 'مزون للتوريد',
            'name_en' => 'Mazoon Supply',
            'contact_name' => 'خالد المعمري',
            'phone' => '+968 2321 7780',
        ]);

        foreach (range('A', 'F') as $rack) {
            foreach (range(1, 6) as $shelf) {
                Location::query()->create([
                    'code' => "S1{$rack}{$shelf}",
                    'zone' => 'S1',
                    'rack' => $rack,
                    'shelf' => (string) $shelf,
                    'kind' => 'storage',
                ]);
            }
        }

        Location::query()->create(['code' => 'S2A1', 'zone' => 'S2', 'rack' => 'A', 'shelf' => '1', 'kind' => 'storage']);
        Location::query()->create(['code' => 'DOCK', 'zone' => 'DOCK', 'kind' => 'dock']);
        Location::query()->create(['code' => 'QUAR', 'zone' => 'QUAR', 'kind' => 'quarantine']);

        $locations = Location::query()->get()->keyBy('code');
        $keeper = Employee::query()->firstOrCreate(['name' => 'راشد الكندي'], ['active' => true]);
        Employee::query()->firstOrCreate(['name' => 'فاطمة السيابية'], ['active' => true]);

        $make = function (array $row) use ($categories, $units) {
            return Item::query()->create([
                'code' => $row['code'],
                'part_number' => $row['part'] ?? null,
                'barcode' => $row['barcode'] ?? $row['code'],
                'name_ar' => $row['ar'],
                'name_en' => $row['en'],
                'category_id' => $categories[$row['cat']]->id,
                'unit_id' => $units[$row['unit']]->id,
                'supplier_id' => $row['supplier']->id,
                'min_level' => $row['min'],
                'unit_cost' => $row['cost'],
            ]);
        };

        $coupling = $make(['code' => '268625', 'part' => '1296149', 'barcode' => '268625', 'ar' => 'وصلة هيدروليك', 'en' => 'Hydraulic coupling', 'cat' => 'HYD', 'unit' => 'EA', 'supplier' => $gulf, 'min' => 15, 'cost' => 86]);
        $valveKit = $make(['code' => '266825', 'part' => '884120', 'ar' => 'طقم صمام', 'en' => 'Valve kit', 'cat' => 'MEC', 'unit' => 'EA', 'supplier' => $noor, 'min' => 25, 'cost' => 54]);
        $bearing = $make(['code' => '331204', 'part' => '1002216', 'ar' => 'طقم رولمان', 'en' => 'Bearing set', 'cat' => 'MEC', 'unit' => 'EA', 'supplier' => $gulf, 'min' => 5, 'cost' => 22]);
        $seal = $make(['code' => '236122', 'part' => '551903', 'ar' => 'طقم جوانات', 'en' => 'Seal kit', 'cat' => 'SEL', 'unit' => 'EA', 'supplier' => $mazoon, 'min' => 4, 'cost' => 9]);
        $valveBody = $make(['code' => '441748', 'part' => '772610', 'ar' => 'جسم صمام', 'en' => 'Valve body', 'cat' => 'MEC', 'unit' => 'EA', 'supplier' => $noor, 'min' => 8, 'cost' => 40]);
        $filter = $make(['code' => '447148', 'part' => '330118', 'ar' => 'عنصر فلتر', 'en' => 'Filter element', 'cat' => 'MEC', 'unit' => 'EA', 'supplier' => $gulf, 'min' => 2, 'cost' => 15]);
        $gasket = $make(['code' => '521502', 'part' => '190044', 'ar' => 'حشية', 'en' => 'Gasket', 'cat' => 'SEL', 'unit' => 'EA', 'supplier' => $mazoon, 'min' => 10, 'cost' => 3]);
        $make(['code' => '736122', 'part' => '660210', 'ar' => 'ماسورة فولاذ', 'en' => 'Steel pipe', 'cat' => 'MEC', 'unit' => 'M', 'supplier' => $gulf, 'min' => 6, 'cost' => 11]);
        $bolt = $make(['code' => '10076210', 'part' => 'M16-80', 'ar' => 'مسمار M16', 'en' => 'Bolt M16', 'cat' => 'FST', 'unit' => 'EA', 'supplier' => $noor, 'min' => 20, 'cost' => 1]);

        $stock = function (Item $item, string $batch, ?string $location, int $qty, string $condition, string $received, ?string $reference = null, ?string $expires = null, ?Supplier $supplier = null) use ($locations) {
            $created = Batch::query()->create([
                'item_id' => $item->id,
                'supplier_id' => ($supplier ?? $item->supplier)?->id,
                'batch_no' => $batch,
                'received_on' => $received,
                'condition' => $condition,
                'reference' => $reference,
                'expires_on' => $expires,
            ]);

            Balance::query()->create([
                'batch_id' => $created->id,
                'location_id' => $location ? $locations[$location]->id : null,
                'quantity' => $qty,
            ]);

            return $created;
        };

        $b001 = $stock($coupling, 'B001', 'S1E6', 7, 'new', '2026-09-01', 'PO-1042', null, $gulf);
        $b002 = $stock($coupling, 'B002', 'S1D6', 22, 'new', '2026-09-01', 'PO-1042', null, $gulf);
        Balance::query()->create([
            'batch_id' => $b002->id,
            'location_id' => $locations['S1C6']->id,
            'quantity' => 17,
        ]);
        $b003 = $stock($coupling, 'B003', 'S1D6', 17, 'used', '2026-09-01', 'CI-7712', null, $gulf);
        $stock($valveKit, 'B110', 'S1F6', 20, 'new', '2026-08-18', 'OPENING', null, $noor);
        $bearingBatch = $stock($bearing, 'B210', 'S1B2', 11, 'new', '2026-09-04', 'TR-14', null, $gulf);
        $sealBatch = $stock($seal, 'B310', 'S1C3', 8, 'new', '2026-09-02', 'OPENING', null, $mazoon);
        $stock($valveBody, 'B410', 'S1A3', 10, 'used', '2026-08-22', 'OPENING', null, $noor);
        $stock($filter, 'B510', 'S1A1', 3, 'new', '2026-07-01', 'PO-0991', '2026-10-15', $gulf);
        $stock($gasket, 'B610', 'S2A1', 4, 'new', '2026-08-01', 'OPENING', null, $mazoon);
        $boltBatch = $stock($bolt, 'B710', null, 6, 'new', '2026-09-15', 'UNLOCATED', null, $noor);

        PurchaseRequest::query()->create([
            'number' => 'RFQ-18',
            'item_id' => $filter->id,
            'quantity' => 12,
            'status' => 'open',
            'note' => 'تجديد مخزون الفلاتر قبل موسم الصيانة',
        ]);

        $received = PurchaseOrder::query()->create([
            'number' => 'PO-1042',
            'supplier_id' => $gulf->id,
            'status' => 'received',
            'ordered_on' => '2026-09-01',
        ]);
        $received->lines()->create([
            'item_id' => $coupling->id,
            'quantity' => 46,
            'received_quantity' => 46,
        ]);

        $incoming = PurchaseOrder::query()->create([
            'number' => 'PO-1055',
            'supplier_id' => $noor->id,
            'status' => 'ordered',
            'ordered_on' => '2026-09-20',
            'notes' => 'لتغطية عجز جسم الصمام',
        ]);
        $incoming->lines()->create([
            'item_id' => $valveBody->id,
            'quantity' => 20,
            'received_quantity' => 0,
        ]);

        Shipment::query()->create([
            'purchase_order_id' => $received->id,
            'ci_number' => 'CI-7712',
            'expected_on' => '2026-09-01',
            'status' => 'received',
            'packing_note' => '4 صناديق',
        ]);
        Shipment::query()->create([
            'purchase_order_id' => $incoming->id,
            'ci_number' => 'CI-9021',
            'expected_on' => '2026-09-12',
            'status' => 'in_transit',
            'packing_note' => 'قائمة التعبئة ناقصة ختماً',
        ]);

        $closed = JobOrder::query()->create([
            'number' => 'JOB-0687',
            'title' => 'تجميع وحدة هيدروليك',
            'status' => 'closed',
        ]);
        $closed->lines()->create([
            'item_id' => $seal->id,
            'required_qty' => 4,
            'issued_qty' => 4,
        ]);

        $open = JobOrder::query()->create([
            'number' => 'JOB-0690',
            'title' => 'صيانة خط التبريد',
            'status' => 'open',
        ]);
        foreach ([[$valveKit, 50], [$bearing, 10], [$valveBody, 20]] as [$item, $qty]) {
            $open->lines()->create([
                'item_id' => $item->id,
                'required_qty' => $qty,
            ]);
        }

        $journal = function (string $type, Batch $batch, int $qty, string $at, array $extra = []) use ($locations, $keeper) {
            Movement::query()->create([
                'type' => $type,
                'item_id' => $batch->item_id,
                'batch_id' => $batch->id,
                'employee_id' => $keeper->id,
                'quantity' => $qty,
                'happened_at' => Carbon::parse($at),
                'from_location_id' => isset($extra['from']) ? $locations[$extra['from']]->id : null,
                'to_location_id' => isset($extra['to']) ? $locations[$extra['to']]->id : null,
                'reason' => $extra['reason'] ?? null,
                'reference' => $extra['reference'] ?? null,
                'department' => $extra['department'] ?? null,
            ]);
        };

        $journal('in', $b001, 7, '2026-09-01 09:00:00', ['to' => 'S1E6', 'reason' => 'receipt', 'reference' => 'PO-1042']);
        $journal('in', $b002, 39, '2026-09-01 09:10:00', ['to' => 'S1D6', 'reason' => 'receipt', 'reference' => 'PO-1042']);
        $journal('transfer', $b002, 17, '2026-09-03 10:00:00', ['from' => 'S1D6', 'to' => 'S1C6', 'reason' => 'transfer']);
        $journal('in', $b003, 17, '2026-09-01 11:00:00', ['to' => 'S1D6', 'reason' => 'receipt', 'reference' => 'CI-7712']);
        $journal('in', $bearingBatch, 11, '2026-09-04 08:30:00', ['to' => 'S1D6', 'reason' => 'receipt', 'reference' => 'TR-14']);
        $journal('transfer', $bearingBatch, 11, '2026-09-07 14:00:00', ['from' => 'S1D6', 'to' => 'S1B2', 'reason' => 'transfer']);
        $journal('in', $sealBatch, 12, '2026-09-02 10:00:00', ['to' => 'S1C3', 'reason' => 'receipt', 'reference' => 'OPENING']);
        $journal('consume', $sealBatch, 4, '2026-09-06 16:20:00', ['from' => 'S1C3', 'reason' => 'job_order', 'reference' => 'JOB-0687', 'department' => 'الورشة']);
        $journal('in', $boltBatch, 6, '2026-09-15 13:00:00', ['reason' => 'receipt', 'reference' => 'UNLOCATED']);

        foreach (Batch::query()->with('balances')->get() as $batch) {
            if (in_array($batch->batch_no, ['B001', 'B002', 'B003', 'B210', 'B310', 'B710'], true)) {
                continue;
            }

            foreach ($batch->balances as $balance) {
                Movement::query()->create([
                    'type' => 'in',
                    'item_id' => $batch->item_id,
                    'batch_id' => $batch->id,
                    'employee_id' => $keeper->id,
                    'to_location_id' => $balance->location_id,
                    'quantity' => $balance->quantity,
                    'reason' => 'receipt',
                    'reference' => $batch->reference,
                    'happened_at' => Carbon::parse($batch->received_on->toDateString().' 08:00:00'),
                ]);
            }
        }

        $count = StockCount::query()->create([
            'number' => 'CNT-0912',
            'status' => 'open',
            'started_at' => Carbon::parse('2026-09-27 08:00:00'),
        ]);

        foreach (Balance::query()->with('batch')->where('quantity', '>', 0)->orderBy('id')->get() as $balance) {
            $counted = $balance->batch->batch_no === 'B003' ? 15 : null;
            CountLine::query()->create([
                'count_id' => $count->id,
                'item_id' => $balance->batch->item_id,
                'location_id' => $balance->location_id,
                'batch_id' => $balance->batch_id,
                'system_qty' => $balance->quantity,
                'counted_qty' => $counted,
                'variance' => $counted === null ? null : $counted - $balance->quantity,
            ]);
        }

        app(StockLedger::class)->syncAlerts();
    }
}
