<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_and_location_photos_can_be_replaced_and_removed(): void
    {
        Storage::fake('public');
        $this->seed(WarehouseSeeder::class);
        $this->seed(AccessSeeder::class);
        $this->actingAs(User::query()->where('email', 'admin@wms.test')->firstOrFail());

        $item = Item::query()->where('code', '268625')->firstOrFail();
        $location = Location::query()->where('code', 'S1A1')->firstOrFail();

        $this->post('/api/items/'.$item->id.'/image', [
            'image' => UploadedFile::fake()->image('coupling.jpg', 80, 80),
        ])->assertOk();

        $item->refresh();
        Storage::disk('public')->assertExists($item->image_path);
        $first = $item->image_path;

        $this->getJson('/api/items/'.$item->id)
            ->assertOk()
            ->assertJsonPath('image_url', '/storage/'.$first);

        $this->post('/api/items/'.$item->id.'/image', [
            'image' => UploadedFile::fake()->image('coupling-2.png', 40, 40),
        ])->assertOk();

        Storage::disk('public')->assertMissing($first);
        $this->assertNotSame($first, $item->fresh()->image_path);

        $this->post('/api/items/'.$item->id.'/image', [
            'image' => UploadedFile::fake()->create('note.pdf', 20, 'application/pdf'),
        ])->assertStatus(422);

        $this->deleteJson('/api/items/'.$item->id.'/image')->assertOk();
        $this->assertNull($item->fresh()->image_path);

        $this->post('/api/locations/'.$location->id.'/image', [
            'image' => UploadedFile::fake()->image('bin.jpg', 60, 60),
        ])->assertOk();

        $location->refresh();
        $this->getJson('/api/locations')
            ->assertOk()
            ->assertJsonFragment([
                'code' => 'S1A1',
                'image_url' => '/storage/'.$location->image_path,
            ]);
    }
}
