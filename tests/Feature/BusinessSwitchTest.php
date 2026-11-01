<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Services\StoreProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        [$this->store, $this->owner] = app(StoreProvisioner::class)->provision([
            'name' => 'Alpha Depot', 'owner_name' => 'Ada', 'owner_email' => 'ada@a.test', 'owner_password' => 'password123',
        ]);
        Sanctum::actingAs($this->owner);
    }

    private function me(): User
    {
        $this->app['auth']->forgetGuards();

        return User::withoutGlobalScopes()->where('email', 'ada@a.test')->firstOrFail();
    }

    private function allowThree(): void
    {
        $plan = DB::table('plans')->insertGetId(['name' => 'Pro', 'price' => 1_000_000, 'months' => 1, 'max_businesses' => 3, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('subscriptions')->where('store_id', $this->store->id)->update(['plan_id' => $plan]);
    }

    public function test_the_plan_decides_how_many_businesses_an_owner_can_create(): void
    {
        $this->getJson('/api/v1/businesses')->assertOk()->assertJsonPath('allowed', 1)->assertJsonPath('owned', 1)->assertJsonPath('can_create', false);
        $this->postJson('/api/v1/businesses', ['name' => 'Beta Depot'])->assertStatus(422);

        $this->allowThree();
        $this->postJson('/api/v1/businesses', ['name' => 'Beta Depot'])->assertOk();
        $this->postJson('/api/v1/businesses', ['name' => 'Gamma Depot'])->assertOk();
        $this->postJson('/api/v1/businesses', ['name' => 'Delta Depot'])->assertStatus(422);

        $this->assertSame(3, DB::table('user_stores')->where('user_id', $this->owner->id)->where('is_owner', true)->count());
        $this->assertSame(3, DB::table('subscriptions')->count());   // each business has its own trial
    }

    public function test_creating_switches_to_the_new_business_which_is_separate_and_switching_back_works(): void
    {
        $this->allowThree();
        $this->postJson('/api/v1/customers', ['name' => 'Alpha Customer', 'phone' => '08030000001'])->assertCreated();

        $this->postJson('/api/v1/businesses', ['name' => 'Beta Depot'])->assertOk();
        $beta = $this->me()->store_id;
        $this->assertNotSame($this->store->id, $beta);

        Sanctum::actingAs($this->me());
        $this->getJson('/api/v1/customers')->assertOk()->assertJsonCount(0, 'data');   // Alpha's customers are not visible here
        $this->getJson('/api/v1/employees')->assertOk();                                  // and the Owner role works in the new business
        $this->getJson('/api/v1/businesses')->assertJsonCount(2, 'data')->assertJsonPath('current', $beta);

        $this->postJson("/api/v1/businesses/{$this->store->id}/switch")->assertOk();
        Sanctum::actingAs($this->me());
        $this->getJson('/api/v1/customers')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_you_cannot_switch_into_a_business_you_do_not_belong_to(): void
    {
        [$other] = app(StoreProvisioner::class)->provision(['name' => 'Other Co', 'owner_name' => 'Ola', 'owner_email' => 'ola@o.test', 'owner_password' => 'password123']);
        Sanctum::actingAs($this->me());

        $this->postJson("/api/v1/businesses/{$other->id}/switch")->assertForbidden();
        $this->assertSame($this->store->id, $this->me()->store_id);
    }

    public function test_a_business_wide_user_can_narrow_the_app_to_one_branch_of_their_own_business_only(): void
    {
        $branch = Branch::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'name' => 'Karu', 'code' => 'KR']);
        [$other] = app(StoreProvisioner::class)->provision(['name' => 'Other Co', 'owner_name' => 'Ola', 'owner_email' => 'ola@o.test', 'owner_password' => 'password123']);
        $foreign = Branch::withoutGlobalScopes()->where('store_id', $other->id)->value('id');
        Sanctum::actingAs($this->me());

        $this->assertGreaterThanOrEqual(2, count($this->getJson('/api/v1/branches')->json('data')));
        $this->getJson('/api/v1/branches', ['X-Branch-Id' => (string) $branch->id])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Karu');
        $this->assertGreaterThanOrEqual(2, count($this->getJson('/api/v1/branches', ['X-Branch-Id' => (string) $foreign])->json('data')));   // another business's branch is ignored

        $opts = $this->getJson('/api/v1/workspace/options', ['X-Branch-Id' => (string) $branch->id])->assertOk();
        $this->assertGreaterThanOrEqual(2, count($opts->json('branches')));   // the picker still lists them all
    }
}
