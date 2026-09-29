<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    public function test_guest_cannot_open_the_warehouse(): void
    {
        $this->getJson('/api/board')->assertUnauthorized();
        $this->postJson('/api/receipts', [])->assertUnauthorized();
    }

    public function test_login_starts_a_session_and_logout_ends_it(): void
    {
        $this->postJson('/api/login', [
            'email' => 'keeper@wms.test',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('email', 'keeper@wms.test');

        $this->getJson('/api/me')->assertOk()->assertJsonPath('name', 'راشد الكندي');

        $this->postJson('/api/logout')->assertNoContent();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->postJson('/api/login', [
            'email' => 'keeper@wms.test',
            'password' => 'not-the-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_viewer_cannot_post_a_receipt(): void
    {
        $viewer = User::factory()->create(['active' => true]);
        $viewer->roles()->sync([Role::query()->where('name', 'viewer')->value('id')]);

        $this->actingAs($viewer)
            ->postJson('/api/receipts', [])
            ->assertForbidden();
    }

    public function test_storekeeper_cannot_manage_access(): void
    {
        $keeper = User::query()->where('email', 'keeper@wms.test')->firstOrFail();

        $this->actingAs($keeper)->getJson('/api/access')->assertForbidden();
    }

    public function test_the_last_administrator_cannot_give_up_access_management(): void
    {
        $admin = User::query()->where('email', 'admin@wms.test')->firstOrFail();
        $viewer = Role::query()->where('name', 'viewer')->firstOrFail();

        $this->actingAs($admin)->patchJson('/api/access/users/'.$admin->id, [
            'name' => $admin->name,
            'email' => $admin->email,
            'active' => true,
            'role_ids' => [$viewer->id],
        ])->assertStatus(422)->assertJsonValidationErrors('role_ids');
    }
}
