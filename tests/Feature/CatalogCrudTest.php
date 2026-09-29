<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use App\Models\Location;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_unused_catalog_records_can_be_updated_and_deleted(): void
    {
        $this->seed(WarehouseSeeder::class);
        $this->seed(AccessSeeder::class);
        $this->actingAs(User::query()->where('email', 'admin@wms.test')->firstOrFail());

        $supplier = $this->postJson('/api/suppliers', [
            'code' => 'SUP-NEW',
            'name' => 'مورد جديد',
            'name_en' => 'New supplier',
            'phone' => '90000000',
        ])->assertCreated()->json();

        $this->patchJson('/api/suppliers/'.$supplier['id'], [
            'code' => 'SUP-NEW',
            'name' => 'مورد معدّل',
            'name_en' => 'Edited supplier',
        ])->assertOk()
            ->assertJsonPath('code', 'SUP-NEW');

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier['id'],
            'name_en' => 'Edited supplier',
            'phone' => null,
        ]);

        $this->deleteJson('/api/suppliers/'.$supplier['id'])->assertOk();
        $this->assertDatabaseMissing('suppliers', ['id' => $supplier['id']]);

        $location = $this->postJson('/api/locations', [
            'code' => 'QA-1',
            'zone' => 'QA',
            'kind' => 'quarantine',
        ])->assertCreated()->json();

        $this->patchJson('/api/locations/'.$location['id'], [
            'code' => 'QA-1',
            'zone' => 'QZ',
            'rack' => 'A',
            'kind' => 'storage',
        ])->assertOk();

        $this->assertDatabaseHas('locations', [
            'id' => $location['id'],
            'zone' => 'QZ',
            'rack' => 'A',
            'kind' => 'storage',
        ]);

        $this->deleteJson('/api/locations/'.$location['id'])->assertOk();
        $this->assertDatabaseMissing('locations', ['id' => $location['id']]);

        $item = $this->postJson('/api/items', [
            'code' => 'CRUD-1',
            'name_ar' => 'صنف تجريبي',
            'name_en' => 'Sample item',
            'category_id' => Category::query()->value('id'),
            'unit_id' => Unit::query()->value('id'),
            'min_level' => 0,
            'unit_cost' => 4,
        ])->assertCreated()->json();

        $this->patchJson('/api/items/'.$item['id'], [
            'code' => 'CRUD-1',
            'name_ar' => 'صنف معدّل',
            'name_en' => 'Edited item',
            'category_id' => Category::query()->value('id'),
            'unit_id' => Unit::query()->value('id'),
            'min_level' => 2,
            'unit_cost' => 4,
        ])->assertOk();

        $this->deleteJson('/api/items/'.$item['id'])->assertOk();
        $this->assertDatabaseMissing('items', ['id' => $item['id']]);
    }

    public function test_records_used_by_stock_or_documents_cannot_be_deleted(): void
    {
        $this->seed(WarehouseSeeder::class);
        $this->seed(AccessSeeder::class);
        $this->actingAs(User::query()->where('email', 'admin@wms.test')->firstOrFail());

        $item = Item::query()->whereHas('batches')->firstOrFail();
        $location = Location::query()->whereHas('balances')->firstOrFail();
        $supplier = Supplier::query()->whereHas('items')->firstOrFail();

        $this->deleteJson('/api/items/'.$item->id)->assertStatus(422)->assertJsonPath('errors.record.0', 'in_use');
        $this->deleteJson('/api/locations/'.$location->id)->assertStatus(422)->assertJsonPath('errors.record.0', 'in_use');
        $this->deleteJson('/api/suppliers/'.$supplier->id)->assertStatus(422)->assertJsonPath('errors.record.0', 'in_use');

        $this->assertDatabaseHas('items', ['id' => $item->id]);
        $this->assertDatabaseHas('locations', ['id' => $location->id]);
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
    }

    public function test_storekeeper_cannot_change_the_catalog(): void
    {
        $this->seed(WarehouseSeeder::class);
        $this->seed(AccessSeeder::class);
        $this->actingAs(User::query()->where('email', 'keeper@wms.test')->firstOrFail());

        $supplier = Supplier::query()->firstOrFail();

        $this->patchJson('/api/suppliers/'.$supplier->id, [
            'code' => $supplier->code,
            'name' => $supplier->name,
            'name_en' => $supplier->name_en,
        ])->assertForbidden();

        $this->deleteJson('/api/suppliers/'.$supplier->id)->assertForbidden();
    }
}
