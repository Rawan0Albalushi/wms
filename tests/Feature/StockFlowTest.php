<?php

namespace Tests\Feature;

use App\Models\Balance;
use App\Models\Batch;
use App\Models\Item;
use App\Models\JobOrder;
use App\Models\Location;
use App\Models\Movement;
use App\Models\RecordedPosition;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_issue_and_transfer_keep_quantities_honest(): void
    {
        $this->seed(WarehouseSeeder::class);
        $this->act();

        $item = Item::query()->where('code', '268625')->firstOrFail();
        $before = $this->onHand($item);
        $dock = Location::query()->where('code', 'S1A1')->firstOrFail();

        $this->postJson('/api/receipts', [
            'item_id' => $item->id,
            'location_id' => $dock->id,
            'batch_no' => 'B900',
            'quantity' => 5,
            'condition' => 'new',
            'reference' => 'TEST',
        ])->assertCreated();

        $this->assertSame($before + 5, $this->onHand($item));

        $batch = Batch::query()->where('batch_no', 'B900')->firstOrFail();
        $position = Balance::query()->where('batch_id', $batch->id)->firstOrFail();

        $this->postJson('/api/issues', [
            'position_id' => $position->id,
            'quantity' => 2,
            'reason' => 'maintenance',
            'department' => 'Workshop',
        ])->assertCreated();

        $this->postJson('/api/issues', [
            'position_id' => $position->id,
            'quantity' => 99,
            'reason' => 'other',
        ])->assertStatus(422);

        $dest = Location::query()->where('code', 'S1B2')->firstOrFail();

        $this->postJson('/api/transfers', [
            'position_id' => $position->id,
            'quantity' => 3,
            'to_location_id' => $dest->id,
        ])->assertCreated();

        $this->assertSame(1, Batch::query()->where('batch_no', 'B900')->count());
        $this->assertDatabaseHas('balances', [
            'batch_id' => $batch->id,
            'location_id' => $dest->id,
            'quantity' => 3,
        ]);
        $this->assertDatabaseMissing('balances', [
            'batch_id' => $batch->id,
            'location_id' => $dock->id,
        ]);
        $this->assertNotNull(Movement::query()->where('batch_id', $batch->id)->where('type', 'transfer')->value('employee_id'));
    }

    public function test_partial_transfer_keeps_one_batch_in_both_bins(): void
    {
        $this->seed(WarehouseSeeder::class);
        $this->act();

        $item = Item::query()->where('code', '268625')->firstOrFail();
        $from = Location::query()->where('code', 'S1A2')->firstOrFail();
        $to = Location::query()->where('code', 'S1B3')->firstOrFail();

        $this->postJson('/api/receipts', [
            'item_id' => $item->id,
            'location_id' => $from->id,
            'batch_no' => 'B901',
            'quantity' => 10,
            'condition' => 'new',
        ])->assertCreated();

        $batch = Batch::query()->where('batch_no', 'B901')->firstOrFail();
        $position = Balance::query()->where('batch_id', $batch->id)->firstOrFail();

        $this->postJson('/api/transfers', [
            'position_id' => $position->id,
            'quantity' => 4,
            'to_location_id' => $to->id,
        ])->assertCreated();

        $this->assertSame(1, Batch::query()->where('batch_no', 'B901')->count());
        $this->assertDatabaseHas('balances', [
            'batch_id' => $batch->id,
            'location_id' => $from->id,
            'quantity' => 6,
        ]);
        $this->assertDatabaseHas('balances', [
            'batch_id' => $batch->id,
            'location_id' => $to->id,
            'quantity' => 4,
        ]);
    }

    public function test_receiving_the_same_batch_again_adds_a_location(): void
    {
        $this->seed(WarehouseSeeder::class);
        $this->act();

        $item = Item::query()->where('code', '268625')->firstOrFail();
        $other = Item::query()->where('code', '266825')->firstOrFail();
        $first = Location::query()->where('code', 'S1A4')->firstOrFail();
        $second = Location::query()->where('code', 'S1A5')->firstOrFail();

        $this->postJson('/api/receipts', [
            'item_id' => $item->id,
            'location_id' => $first->id,
            'batch_no' => 'B902',
            'quantity' => 8,
            'condition' => 'new',
        ])->assertCreated();

        $this->postJson('/api/receipts', [
            'item_id' => $item->id,
            'location_id' => $second->id,
            'batch_no' => 'B902',
            'quantity' => 3,
            'condition' => 'new',
        ])->assertCreated();

        $batch = Batch::query()->where('batch_no', 'B902')->firstOrFail();

        $this->assertSame(1, Batch::query()->where('batch_no', 'B902')->count());
        $this->assertSame(11, (int) Balance::query()->where('batch_id', $batch->id)->sum('quantity'));
        $this->assertSame(2, Balance::query()->where('batch_id', $batch->id)->count());

        $this->postJson('/api/receipts', [
            'item_id' => $other->id,
            'location_id' => $first->id,
            'batch_no' => 'B902',
            'quantity' => 1,
            'condition' => 'new',
        ])->assertStatus(422);
    }

    public function test_partial_reservation_is_not_marked_fully_reserved(): void
    {
        $this->seed(WarehouseSeeder::class);
        $this->act();

        $item = Item::query()->where('code', '268625')->firstOrFail();
        $onHand = $this->onHand($item);

        $this->postJson('/api/jobs', [
            'title' => 'Partial hold',
            'lines' => [['item_id' => $item->id, 'quantity' => $onHand + 5]],
        ])->assertCreated();

        $job = JobOrder::query()->where('title', 'Partial hold')->firstOrFail();

        $this->postJson('/api/jobs/'.$job->id.'/reserve')->assertOk();
        $this->assertSame('partial_hold', $job->fresh()->status);

        $position = Balance::query()->where('quantity', '>', 0)->firstOrFail();

        $this->postJson('/api/issues', [
            'position_id' => $position->id,
            'quantity' => 1,
            'reason' => 'job_order',
        ])->assertStatus(422);
    }

    public function test_emptied_bin_keeps_the_item_that_left(): void
    {
        $this->seed(WarehouseSeeder::class);
        $this->act();

        $item = Item::query()->where('code', '268625')->firstOrFail();
        $bin = Location::query()->where('code', 'S1F1')->firstOrFail();
        $unused = Location::query()->where('code', 'S1F2')->firstOrFail();
        $stocked = Location::query()->where('code', 'S1A1')->firstOrFail();

        $this->assertNull($this->locationRow($unused->code)['cleared']);
        $this->assertNull($this->locationRow($stocked->code)['cleared']);
        $this->assertGreaterThan(0, $this->locationRow($stocked->code)['on_hand']);

        $this->postJson('/api/receipts', [
            'item_id' => $item->id,
            'location_id' => $bin->id,
            'batch_no' => 'B950',
            'quantity' => 4,
            'condition' => 'new',
        ])->assertCreated();

        $this->assertNull($this->locationRow($bin->code)['cleared']);

        $position = Balance::query()->where('location_id', $bin->id)->firstOrFail();

        $this->postJson('/api/issues', [
            'position_id' => $position->id,
            'quantity' => 4,
            'reason' => 'maintenance',
        ])->assertCreated();

        $cleared = $this->locationRow($bin->code);

        $this->assertSame(0, $cleared['on_hand']);
        $this->assertSame('268625', $cleared['cleared']['item_code']);
        $this->assertSame('out', $cleared['cleared']['type']);
        $this->assertSame(4, $cleared['cleared']['quantity']);
        $this->assertNull($this->locationRow($unused->code)['cleared']);

        $history = $this->getJson('/api/locations/'.$bin->id.'/history')->assertOk()->json();

        $this->assertSame(['out', 'in'], array_column($history, 'type'));
        $this->assertSame('S1F1', $history[0]['from']);
        $this->assertSame('S1F1', $history[1]['to']);
    }

    public function test_empty_bin_shows_the_recorded_item_that_used_to_be_there(): void
    {
        $this->seed(WarehouseSeeder::class);
        $this->act();

        $item = Item::query()->where('code', '268625')->firstOrFail();
        $bin = Location::query()->where('code', 'S1F2')->firstOrFail();

        RecordedPosition::query()->create([
            'item_id' => $item->id,
            'location_id' => $bin->id,
            'quantity' => 4,
            'condition' => 'new',
            'recorded_on' => '2026-09-01',
            'import_key' => 'test-s1f2',
        ]);

        $row = $this->locationRow($bin->code);

        $this->assertSame(0, $row['on_hand']);
        $this->assertSame('268625', $row['cleared']['item_code']);
        $this->assertSame('recorded', $row['cleared']['type']);

        $history = $this->getJson('/api/locations/'.$bin->id.'/history')->assertOk()->json();

        $this->assertSame('recorded', $history[0]['type']);
        $this->assertSame('268625', $history[0]['item_code']);
        $this->assertSame(4, $history[0]['quantity']);
        $this->assertSame('S1F2', $history[0]['to']);
    }

    public function test_stocked_bin_lists_every_item_on_hand(): void
    {
        $this->seed(WarehouseSeeder::class);
        $this->act();

        $first = Item::query()->where('code', '268625')->firstOrFail();
        $second = Item::query()->where('code', '!=', '268625')->orderBy('code')->firstOrFail();
        $bin = Location::query()->where('code', 'S1F1')->firstOrFail();
        $empty = Location::query()->where('code', 'S1F2')->firstOrFail();

        $this->postJson('/api/receipts', [
            'item_id' => $first->id,
            'location_id' => $bin->id,
            'batch_no' => 'B950',
            'quantity' => 4,
            'condition' => 'new',
        ])->assertCreated();

        $this->postJson('/api/receipts', [
            'item_id' => $first->id,
            'location_id' => $bin->id,
            'batch_no' => 'B952',
            'quantity' => 3,
            'condition' => 'new',
        ])->assertCreated();

        $this->postJson('/api/receipts', [
            'item_id' => $second->id,
            'location_id' => $bin->id,
            'batch_no' => 'B951',
            'quantity' => 2,
            'condition' => 'new',
        ])->assertCreated();

        $stock = collect($this->getJson('/api/locations/'.$bin->id.'/stock')->assertOk()->json());

        $codes = collect([$first->code, $second->code])->sort()->values()->all();

        $this->assertSame($codes, $stock->pluck('item_code')->sort()->values()->all());
        $this->assertSame(7, $stock->firstWhere('item_code', $first->code)['quantity']);
        $this->assertSame(2, $stock->firstWhere('item_code', $second->code)['quantity']);
        $this->assertSame([], $this->getJson('/api/locations/'.$empty->id.'/stock')->assertOk()->json());
    }

    public function test_home_queue_points_at_the_next_warehouse_step(): void
    {
        $this->seed(WarehouseSeeder::class);
        $this->act();

        $tasks = collect($this->getJson('/api/board')->assertOk()->json('tasks'));

        $this->assertTrue($tasks->contains(fn (array $task) => $task['kind'] === 'putaway' && $task['ref'] === 'B710'));
        $this->assertTrue($tasks->contains(fn (array $task) => $task['kind'] === 'reserve' && $task['ref'] === 'JOB-0690'));
        $this->assertTrue($tasks->contains(fn (array $task) => $task['kind'] === 'arrive' && $task['ref'] === 'CI-9021'));
    }

    private function act(): void
    {
        $this->seed(AccessSeeder::class);
        $user = User::query()->where('email', 'keeper@wms.test')->firstOrFail();
        $this->actingAs($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function locationRow(string $code): array
    {
        $row = collect($this->getJson('/api/locations')->assertOk()->json())->firstWhere('code', $code);

        $this->assertIsArray($row);

        return $row;
    }

    private function onHand(Item $item): int
    {
        return (int) Balance::query()->whereHas('batch', fn ($query) => $query->where('item_id', $item->id))->sum('quantity');
    }
}
