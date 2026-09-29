<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Item;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\StockLedger;
use Database\Seeders\AccessSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_receipt_cannot_pair_a_shipment_with_another_order(): void
    {
        $this->prepare();

        $item = Item::query()->where('code', '441748')->firstOrFail();
        $location = Location::query()->where('code', 'S1A1')->firstOrFail();
        $shipment = Shipment::query()->where('ci_number', 'CI-9021')->firstOrFail();
        $other = PurchaseOrder::query()->where('number', 'PO-1042')->firstOrFail();

        $this->postJson('/api/receipts', $this->receipt($item, $location, [
            'quantity' => 5,
            'purchase_order_id' => $other->id,
            'shipment_id' => $shipment->id,
        ]))->assertStatus(422)->assertJsonPath('errors.shipment_id.0', 'shipment_order');

        $this->assertSame('in_transit', $shipment->fresh()->status);
        $this->assertSame(0, $this->received($shipment));
    }

    public function test_a_shipment_receipt_stops_at_the_remaining_order_quantity(): void
    {
        $this->prepare();

        $item = Item::query()->where('code', '441748')->firstOrFail();
        $location = Location::query()->where('code', 'S1A1')->firstOrFail();
        $shipment = Shipment::query()->where('ci_number', 'CI-9021')->firstOrFail();

        $this->postJson('/api/receipts', $this->receipt($item, $location, [
            'quantity' => 5,
            'shipment_id' => $shipment->id,
        ]))->assertCreated();

        $this->assertSame(5, $this->received($shipment));
        $this->assertSame('partial', $shipment->purchaseOrder->fresh()->status);
        $this->assertSame('arrived', $shipment->fresh()->status);

        $this->postJson('/api/receipts', $this->receipt($item, $location, [
            'batch_no' => 'B951',
            'quantity' => 16,
            'shipment_id' => $shipment->id,
        ]))->assertStatus(422)->assertJsonPath('errors.quantity.0', 'exceeds_order');

        $this->assertSame(5, $this->received($shipment));

        $this->postJson('/api/receipts', $this->receipt($item, $location, [
            'batch_no' => 'B952',
            'quantity' => 15,
            'purchase_order_id' => $shipment->purchase_order_id,
            'shipment_id' => $shipment->id,
        ]))->assertCreated();

        $this->assertSame(20, $this->received($shipment));
        $this->assertSame('received', $shipment->purchaseOrder->fresh()->status);
        $this->assertSame('received', $shipment->fresh()->status);

        $this->postJson('/api/receipts', $this->receipt($item, $location, [
            'batch_no' => 'B953',
            'quantity' => 1,
            'shipment_id' => $shipment->id,
        ]))->assertStatus(422)->assertJsonPath('errors.shipment_id.0', 'already_received');
    }

    public function test_new_documents_continue_the_existing_number_sequence(): void
    {
        $this->prepare();
        $this->actingAs(User::query()->where('email', 'admin@wms.test')->firstOrFail());

        $item = Item::query()->where('code', '441748')->firstOrFail();
        $supplier = Supplier::query()->firstOrFail();

        $this->postJson('/api/requests', [
            'item_id' => $item->id,
            'quantity' => 2,
        ])->assertCreated()->assertJsonPath('number', 'RFQ-19');

        $this->postJson('/api/requests', [
            'item_id' => $item->id,
            'quantity' => 3,
        ])->assertCreated()->assertJsonPath('number', 'RFQ-20');

        $this->postJson('/api/orders', [
            'supplier_id' => $supplier->id,
            'lines' => [
                ['item_id' => $item->id, 'quantity' => 4],
            ],
        ])->assertCreated()->assertJsonPath('number', 'PO-1056');

        $this->assertDatabaseHas('document_sequences', [
            'prefix' => 'RFQ',
            'current' => 20,
        ]);
        $this->assertDatabaseHas('document_sequences', [
            'prefix' => 'PO',
            'current' => 1056,
        ]);
    }

    public function test_refreshing_one_item_keeps_alerts_for_the_others(): void
    {
        $this->seed(WarehouseSeeder::class);

        $kept = Alert::query()->whereNotNull('item_id')->firstOrFail();
        $other = Item::query()->whereKeyNot($kept->item_id)->value('id');

        app(StockLedger::class)->syncTouched([(int) $other]);

        $this->assertNotNull(Alert::query()->where('fingerprint', $kept->fingerprint)->first());
    }

    private function prepare(): void
    {
        $this->seed(WarehouseSeeder::class);
        $this->seed(AccessSeeder::class);
        $this->actingAs(User::query()->where('email', 'keeper@wms.test')->firstOrFail());
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function receipt(Item $item, Location $location, array $extra): array
    {
        return [
            'item_id' => $item->id,
            'location_id' => $location->id,
            'batch_no' => 'B950',
            'condition' => 'new',
            ...$extra,
        ];
    }

    private function received(Shipment $shipment): int
    {
        return (int) PurchaseOrderLine::query()
            ->where('purchase_order_id', $shipment->purchase_order_id)
            ->where('item_id', Item::query()->where('code', '441748')->value('id'))
            ->value('received_quantity');
    }
}
