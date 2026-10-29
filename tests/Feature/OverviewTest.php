<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Services\StoreProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OverviewTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        [$this->store, $this->owner] = app(StoreProvisioner::class)->provision(['name' => 'A', 'owner_name' => 'Ada', 'owner_email' => 'a@a.test', 'owner_password' => 'password123']);
        Sanctum::actingAs($this->owner);
    }

    public function test_the_overview_returns_every_section_for_an_owner(): void
    {
        $r = $this->getJson('/api/v1/overview?from='.now()->startOfMonth()->toDateString().'&to='.now()->toDateString())->assertOk();

        foreach (['sales', 'expenses', 'stock', 'accounts', 'field', 'people', 'fleet'] as $section) {
            $this->assertNotNull($r->json($section), "{$section} is missing");
        }

        $this->assertSame(0, $r->json('sales.total'));
        $this->assertSame(0, $r->json('accounts.net_profit'));
    }

    public function test_an_owner_keeps_working_when_their_role_misses_a_newer_permission(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->store->id);
        Role::where('store_id', $this->store->id)->where('name', 'Owner')->first()->revokePermissionTo('vehicles.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->getJson('/api/v1/vehicles')->assertOk();
        $this->assertContains('vehicles.view', $this->getJson('/api/v1/auth/me')->json('permissions'));
    }
}
