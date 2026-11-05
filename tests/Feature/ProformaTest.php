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
 * Money in these tests is kobo. One crate costs 6,000 naira (600,000 kobo) and sells for 6,200 naira (620,000 kobo);
 * a crate holds 12 bottles, a bottle sells for 600 naira (60,000 kobo).
 */
class ProformaTest extends TestCase
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

    private function receive(int $crates, ?int $warehouseId = null): void
    {
        $id = $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $this->cocaCola->id, 'warehouse_id' => $warehouseId ?? $this->w1->id, 'received_on' => now()->toDateString(),
            'supplier_reference' => 'WB-'.$this->refSeq++,
            'lines' => [['product_id' => $this->coke->id, 'unit_id' => $this->crate, 'delivered' => $crates, 'unit_cost' => 6000]],
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/goods-receipts/{$id}/post")->assertOk();
    }

    public function test_a_proforma_is_quoted_sent_and_turned_into_a_sale(): void
    {
        $customer = Customer::create(['store_id' => $this->store->id, 'name' => 'Mama Put', 'type' => 'retail', 'phone' => '08031112222', 'credit_limit' => 5_000_000]);

        $id = $this->postJson('/api/v1/proformas', [
            'customer_id' => $customer->id, 'issued_on' => now()->toDateString(), 'valid_until' => now()->addDays(7)->toDateString(), 'delivery_fee' => 50_000,
            'lines' => [['product_id' => $this->coke->id, 'unit_id' => $this->crate, 'quantity' => 2, 'unit_price' => 620_000]],
        ])->assertCreated()->assertJsonPath('number', 'PRO-000001')->assertJsonPath('subtotal', 1_240_000)->assertJsonPath('total', 1_290_000)->assertJsonPath('customer_name', 'Mama Put')->json('id');

        // A quote changes no stock and records no sale.
        $this->assertSame(0, DB::table('sales')->count());

        // The price can never be above the list price.
        $this->putJson("/api/v1/proformas/{$id}", [
            'customer_id' => $customer->id, 'issued_on' => now()->toDateString(),
            'lines' => [['product_id' => $this->coke->id, 'unit_id' => $this->crate, 'quantity' => 2, 'unit_price' => 700_000]],
        ])->assertUnprocessable();

        $this->postJson("/api/v1/proformas/{$id}/status", ['status' => 'sent'])->assertOk()->assertJsonPath('status', 'sent');

        // Converting makes a credit sale for the customer at the quoted price (delivery is not part of a sale).
        $out = $this->postJson("/api/v1/proformas/{$id}/convert", ['warehouse_id' => $this->w1->id])->assertOk()->json();
        $this->assertSame('converted', $out['proforma']['status']);
        $this->getJson("/api/v1/sales/{$out['sale_id']}")->assertOk()->assertJsonPath('total', 1_240_000)->assertJsonPath('payment_status', 'unpaid');

        // It cannot be converted twice, and a converted one cannot be deleted.
        $this->postJson("/api/v1/proformas/{$id}/convert", ['warehouse_id' => $this->w1->id])->assertUnprocessable();
        $this->deleteJson("/api/v1/proformas/{$id}")->assertUnprocessable();
    }

    public function test_a_prospect_can_be_quoted_but_not_sold_to_on_credit_without_a_customer(): void
    {
        $id = $this->postJson('/api/v1/proformas', [
            'customer_name' => 'New Hotel Ltd', 'issued_on' => now()->toDateString(),
            'lines' => [['product_id' => $this->coke->id, 'unit_id' => $this->bottle, 'quantity' => 24, 'unit_price' => 60_000]],
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/proformas/{$id}/convert", ['warehouse_id' => $this->w1->id])->assertUnprocessable();

        // Open ones can be deleted; the number is never reused.
        $this->deleteJson("/api/v1/proformas/{$id}")->assertNoContent();
        $this->getJson('/api/v1/proformas')->assertOk()->assertJsonCount(0, 'data');
        $next = $this->postJson('/api/v1/proformas', [
            'customer_name' => 'Next', 'issued_on' => now()->toDateString(),
            'lines' => [['product_id' => $this->coke->id, 'unit_id' => $this->bottle, 'quantity' => 1, 'unit_price' => 60_000]],
        ])->assertCreated()->json('number');
        $this->assertSame('PRO-000002', $next);
    }

    public function test_deleting_set_up_records_hides_them_and_frees_their_names(): void
    {
        $unit = Unit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'name' => 'Carton', 'abbreviation' => 'CTN']);

        // An unused unit can be deleted, and then the same abbreviation can be used again.
        $this->deleteJson("/api/v1/units/{$unit->id}")->assertNoContent();
        $this->assertSoftDeleted('units', ['id' => $unit->id]);
        $this->postJson('/api/v1/units', ['name' => 'Carton', 'abbreviation' => 'CTN'])->assertCreated();

        // A unit that products use cannot be deleted.
        $this->deleteJson("/api/v1/units/{$this->crate}")->assertUnprocessable();

        // A customer who has bought cannot be deleted; one who has not, can.
        $buyer = Customer::create(['store_id' => $this->store->id, 'name' => 'Buyer', 'type' => 'retail', 'phone' => '08030000001']);
        $idle = Customer::create(['store_id' => $this->store->id, 'name' => 'Idle', 'type' => 'retail', 'phone' => '08030000002']);
        $this->postJson('/api/v1/sales', ['warehouse_id' => $this->w1->id, 'customer_id' => $buyer->id, 'lines' => [['product_id' => $this->coke->id, 'unit_id' => $this->bottle, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 60_000]]])->assertCreated();
        $this->deleteJson("/api/v1/customers/{$buyer->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/customers/{$idle->id}")->assertNoContent();
        $this->assertSoftDeleted('customers', ['id' => $idle->id]);
    }
}
