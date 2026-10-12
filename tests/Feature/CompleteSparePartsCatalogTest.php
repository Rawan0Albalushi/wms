<?php

namespace Tests\Feature;

use App\Models\Balance;
use App\Models\Batch;
use App\Models\Category;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\RecordedPosition;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\CompleteSparePartsCatalog;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompleteSparePartsCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_completion_adds_missing_rows_without_changing_stock_or_existing_orders(): void
    {
        $category = Category::query()->create(['code' => 'SPR', 'name_ar' => 'قطع غيار', 'name_en' => 'Spare parts']);
        $unit = Unit::query()->create(['code' => 'EA', 'name_ar' => 'حبة', 'name_en' => 'Each']);
        $supplier = Supplier::query()->create(['code' => 'SUP-01', 'name' => 'مورد', 'name_en' => 'Supplier']);
        $item = Item::query()->create([
            'code' => 'A1',
            'name_ar' => 'اسم قديم',
            'name_en' => 'Old name',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'min_level' => 0,
            'unit_cost' => 9,
        ]);
        $batch = Batch::query()->create([
            'item_id' => $item->id,
            'batch_no' => 'B1',
            'received_on' => '2026-01-01',
            'condition' => 'new',
        ]);
        $balance = Balance::query()->create([
            'batch_id' => $batch->id,
            'quantity' => 5,
            'reserved' => 0,
        ]);
        $order = PurchaseOrder::query()->create([
            'number' => 'CI-1',
            'supplier_id' => $supplier->id,
            'status' => 'received',
            'kind' => 'receipt',
            'ordered_on' => '2026-01-01',
            'notes' => 'keep me',
        ]);
        Shipment::query()->create([
            'purchase_order_id' => $order->id,
            'ci_number' => '1',
            'expected_on' => '2026-01-01',
            'status' => 'received',
        ]);

        $workbook = [
            'items' => [
                ['code' => 'A1', 'name' => 'اسم جديد', 'part_number' => 'P1', 'units' => ['EA', 'GL'], 'stock_status' => 'rop', 'min_level' => 4, 'family' => 'Housing 538'],
                ['code' => 'B2', 'name' => 'صنف جديد', 'part_number' => 'P2', 'units' => ['EA'], 'stock_status' => 'sufficient', 'min_level' => 0, 'family' => null],
            ],
            'positions' => [
                ['item' => 'A1', 'location' => 'S1J5', 'qty' => 9, 'condition' => 'new', 'reference' => 'CI', 'type' => 'Housing 538', 'recorded_on' => '2026-09-01'],
            ],
            'future' => [
                ['number' => 'RFQ-9', 'ordered_on' => '2022-03-01', 'exw_on' => '2022-06-01', 'sheet_status' => 'In transit', 'stage' => 'Accepted', 'lines' => [
                    ['item' => 'A1', 'name' => 'اسم جديد', 'unit' => 'EA', 'qty' => 3],
                ]],
            ],
            'receipts' => [
                ['number' => 'CI-1', 'ci' => '1', 'ordered_on' => '2026-02-01', 'packing_note' => 'changed', 'lines' => [
                    ['item' => 'A1', 'name' => 'اسم جديد', 'unit' => 'EA', 'qty' => 8],
                ]],
                ['number' => 'CI-2', 'ci' => '2', 'ordered_on' => '2026-03-01', 'packing_note' => 'PL 10', 'lines' => [
                    ['item' => 'B2', 'name' => 'صنف جديد', 'unit' => 'EA', 'qty' => 2],
                ]],
            ],
        ];

        $catalog = app(CompleteSparePartsCatalog::class);
        $first = $catalog->apply($workbook, true);
        $second = $catalog->apply($workbook, true);

        $balance->refresh();
        $item->refresh();
        $order->refresh();

        $this->assertSame(5, $balance->quantity);
        $this->assertSame('S1J5', $balance->location->code);
        $this->assertSame(4, $item->min_level);
        $this->assertSame('rop', $item->stock_status);
        $this->assertSame('Housing 538', $item->family);
        $this->assertSame('GL', $item->alt_unit);
        $this->assertSame('P1', $item->part_number);
        $this->assertSame('Old name', $item->name_en);
        $this->assertSame(9, $item->unit_cost);
        $this->assertSame('keep me', $order->notes);
        $this->assertSame(0, $order->lines()->count());
        $this->assertDatabaseHas('items', ['code' => 'B2']);
        $this->assertDatabaseMissing('balances', ['quantity' => 9]);
        $this->assertSame(1, RecordedPosition::query()->count());
        $this->assertSame(9, RecordedPosition::query()->value('quantity'));
        $this->assertDatabaseHas('purchase_orders', ['number' => 'RFQ-9', 'status' => 'historical', 'kind' => 'future']);
        $this->assertDatabaseHas('purchase_orders', ['number' => 'CI-2', 'status' => 'received', 'kind' => 'receipt']);
        $this->assertDatabaseHas('shipments', ['ci_number' => '2']);
        $this->assertSame(1, $first['positions_created']);
        $this->assertSame(0, $second['positions_created']);
        $this->assertSame(0, $second['future_orders_created']);
        $this->assertSame(0, $second['receipts_created']);
        $this->assertSame(0, $second['items_created']);
    }

    public function test_archived_rfq_orders_stay_received_in_the_database_and_show_as_historical(): void
    {
        $this->seed(AccessSeeder::class);
        $supplier = Supplier::query()->create(['code' => 'SUP-01', 'name' => 'مورد', 'name_en' => 'Supplier']);
        PurchaseOrder::query()->create([
            'number' => 'RFQ-2018',
            'supplier_id' => $supplier->id,
            'status' => 'received',
            'kind' => 'receipt',
            'ordered_on' => '2022-01-01',
            'notes' => 'Future arrival archive.',
        ]);
        $receipt = PurchaseOrder::query()->create([
            'number' => 'CI-9',
            'supplier_id' => $supplier->id,
            'status' => 'received',
            'kind' => 'receipt',
            'ordered_on' => '2026-01-01',
        ]);
        Shipment::query()->create([
            'purchase_order_id' => $receipt->id,
            'ci_number' => '9',
            'expected_on' => '2026-01-01',
            'status' => 'received',
        ]);

        $orders = collect($this->actingAs(User::query()->where('email', 'admin@wms.test')->firstOrFail())
            ->getJson('/api/procurement')
            ->assertOk()
            ->json('orders'));

        $rfq = $orders->firstWhere('number', 'RFQ-2018');
        $ci = $orders->firstWhere('number', 'CI-9');

        $this->assertSame('historical', $rfq['status']);
        $this->assertSame('future', $rfq['kind']);
        $this->assertSame('received', $ci['status']);
        $this->assertSame('receipt', $ci['kind']);
        $this->assertDatabaseHas('purchase_orders', [
            'number' => 'RFQ-2018',
            'status' => 'received',
            'kind' => 'receipt',
        ]);
    }
}
