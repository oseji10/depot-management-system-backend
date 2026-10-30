<?php

namespace Tests\Feature;

use App\Modules\Accounting\Services\ChartDefaults;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Services\StoreProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChartRepairTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        [$this->store] = app(StoreProvisioner::class)->provision(['name' => 'A', 'owner_name' => 'Ada', 'owner_email' => 'a@a.test', 'owner_password' => 'password123']);
    }

    private function receivables()
    {
        return DB::table('accounts')->where('store_id', $this->store->id)->where('role', 'receivables');
    }

    public function test_a_switched_off_system_account_is_switched_back_on(): void
    {
        $this->receivables()->update(['is_active' => false]);

        $this->assertSame(['receivables'], app(ChartDefaults::class)->repair($this->store->id));
        $this->assertTrue((bool) $this->receivables()->value('is_active'));
        $this->assertSame([], app(ChartDefaults::class)->repair($this->store->id), 'nothing left to fix');
    }

    public function test_a_missing_role_is_given_to_the_standard_account_or_a_new_one(): void
    {
        $this->receivables()->update(['role' => null]);
        app(ChartDefaults::class)->repair($this->store->id);
        $this->assertSame('1100', $this->receivables()->value('code'));

        // The standard code is now used for something else: a fresh account goes on the next free code.
        $this->receivables()->update(['role' => 'other_thing']);
        app(ChartDefaults::class)->repair($this->store->id, 'receivables');
        $this->assertSame('1101', $this->receivables()->value('code'));
    }
}
