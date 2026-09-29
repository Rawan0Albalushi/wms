<?php

namespace Tests\Feature;

use App\Models\Balance;
use App\Models\Batch;
use App\Models\Item;
use App\Models\JobOrder;
use App\Models\Location;
use App\Models\Movement;
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

    private function onHand(Item $item): int
    {
        return (int) Balance::query()->whereHas('batch', fn ($query) => $query->where('item_id', $item->id))->sum('quantity');
    }
}
