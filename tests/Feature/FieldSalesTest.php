<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\FiscalPeriods;
use App\Modules\Catalogue\Models\PriceList;
use App\Modules\Catalogue\Models\Product;
use App\Modules\Catalogue\Models\ProductCategory;
use App\Modules\Catalogue\Models\ProductUnit;
use App\Modules\Catalogue\Models\Unit;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Organization\Models\Branch;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Warehouse;
use App\Modules\Organization\Services\StoreProvisioner;
use App\Modules\Procurement\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Salespeople and their vans. Money in these tests is kobo. One crate costs 6,000 naira (600,000 kobo) and sells for 6,200 naira (620,000 kobo);
 * a crate holds 12 bottles, a bottle sells for 600 naira (60,000 kobo).
 */
class FieldSalesTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $owner;
    private Warehouse $w1;
    private Product $coke;
    private Supplier $cocaCola;
    private int $crate;
    private int $bottle;
    private int $refSeq = 5000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        [$this->store, $this->owner] = app(StoreProvisioner::class)->provision([
            'name' => 'Alpha Depot', 'owner_name' => 'Ada', 'owner_email' => 'ada@a.test', 'owner_password' => 'password123',
        ]);

        $this->w1 = Warehouse::withoutGlobalScopes()->where('store_id', $this->store->id)->firstOrFail();
        $unit = fn ($abbr) => Unit::withoutGlobalScopes()->where('store_id', $this->store->id)->where('abbreviation', $abbr)->firstOrFail()->id;
        $this->bottle = $unit('BTL');
        $this->crate = $unit('CRT');

        $this->coke = Product::withoutGlobalScopes()->create([
            'store_id' => $this->store->id, 'sku' => 'COKE50', 'name' => 'Coke 50cl', 'packaging' => 'rgb',
            'category_id' => ProductCategory::withoutGlobalScopes()->where('store_id', $this->store->id)->value('id'), 'base_unit_id' => $this->bottle,
        ]);

        $prices = [];
        foreach ([[$this->bottle, 1, true, 600], [$this->crate, 12, false, 6200]] as [$u, $f, $base, $price]) {
            $pu = ProductUnit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'product_id' => $this->coke->id, 'unit_id' => $u, 'factor' => $f, 'is_base' => $base]);
            $prices[] = ['product_unit_id' => $pu->id, 'price' => $price];
        }

        Sanctum::actingAs($this->owner);
        $standard = PriceList::withoutGlobalScopes()->where('store_id', $this->store->id)->where('is_default', true)->value('id');
        $this->putJson("/api/v1/price-lists/{$standard}/prices", ['prices' => $prices])->assertOk();

        $this->cocaCola = Supplier::create(['store_id' => $this->store->id, 'name' => 'Coca-Cola Bottling', 'code' => 'CCB', 'is_manufacturer' => true]);
        $this->receive(10); // 120 bottles, 6,000,000 kobo of inventory
    }

    // ---- helpers ----

    private function receive(int $crates): void
    {
        $id = $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $this->cocaCola->id, 'warehouse_id' => $this->w1->id, 'received_on' => now()->toDateString(),
            'supplier_reference' => 'WB-'.$this->refSeq++,
            'lines' => [['product_id' => $this->coke->id, 'unit_id' => $this->crate, 'delivered' => $crates, 'unit_cost' => 6000]],
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/goods-receipts/{$id}/post")->assertOk();
    }

    private function net(string $role): int
    {
        $id = Account::withoutGlobalScopes()->where('store_id', $this->store->id)->where('role', $role)->value('id');

        return (int) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $id)->where('e.status', 'posted')
            ->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) AS n')->value('n');
    }

    private function onHand(int $warehouseId, string $status = 'saleable'): int
    {
        return app(StockService::class)->balance($warehouseId, $this->coke->id, $status);
    }

    /** Creates Musa, a salesperson, and returns [user, van]. */
    private function rep(string $email = 'musa@a.test'): array
    {
        Sanctum::actingAs($this->owner);
        $id = $this->postJson('/api/v1/reps', [
            'name' => 'Musa', 'email' => $email, 'password' => 'password123', 'phone' => '0803', 'branch_id' => $this->w1->branch_id,
        ])->assertCreated()->json('id');

        return [User::findOrFail($id), Warehouse::vanOf($id)];
    }

    private function issue(User $rep, int $crates = 4): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->owner);

        return $this->postJson('/api/v1/trips/issue', [
            'rep_id' => $rep->id, 'from_warehouse_id' => $this->w1->id,
            'lines' => [['product_id' => $this->coke->id, 'unit_id' => $this->crate, 'quantity' => $crates]],
        ]);
    }

    private function sellFromVan(User $rep, Warehouse $van, int $bottles = 12, array $payments = [['method' => 'cash', 'amount' => 720_000]], array $extra = []): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($rep);

        return $this->postJson('/api/v1/sales', array_merge([
            'warehouse_id' => $van->id,
            'lines' => [['product_id' => $this->coke->id, 'unit_id' => $this->bottle, 'quantity' => $bottles]],
            'payments' => $payments,
        ], $extra));
    }

    // ---- accounts ----

    public function test_creating_a_salesperson_makes_a_login_and_a_van(): void
    {
        [$rep, $van] = $this->rep();

        $this->assertTrue($rep->hasRole('Field Sales'));
        $this->assertSame('van', $van->type);
        $this->assertSame($rep->id, $van->assigned_user_id);
        $this->assertSame($this->w1->branch_id, $rep->branch_id);

        // vans stay out of the ordinary warehouse list, and out of the "warehouses" count
        $names = collect($this->getJson('/api/v1/warehouses')->json('data'))->pluck('name');
        $this->assertFalse($names->contains($van->name));

        $this->getJson('/api/v1/reps')->assertOk()->assertJsonPath('0.name', 'Musa')->assertJsonPath('0.open_trip', null);
        $this->postJson('/api/v1/auth/login', ['email' => 'musa@a.test', 'password' => 'password123'])->assertOk()->assertJsonPath('van.id', $van->id);
    }

    public function test_a_cashier_cannot_create_salespeople_or_issue_stock(): void
    {
        [$rep] = $this->rep();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->store->id);
        $cashier = User::create(['store_id' => $this->store->id, 'branch_id' => $this->w1->branch_id, 'name' => 'C', 'email' => 'c@a.test', 'password' => 'password123']);
        $cashier->assignRole(Role::where('store_id', $this->store->id)->where('name', 'Sales Cashier')->firstOrFail());

        Sanctum::actingAs($cashier);
        $this->postJson('/api/v1/reps', ['name' => 'X', 'email' => 'x@a.test', 'password' => 'password123', 'branch_id' => $this->w1->branch_id])->assertForbidden();
        $this->postJson('/api/v1/trips/issue', ['rep_id' => $rep->id, 'from_warehouse_id' => $this->w1->id, 'lines' => [['product_id' => $this->coke->id, 'unit_id' => $this->crate, 'quantity' => 1]]])->assertForbidden();
    }

    // ---- issuing ----

    public function test_issuing_moves_stock_from_the_warehouse_to_the_van_and_opens_a_trip(): void
    {
        [$rep, $van] = $this->rep();

        $trip = $this->issue($rep, 4)->assertCreated()
            ->assertJsonPath('trip.status', 'open')->assertJsonPath('totals.issued', 48)->assertJsonPath('totals.with_rep', 48)->json();

        $this->assertSame('TRP-000001', $trip['trip']['number']);
        $this->assertSame(72, $this->onHand($this->w1->id));
        $this->assertSame(48, $this->onHand($van->id));
        $this->assertSame(6_000_000, $this->net('inventory'));   // moving stock between places in one branch changes no money
        $this->getJson('/api/v1/reports/inventory-reconciliation')->assertJsonPath('reconciled', true);

        // a second issue tops up the same trip
        $this->issue($rep, 1)->assertCreated()->assertJsonPath('trip.id', $trip['trip']['id'])->assertJsonPath('totals.issued', 60);
        $this->assertSame(1, DB::table('trips')->count());
    }

    public function test_cannot_issue_more_than_the_warehouse_holds_or_from_a_van(): void
    {
        [$rep, $van] = $this->rep();

        $this->issue($rep, 11)->assertStatus(422);
        $this->assertSame(120, $this->onHand($this->w1->id));
        $this->assertSame(0, DB::table('trips')->count());   // the failed issue left nothing behind

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/trips/issue', ['rep_id' => $rep->id, 'from_warehouse_id' => $van->id, 'lines' => [['product_id' => $this->coke->id, 'unit_id' => $this->crate, 'quantity' => 1]]])->assertStatus(422);
    }

    // ---- selling from the van ----

    public function test_a_salesperson_sells_from_the_van_and_the_sale_belongs_to_the_trip(): void
    {
        [$rep, $van] = $this->rep();
        $this->issue($rep, 4);

        $sale = $this->sellFromVan($rep, $van, 12)->assertCreated()->assertJsonPath('total', 720_000)->json();

        $this->assertSame(36, $this->onHand($van->id));
        $this->assertSame(72, $this->onHand($this->w1->id));
        $this->assertSame(1, DB::table('sales')->where('id', $sale['id'])->whereNotNull('trip_id')->count());
        $this->assertSame(720_000, $this->net('cash'));
        $this->assertSame(-720_000, $this->net('sales'));

        // the manager watches it happen
        Sanctum::actingAs($this->owner);
        $tripId = DB::table('trips')->value('id');
        $this->getJson("/api/v1/trips/{$tripId}")->assertOk()
            ->assertJsonPath('totals.sold', 12)->assertJsonPath('totals.with_rep', 36)
            ->assertJsonPath('money.orders', 1)->assertJsonPath('money.sales', 720_000)->assertJsonPath('money.cash_expected', 720_000)
            ->assertJsonPath('sales.0.number', $sale['number']);
    }

    public function test_selling_is_blocked_without_a_load_or_from_the_wrong_place(): void
    {
        [$rep, $van] = $this->rep();

        $this->sellFromVan($rep, $van)->assertStatus(422);               // nothing issued yet

        $this->issue($rep, 1);
        [$other, $otherVan] = $this->rep('amina@a.test');
        $this->issue($other, 1);

        $this->sellFromVan($rep, $otherVan)->assertForbidden();           // someone else's van

        Sanctum::actingAs($rep);
        $this->postJson('/api/v1/sales', [                                // the main warehouse
            'warehouse_id' => $this->w1->id, 'lines' => [['product_id' => $this->coke->id, 'unit_id' => $this->bottle, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 60_000]],
        ])->assertForbidden();
        $this->getJson("/api/v1/pos/catalogue?warehouse_id={$this->w1->id}")->assertForbidden();
        $this->getJson("/api/v1/pos/catalogue?warehouse_id={$van->id}")->assertOk()->assertJsonPath('products.0.stock', 12);

        // a manager cannot sell out of a salesperson's van either
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/sales', [
            'warehouse_id' => $van->id, 'lines' => [['product_id' => $this->coke->id, 'unit_id' => $this->bottle, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 60_000]],
        ])->assertForbidden();
    }

    public function test_a_salesperson_cannot_sell_more_than_is_on_the_van(): void
    {
        [$rep, $van] = $this->rep();
        $this->issue($rep, 1);

        $this->sellFromVan($rep, $van, 13, [['method' => 'cash', 'amount' => 780_000]])->assertStatus(422);
        $this->assertSame(12, $this->onHand($van->id));
        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_a_salesperson_sees_only_their_own_sales(): void
    {
        [$rep, $van] = $this->rep();
        [$other, $otherVan] = $this->rep('amina@a.test');
        $this->issue($rep, 2);
        $this->issue($other, 2);

        $mine = $this->sellFromVan($rep, $van, 6, [['method' => 'cash', 'amount' => 360_000]])->assertCreated()->json('id');
        $theirs = $this->sellFromVan($other, $otherVan, 6, [['method' => 'cash', 'amount' => 360_000]])->assertCreated()->json('id');

        Sanctum::actingAs($rep);
        $this->assertSame([$mine], collect($this->getJson('/api/v1/sales')->json('data'))->pluck('id')->all());
        $this->getJson("/api/v1/sales/{$theirs}")->assertNotFound();
        $this->postJson("/api/v1/sales/{$theirs}/payments", ['method' => 'cash', 'amount' => 1])->assertNotFound();
        $this->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonPath('sales', null);
        $this->getJson('/api/v1/field/me')->assertOk()->assertJsonPath('report.totals.sold', 6)->assertJsonPath('van.id', $van->id);
    }

    // ---- checking back in ----

    public function test_check_in_returns_the_leftovers_and_clears_the_van(): void
    {
        [$rep, $van] = $this->rep();
        $this->issue($rep, 4);                                   // 48 bottles out
        $this->sellFromVan($rep, $van, 12);                      // 12 sold, 600,000 cash taken
        $tripId = DB::table('trips')->value('id');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/trips/{$tripId}/check-in", [
            'to_warehouse_id' => $this->w1->id, 'close' => true, 'cash_handed_in' => 720_000,
            'returns' => [['product_id' => $this->coke->id, 'unit_id' => $this->bottle, 'quantity' => 36]],
        ])->assertOk()
            ->assertJsonPath('trip.status', 'closed')->assertJsonPath('totals.returned', 36)->assertJsonPath('totals.shortage', 0)
            ->assertJsonPath('money.cash_expected', 720_000)->assertJsonPath('money.cash_variance', 0);

        $this->assertSame(0, $this->onHand($van->id));
        $this->assertSame(108, $this->onHand($this->w1->id));    // 120 - 12 sold
        $this->assertSame(5_400_000, $this->net('inventory'));
        $this->getJson('/api/v1/reports/inventory-reconciliation')->assertJsonPath('reconciled', true);

        // the van is free for the next trip
        $this->issue($rep, 1)->assertCreated();
        $this->assertSame(2, DB::table('trips')->count());
    }

    public function test_stock_that_does_not_come_back_is_written_off_as_a_shortage_and_cash_variance_is_recorded(): void
    {
        [$rep, $van] = $this->rep();
        $this->issue($rep, 4);
        $this->sellFromVan($rep, $van, 12);
        $tripId = DB::table('trips')->value('id');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/trips/{$tripId}/check-in", [
            'to_warehouse_id' => $this->w1->id, 'close' => true, 'cash_handed_in' => 670_000,
            'returns' => [['product_id' => $this->coke->id, 'unit_id' => $this->bottle, 'quantity' => 30]],   // 6 never came back
        ])->assertOk()
            ->assertJsonPath('totals.shortage', 6)->assertJsonPath('money.cash_expected', 720_000)
            ->assertJsonPath('money.cash_handed_in', 670_000)->assertJsonPath('money.cash_variance', -50_000);

        $this->assertSame(0, $this->onHand($van->id));
        $this->assertSame(102, $this->onHand($this->w1->id));
        // 6 bottles at 50,000 kobo each leave inventory and land in the adjustment (loss) account
        $this->assertSame(5_100_000, $this->net('inventory'));
        $this->assertSame(300_000, $this->net('inventory_adjustment'));
        $this->getJson('/api/v1/reports/inventory-reconciliation')->assertJsonPath('reconciled', true);
    }

    public function test_a_trip_shortage_raises_a_pending_staff_charge_for_the_rep_at_selling_price(): void
    {
        [$rep, $van] = $this->rep();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/employees/from-user', ['user_id' => $rep->id, 'basic_salary' => 10_000_000])->assertCreated();

        $this->issue($rep, 1);                                   // 12 bottles
        $tripId = DB::table('trips')->value('id');
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/trips/{$tripId}/check-in", [
            'to_warehouse_id' => $this->w1->id, 'close' => true, 'cash_handed_in' => 0,
            'returns' => [['product_id' => $this->coke->id, 'unit_id' => $this->bottle, 'quantity' => 9]],   // 3 missing
        ])->assertOk()->assertJsonPath('totals.shortage', 3);

        $charge = DB::table('staff_charges')->first();
        $this->assertNotNull($charge);
        $this->assertSame('shortage', $charge->reason);
        $this->assertSame('pending', $charge->status);
        $this->assertSame(3, (int) $charge->quantity);
        $this->assertSame(3 * 60_000, (int) $charge->amount);    // 600 naira a bottle
    }

    public function test_a_shortage_for_a_rep_who_is_not_on_the_payroll_just_closes_the_trip(): void
    {
        [$rep] = $this->rep();
        $this->issue($rep, 1);
        $tripId = DB::table('trips')->value('id');
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/trips/{$tripId}/check-in", [
            'to_warehouse_id' => $this->w1->id, 'close' => true, 'cash_handed_in' => 0, 'returns' => [],
        ])->assertOk();

        $this->assertSame(0, DB::table('staff_charges')->count());
    }

    public function test_damaged_returns_go_to_damaged_stock_and_are_written_off(): void
    {
        [$rep, $van] = $this->rep();
        $this->issue($rep, 1);                                   // 12 bottles
        $tripId = DB::table('trips')->value('id');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/trips/{$tripId}/check-in", [
            'to_warehouse_id' => $this->w1->id, 'close' => true, 'cash_handed_in' => 0,
            'returns' => [
                ['product_id' => $this->coke->id, 'unit_id' => $this->bottle, 'quantity' => 10],
                ['product_id' => $this->coke->id, 'unit_id' => $this->bottle, 'quantity' => 2, 'status' => 'damaged'],
            ],
        ])->assertOk()->assertJsonPath('totals.returned', 10)->assertJsonPath('totals.damaged', 2)->assertJsonPath('totals.shortage', 0);

        $this->assertSame(118, $this->onHand($this->w1->id));
        $this->assertSame(2, $this->onHand($this->w1->id, 'damaged'));
        $this->assertSame(100_000, $this->net('inventory_adjustment'));
        $this->getJson('/api/v1/reports/inventory-reconciliation')->assertJsonPath('reconciled', true);
    }

    public function test_a_part_return_keeps_the_trip_open_and_cannot_return_more_than_the_van_holds(): void
    {
        [$rep, $van] = $this->rep();
        $this->issue($rep, 1);
        $tripId = DB::table('trips')->value('id');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/trips/{$tripId}/check-in", [
            'to_warehouse_id' => $this->w1->id, 'returns' => [['product_id' => $this->coke->id, 'unit_id' => $this->bottle, 'quantity' => 13]],
        ])->assertStatus(422);

        $this->postJson("/api/v1/trips/{$tripId}/check-in", [
            'to_warehouse_id' => $this->w1->id, 'returns' => [['product_id' => $this->coke->id, 'unit_id' => $this->bottle, 'quantity' => 5]],
        ])->assertOk()->assertJsonPath('trip.status', 'open')->assertJsonPath('totals.with_rep', 7);

        $this->assertSame(7, $this->onHand($van->id));
    }

    public function test_closing_needs_the_cash_figure_and_a_closed_trip_cannot_be_reused(): void
    {
        [$rep, $van] = $this->rep();
        $this->issue($rep, 1);
        $tripId = DB::table('trips')->value('id');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/trips/{$tripId}/check-in", ['close' => true])->assertStatus(422);

        $this->postJson("/api/v1/trips/{$tripId}/check-in", ['close' => true, 'cash_handed_in' => 0, 'to_warehouse_id' => $this->w1->id,
            'returns' => [['product_id' => $this->coke->id, 'unit_id' => $this->bottle, 'quantity' => 12]]])->assertOk();
        $this->postJson("/api/v1/trips/{$tripId}/check-in", ['close' => true, 'cash_handed_in' => 0])->assertStatus(422);

        $this->sellFromVan($rep, $van, 1, [['method' => 'cash', 'amount' => 60_000]])->assertStatus(422);   // no open trip any more
    }

    public function test_a_trip_sale_cannot_be_voided_after_check_in_and_cash_taken_after_the_trip_is_not_counted(): void
    {
        [$rep, $van] = $this->rep();
        $this->issue($rep, 1);
        $saleId = $this->sellFromVan($rep, $van, 2, [['method' => 'cash', 'amount' => 120_000]])->assertCreated()->json('id');
        $tripId = DB::table('trips')->value('id');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/trips/{$tripId}/check-in", ['close' => true, 'cash_handed_in' => 120_000, 'to_warehouse_id' => $this->w1->id,
            'returns' => [['product_id' => $this->coke->id, 'unit_id' => $this->bottle, 'quantity' => 10]]])->assertOk()->assertJsonPath('money.cash_variance', 0);

        $this->postJson("/api/v1/sales/{$saleId}/void", ['reason' => 'mistake'])->assertStatus(422);
    }

    public function test_a_salesperson_with_stock_out_cannot_be_disabled(): void
    {
        [$rep] = $this->rep();
        $this->issue($rep, 1);

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/reps/{$rep->id}", ['is_active' => false])->assertStatus(422);
    }

    public function test_credit_sales_from_the_van_are_not_counted_as_cash_to_hand_in(): void
    {
        [$rep, $van] = $this->rep();
        $this->issue($rep, 2);

        Sanctum::actingAs($this->owner);
        $customer = $this->postJson('/api/v1/customers', ['name' => 'Mama Ngozi', 'phone' => '08031230000', 'address' => 'Market Rd', 'credit_limit' => 1_000_000])->assertCreated()->json('id');

        $this->sellFromVan($rep, $van, 12, [['method' => 'cash', 'amount' => 200_000]], ['customer_id' => $customer])->assertCreated()->assertJsonPath('balance_due', 520_000);
        $this->sellFromVan($rep, $van, 2, [['method' => 'transfer', 'amount' => 120_000]])->assertCreated();

        Sanctum::actingAs($this->owner);
        $tripId = DB::table('trips')->value('id');
        $this->getJson("/api/v1/trips/{$tripId}")
            ->assertJsonPath('money.sales', 840_000)->assertJsonPath('money.cash', 200_000)->assertJsonPath('money.transfer', 120_000)
            ->assertJsonPath('money.on_credit', 520_000)->assertJsonPath('money.cash_expected', 200_000);
    }

    // ---- terminals on the van, transfers, credit ----

    private function terminal(string $name, array $extra = []): int
    {
        Sanctum::actingAs($this->owner);

        return $this->postJson('/api/v1/pos-terminals', ['name' => $name, 'provider' => 'Moniepoint', ...$extra])->assertCreated()->json('id');
    }

    private function bankAccount(string $code, string $name): int
    {
        return \App\Modules\Accounting\Models\Account::withoutGlobalScopes()->create([
            'store_id' => $this->store->id, 'code' => $code, 'name' => $name, 'type' => 'asset', 'is_system' => false, 'is_active' => true,
        ])->id;
    }

    private function netOf(int $accountId): int
    {
        return (int) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $accountId)->where('e.status', 'posted')->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) AS n')->value('n');
    }

    public function test_a_terminal_handed_over_with_the_load_is_the_one_the_rep_uses(): void
    {
        [$rep, $van] = $this->rep();
        $van_pos = $this->terminal('Van POS');
        $counter = $this->terminal('Counter');

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/trips/issue', [
            'rep_id' => $rep->id, 'from_warehouse_id' => $this->w1->id, 'pos_terminal_id' => $van_pos,
            'lines' => [['product_id' => $this->coke->id, 'unit_id' => $this->crate, 'quantity' => 4]],
        ])->assertCreated()->assertJsonPath('terminal.name', 'Van POS');

        $this->assertSame($rep->id, \App\Modules\Sales\Models\PosTerminal::withoutGlobalScopes()->find($van_pos)->assigned_user_id);

        // The rep names no machine: the one they carry is used. Naming another is refused.
        $sale = $this->sellFromVan($rep, $van, 12, [['method' => 'pos', 'amount' => 720_000]])->assertCreated();
        $this->assertSame($van_pos, $sale->json('payments.0.pos_terminal_id'));
        $this->sellFromVan($rep, $van, 12, [['method' => 'pos', 'amount' => 720_000, 'pos_terminal_id' => $counter]])->assertUnprocessable();

        // The phone is told about only that machine.
        Sanctum::actingAs($rep);
        $this->getJson('/api/v1/field/bootstrap')->assertOk()->assertJsonCount(1, 'catalogue.terminals')->assertJsonPath('catalogue.terminals.0.name', 'Van POS');
    }

    public function test_a_machine_with_someone_else_cannot_be_handed_over(): void
    {
        [$musa] = $this->rep();
        [$ade, $adeVan] = $this->rep('ade@a.test');
        $t = $this->terminal('Van POS');

        Sanctum::actingAs($this->owner);
        $load = fn (User $r, int $term) => $this->postJson('/api/v1/trips/issue', [
            'rep_id' => $r->id, 'from_warehouse_id' => $this->w1->id, 'pos_terminal_id' => $term,
            'lines' => [['product_id' => $this->coke->id, 'unit_id' => $this->crate, 'quantity' => 1]],
        ]);

        $load($musa, $t)->assertCreated();
        $load($ade, $t)->assertUnprocessable()->assertJsonValidationErrors(['pos_terminal_id']);
    }

    public function test_a_transfer_names_where_the_money_went(): void
    {
        [$rep, $van] = $this->rep();
        $this->issue($rep, 4);
        $gtb = $this->bankAccount('1030', 'GTB Savings');
        $moniepoint = $this->bankAccount('1040', 'Moniepoint Settlement');
        $vanPos = $this->terminal('Van POS', ['account_id' => $moniepoint]);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/trips/issue', [
            'rep_id' => $rep->id, 'from_warehouse_id' => $this->w1->id, 'pos_terminal_id' => $vanPos,
            'lines' => [['product_id' => $this->coke->id, 'unit_id' => $this->crate, 'quantity' => 1]],
        ])->assertCreated();

        // Into another account from the list
        $r = $this->sellFromVan($rep, $van, 12, [['method' => 'transfer', 'amount' => 720_000, 'transfer_to' => 'account', 'account_id' => $gtb]])->assertCreated();
        $this->assertSame('account', $r->json('payments.0.transfer_to'));
        $this->assertSame(720_000, $this->netOf($gtb));

        // Into the POS machine's own account
        $this->sellFromVan($rep, $van, 12, [['method' => 'transfer', 'amount' => 720_000, 'transfer_to' => 'pos']])->assertCreated();
        $this->assertSame(720_000, $this->netOf($moniepoint));

        // "Another account" must be named, and must be one of the listed accounts (not, say, receivables)
        $this->sellFromVan($rep, $van, 12, [['method' => 'transfer', 'amount' => 720_000, 'transfer_to' => 'account']])->assertUnprocessable();
        $receivables = \App\Modules\Accounting\Models\Account::withoutGlobalScopes()->where('store_id', $this->store->id)->where('role', 'receivables')->value('id');
        $this->sellFromVan($rep, $van, 12, [['method' => 'transfer', 'amount' => 720_000, 'transfer_to' => 'account', 'account_id' => $receivables]])->assertUnprocessable();

        // The phone gets the list to choose from
        Sanctum::actingAs($rep);
        $names = collect($this->getJson('/api/v1/field/bootstrap')->assertOk()->json('catalogue.transfer_accounts'))->pluck('name');
        $this->assertTrue($names->contains('GTB Savings'));
        $this->assertFalse($names->contains('Trade Receivables'));
    }

    public function test_a_credit_sale_is_settled_later_by_the_rep_or_the_office(): void
    {
        [$rep, $van] = $this->rep();
        $this->issue($rep, 4);
        $gtb = $this->bankAccount('1030', 'GTB Savings');

        Sanctum::actingAs($this->owner);
        $customer = $this->postJson('/api/v1/customers', ['name' => 'Mama Ngozi', 'phone' => '08031239999', 'address' => 'Market Rd', 'credit_limit' => 5_000_000])->assertCreated()->json('id');

        // Nothing paid now: it goes on the customer's account
        $sale = $this->sellFromVan($rep, $van, 12, [], ['customer_id' => $customer])->assertCreated()->assertJsonPath('payment_status', 'unpaid')->assertJsonPath('balance_due', 720_000)->json('id');

        // The rep confirms part of the money, then the office confirms the rest
        Sanctum::actingAs($rep);
        $this->postJson("/api/v1/sales/{$sale}/payments", ['method' => 'cash', 'amount' => 200_000])->assertOk()->assertJsonPath('payment_status', 'partial');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/sales/{$sale}/payments", ['method' => 'transfer', 'amount' => 520_000, 'transfer_to' => 'account', 'account_id' => $gtb])
            ->assertOk()->assertJsonPath('payment_status', 'paid')->assertJsonPath('balance_due', 0)->assertJsonPath('payments.1.account.name', 'GTB Savings');
        $this->assertSame(520_000, $this->netOf($gtb));
    }

    public function test_a_customer_needs_a_phone_and_an_address_and_can_join_groups(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/customers', ['name' => 'No Phone', 'address' => 'Somewhere'])->assertUnprocessable()->assertJsonValidationErrors(['phone']);
        $this->postJson('/api/v1/customers', ['name' => 'No Address', 'phone' => '08030001111'])->assertUnprocessable()->assertJsonValidationErrors(['address']);

        $group = $this->postJson('/api/v1/customer-groups', ['name' => 'Ikeja route', 'kind' => 'route'])->assertCreated()->json('id');
        $id = $this->postJson('/api/v1/customers', ['name' => 'Full', 'phone' => '08030001112', 'address' => '4 Allen Ave', 'group_ids' => [$group]])->assertCreated()->json('id');

        $this->assertDatabaseHas('customer_group_members', ['customer_group_id' => $group, 'customer_id' => $id]);
    }

    public function test_a_salesperson_sees_their_routes_customers_and_can_load_the_others(): void
    {
        Sanctum::actingAs($this->owner);
        $ikeja = $this->postJson('/api/v1/customer-groups', ['name' => 'Ikeja route', 'kind' => 'route'])->assertCreated()->json('id');
        $lekki = $this->postJson('/api/v1/customer-groups', ['name' => 'Lekki route', 'kind' => 'route'])->assertCreated()->json('id');
        $a = $this->postJson('/api/v1/customers', ['name' => 'Ikeja Shop', 'phone' => '08030000001', 'address' => '1 Allen', 'group_ids' => [$ikeja]])->assertCreated()->json('id');
        $b = $this->postJson('/api/v1/customers', ['name' => 'Lekki Shop', 'phone' => '08030000002', 'address' => '2 Admiralty', 'group_ids' => [$lekki]])->assertCreated()->json('id');

        [$rep] = $this->rep();
        $this->putJson("/api/v1/reps/{$rep->id}", ['route_ids' => [$ikeja]])->assertOk();
        $this->issue($rep)->assertCreated();

        Sanctum::actingAs($rep);
        $boot = $this->getJson('/api/v1/field/bootstrap')->assertOk();
        $this->assertSame([$a], collect($boot->json('customers'))->pluck('id')->all());
        $this->assertSame(['Ikeja route'], collect($boot->json('routes'))->pluck('name')->all());
        $this->assertSame(['Lekki route'], collect($boot->json('other_routes'))->pluck('name')->all());

        // Loading another route brings its customers; "all" brings everyone.
        $this->assertSame([$b], collect($this->getJson("/api/v1/field/customers?route_id={$lekki}")->assertOk()->json('customers'))->pluck('id')->all());
        $this->assertCount(2, $this->getJson('/api/v1/field/customers?route_id=all')->assertOk()->json('customers'));

        // A customer the rep adds joins the rep's own route.
        $this->postJson('/api/v1/field/sync', ['items' => [['type' => 'customer', 'client_ref' => 'c-new-1', 'data' => ['name' => 'New Kiosk', 'phone' => '08030000003', 'address' => '3 Obafemi']]]])->assertOk();
        $new = \App\Modules\Sales\Models\Customer::withoutGlobalScopes()->where('client_ref', 'c-new-1')->value('id');
        $this->assertDatabaseHas('customer_group_members', ['customer_group_id' => $ikeja, 'customer_id' => $new]);
    }

    public function test_targets_pay_full_partial_or_no_reward_and_land_on_the_payslip(): void
    {
        [$rep, $van] = $this->rep();
        $this->issue($rep, 4)->assertCreated();
        $period = now()->format('Y-m');

        Sanctum::actingAs($this->owner);
        // 30 bottles for a N10,000 reward.
        $this->postJson('/api/v1/targets', ['period' => $period, 'user_ids' => [$rep->id], 'product_id' => $this->coke->id, 'unit_id' => $this->bottle, 'quantity' => 30, 'reward_type' => 'flat', 'reward_amount' => 1_000_000])->assertCreated();
        // Someone who is not a salesperson cannot be given a target.
        $this->postJson('/api/v1/targets', ['period' => $period, 'user_ids' => [$this->owner->id], 'product_id' => $this->coke->id, 'unit_id' => $this->bottle, 'quantity' => 30, 'reward_type' => 'flat', 'reward_amount' => 1])->assertUnprocessable();

        // 24 of 30 = 80%: the default rules pay half.
        $this->sellFromVan($rep, $van, 24, [['method' => 'cash', 'amount' => 1_440_000]])->assertCreated();
        Sanctum::actingAs($this->owner);
        $this->getJson("/api/v1/targets?period={$period}")->assertOk()
            ->assertJsonPath('reps.0.targets.0.percent', 80)->assertJsonPath('reps.0.targets.0.payout_percent', 50)->assertJsonPath('reps.0.reward_total', 500_000);

        // The reward goes on the payslip as its own line, outside basic pay.
        $this->postJson('/api/v1/employees/from-user', ['user_id' => $rep->id, 'basic_salary' => 10_000_000])->assertCreated();
        $run = $this->postJson('/api/v1/payroll-runs', ['period' => $period])->assertCreated()->assertJsonPath('total_gross', 10_500_000)->json('id');
        $slip = $this->getJson("/api/v1/payroll-runs/{$run}")->assertOk()->json('payslips.0');
        $this->assertSame(10_000_000, $slip['basic']);
        $this->assertTrue(collect($slip['lines'])->contains(fn ($l) => $l['source'] === 'target' && $l['amount'] === 500_000));

        // Selling the rest takes them to 100%: the whole reward.
        $this->sellFromVan($rep, $van, 6, [['method' => 'cash', 'amount' => 360_000]])->assertCreated();
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/payroll-runs/{$run}/recalculate")->assertOk()->assertJsonPath('total_gross', 11_000_000);

        // The reward can instead be a percentage of the person's salary or of a fixed amount, and a target can cover every product.
        $this->postJson('/api/v1/targets', ['period' => $period, 'user_ids' => [$rep->id], 'product_id' => $this->coke->id, 'unit_id' => $this->bottle, 'quantity' => 30, 'reward_type' => 'salary_percent', 'reward_percent' => 5])->assertCreated();
        $this->getJson("/api/v1/targets?period={$period}")->assertJsonPath('reps.0.targets.0.reward_full', 500_000);   // 5% of N100,000
        $this->postJson('/api/v1/targets', ['period' => $period, 'user_ids' => [$rep->id], 'product_id' => $this->coke->id, 'unit_id' => $this->bottle, 'quantity' => 30, 'reward_type' => 'fixed_percent', 'reward_percent' => 10, 'reward_base' => 2_000_000])->assertCreated();
        $this->getJson("/api/v1/targets?period={$period}")->assertJsonPath('reps.0.targets.0.reward_full', 200_000);
        $this->postJson('/api/v1/targets', ['period' => $period, 'user_ids' => [$rep->id], 'all_products' => true, 'target_amount' => 1_000_000, 'reward_type' => 'flat', 'reward_amount' => 300_000])->assertCreated();
        $all = collect($this->getJson("/api/v1/targets?period={$period}")->json('reps.0.targets'))->firstWhere('all_products', true);
        $this->assertSame(1_800_000, $all['achieved_base']);   // 30 bottles sold, in kobo
        $this->assertSame(100.0, (float) min(100, $all['percent']));
        $this->postJson('/api/v1/targets', ['period' => $period, 'user_ids' => [$rep->id], 'reward_type' => 'flat', 'reward_amount' => 1])->assertUnprocessable();

        // Below the lowest level nothing is paid; the levels can be changed.
        $this->putJson('/api/v1/target-rules', ['tiers' => [['min' => 100, 'pays' => 100], ['min' => 90, 'pays' => 60]], 'pay_lag' => 0])->assertOk();
        $this->getJson("/api/v1/targets?period={$period}")->assertJsonPath('rules.tiers.1.min', 90);
    }
}
