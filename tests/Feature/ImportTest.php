<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalogue\Models\ProductCategory;
use App\Modules\Catalogue\Models\Unit;
use App\Modules\Organization\Models\Branch;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Warehouse;
use App\Modules\Organization\Services\StoreProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImportTest extends TestCase
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

    private function products(): array
    {
        $rows = [
            ['_row' => 2, 'Product ID' => 5001, 'Name' => 'RGB Coke 50cl', 'Price' => 5900, 'Cost' => 5500, 'Stock Qty' => 985],
            ['_row' => 3, 'Product ID' => 5002, 'Name' => 'Pet Coke 50cl', 'Price' => 4550, 'Cost' => null, 'Stock Qty' => 10],
            ['_row' => 4, 'Product ID' => 5003, 'Name' => 'Can Fanta 33cl', 'Price' => null, 'Cost' => null, 'Stock Qty' => 0],
            ['_row' => 5, 'Product ID' => 5004, 'Name' => null],
        ];

        return [
            'rows' => $rows,
            'category_id' => ProductCategory::withoutGlobalScopes()->where('store_id', $this->store->id)->value('id'),
            'base_unit_id' => Unit::withoutGlobalScopes()->where('store_id', $this->store->id)->where('abbreviation', 'BTL')->value('id'),
            'warehouse_id' => Warehouse::withoutGlobalScopes()->where('store_id', $this->store->id)->value('id'),
        ];
    }

    public function test_products_import_once_with_prices_and_a_pending_opening_stock_adjustment(): void
    {
        $r = $this->postJson('/api/v1/imports/products', $this->products())->assertOk();
        $this->assertSame(3, $r->json('counts.created'));
        $this->assertSame(1, $r->json('counts.problems'));
        $this->assertSame(1, $r->json('counts.without_price'));
        $this->assertSame(2, $r->json('counts.opening_lines'));
        $this->assertSame(1, $r->json('counts.cost_guessed'));

        $this->assertDatabaseHas('products', ['sku' => '5001', 'packaging' => 'rgb', 'is_returnable' => true, 'size_label' => '50cl']);
        $this->assertDatabaseHas('products', ['sku' => '5002', 'packaging' => 'pet']);
        $this->assertDatabaseHas('product_prices', ['price' => 5900]);

        $adjustment = DB::table('stock_adjustments')->first();
        $this->assertSame('pending', $adjustment->status);
        $this->assertSame('opening_balance', $adjustment->reason);
        $this->assertSame(0, (int) DB::table('stock_balances')->count(), 'stock is not added until the adjustment is approved');

        // The same file again adds nothing.
        $again = $this->postJson('/api/v1/imports/products', $this->products())->assertOk();
        $this->assertSame(3, $again->json('counts.existing'));
        $this->assertNull($again->json('counts.created'));
        $this->assertSame(1, DB::table('stock_adjustments')->count());
    }

    public function test_customers_import_with_routes_and_skip_duplicates_and_walk_ins(): void
    {
        $rows = [
            ['_row' => 2, 'Customer ID' => 11, 'Name' => 'Mummy Dammy', 'Phone' => '+2349067002039', 'Group / Route' => 'Route A(Karshi)', 'Street' => 'Karu'],
            ['_row' => 3, 'Customer ID' => 12, 'Name' => 'Same Phone', 'Phone' => '09067002039', 'Group / Route' => 'Route A(Karshi)'],
            ['_row' => 4, 'Customer ID' => 13, 'Name' => 'walk-in customer', 'Phone' => '+2341234567890'],
            ['_row' => 5, 'Customer ID' => 14, 'Name' => 'No Phone Shop'],
            ['_row' => 6],
        ];

        $r = $this->postJson('/api/v1/imports/customers', ['rows' => $rows])->assertOk();
        $this->assertSame(2, $r->json('counts.customers_created'));
        $this->assertSame(1, $r->json('counts.customers_existing'));
        $this->assertSame(1, $r->json('counts.skipped_walk_in'));
        $this->assertSame(1, $r->json('counts.problems'));

        $this->assertDatabaseHas('customers', ['name' => 'Mummy Dammy', 'phone' => '09067002039', 'address' => 'Karu', 'client_ref' => 'bumpa:11']);
        $this->assertDatabaseHas('customer_groups', ['name' => 'Route A(Karshi)', 'kind' => 'route']);
        $this->assertSame(1, DB::table('customer_group_members')->count());

        $this->postJson('/api/v1/imports/customers', ['rows' => $rows])->assertOk()->assertJsonPath('counts.customers_existing', 3);
        $this->assertSame(2, DB::table('customers')->count());
    }

    public function test_orders_import_as_history_without_touching_stock_or_the_books(): void
    {
        $this->postJson('/api/v1/imports/products', $this->products())->assertOk();
        $branch = Branch::withoutGlobalScopes()->where('store_id', $this->store->id)->value('id');
        $journals = DB::table('journal_entries')->count();

        $order = fn (array $over = []) => array_merge([
            '_row' => 2, 'Order #' => '04956', 'Order ID' => 6087430, 'Order Date' => '2026-10-29 17:41:19', 'Customer ID' => 2873143, 'Customer' => 'Mr Joseph', 'Phone' => '+2348222225553',
            'Channel' => 'POS', 'Status' => 'COMPLETED', 'Shipping Status' => 'PICKED_UP',
            'Items' => 'RGB Coke 50cl - (full crate (24)) x2 @ 6000; Pet Coke 50cl x3 @ 4550; Unknown Drink x1 @ 100',
            'Subtotal' => 25650, 'Total' => 25650, 'Amount Paid' => 25650, 'Amount Due' => 0, 'Payment Method' => 'POS, CASH', 'Created By' => 'Mercy Otei',
        ], $over);

        $rows = [
            $order(),
            $order(['_row' => 3, 'Order #' => '04957', 'Order ID' => 6087431, 'Customer ID' => null, 'Customer' => 'walk-in customer', 'Phone' => '+2341234567890', 'Items' => 'Pet Coke 50cl x1 @ 4550', 'Subtotal' => 4550, 'Total' => 4550, 'Amount Paid' => 4550, 'Payment Method' => 'CASH']),
            $order(['_row' => 4, 'Order #' => '04958', 'Order ID' => 6087432, 'Status' => 'COMPLETED', 'Shipping Status' => 'RETURNED', 'Items' => 'Pet Coke 50cl x1 @ 4550', 'Subtotal' => 4550, 'Total' => 4550, 'Amount Paid' => 4550, 'Customer ID' => null]),
        ];

        $r = $this->postJson('/api/v1/imports/orders', ['rows' => $rows, 'branch_id' => $branch])->assertOk();
        $this->assertSame(2, $r->json('counts.created'));
        $this->assertSame(1, $r->json('counts.created_void'));
        $this->assertSame(1, $r->json('counts.lines_skipped'));
        $this->assertSame(1, $r->json('counts.customers_created'));

        $sale = DB::table('sales')->where('number', '04956')->first();
        $this->assertSame(2_565_000, (int) $sale->total);
        $this->assertSame('2026-10-29 17:41:19', (string) $sale->sold_at);
        $this->assertSame('import:6087430', $sale->client_ref);
        $this->assertNotNull($sale->customer_id);
        $this->assertDatabaseHas('sale_lines', ['sale_id' => $sale->id, 'factor' => 24, 'quantity' => 2, 'unit_price' => 600_000, 'line_total' => 1_200_000]);
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $sale->id, 'method' => 'pos', 'amount' => 2_565_000]);
        $this->assertDatabaseHas('sales', ['number' => '04957', 'customer_id' => null, 'status' => 'completed']);
        $this->assertDatabaseHas('sales', ['number' => '04958', 'status' => 'void']);

        $this->assertSame(0, DB::table('stock_balances')->count());
        $this->assertSame($journals, DB::table('journal_entries')->count());

        // Again: nothing doubles. And a history-only order cannot be voided.
        $this->postJson('/api/v1/imports/orders', ['rows' => $rows, 'branch_id' => $branch])->assertOk()->assertJsonPath('counts.existing', 3);
        $this->assertSame(3, DB::table('sales')->count());
        $this->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'test'])->assertStatus(422);
    }
}
