<?php

namespace App\Support\Imports;

use App\Models\User;
use App\Modules\Catalogue\Models\PriceList;
use App\Modules\Catalogue\Models\Product;
use App\Modules\Catalogue\Models\ProductPrice;
use App\Modules\Catalogue\Models\ProductUnit;
use App\Modules\Catalogue\Models\Unit;
use App\Modules\Organization\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Past orders from an export, kept as history. They appear in Sales, customer insights and sales reports, but they do
 * NOT move stock and do NOT post to the books: the stock count and opening balances you start with already reflect them.
 * Each order is tied to the old system's order id, so uploading the same file twice never doubles anything.
 */
class OrderImporter
{
    public const REF = 'import:';

    private array $products = [];     // normalised name => Product|false
    private array $units = [];        // "productId:factor" => [unit_id, factor]
    private array $customers = [];    // old customer id => our id|null
    private ?int $listId = null;
    private CustomerImporter $customerImporter;

    public function __construct(private int $storeId, private User $actor, private int $branchId, private Warehouse $warehouse)
    {
        $this->customerImporter = new CustomerImporter($storeId);
        $this->listId = PriceList::where('is_default', true)->value('id');
    }

    public function run(array $rows): ImportReport
    {
        $report = new ImportReport();

        foreach ($rows as $i => $row) {
            $line = (int) ($row['_row'] ?? $i + 2);

            try {
                DB::transaction(fn () => $this->one($row, $line, $report));
            } catch (\Throwable $e) {
                $report->problem($line, 'Could not import: '.$e->getMessage());
            }
        }

        return $report;
    }

    private function one(array $row, int $line, ImportReport $report): void
    {
        $orderId = Cell::text($row, 'Order ID', 30) ?? Cell::text($row, 'Order #', 30);
        $number = Cell::text($row, 'Order #', 20);
        $soldAt = Cell::dateTime($row, 'Order Date');

        if ($orderId === null || $number === null || $soldAt === null) {
            $report->problem($line, 'Needs an order number, order id and date.');

            return;
        }

        $ref = self::REF.$orderId;

        if (DB::table('sales')->where('store_id', $this->storeId)->where('client_ref', $ref)->exists()) {
            $report->add('existing');

            return;
        }

        if (DB::table('sales')->where('store_id', $this->storeId)->where('number', $number)->exists()) {
            $number = mb_substr('B'.$orderId, 0, 20);
        }

        $status = strtoupper(Cell::text($row, 'Status') ?? 'COMPLETED');
        $returned = strtoupper(Cell::text($row, 'Shipping Status') ?? '') === 'RETURNED';
        $void = $status === 'CANCELLED' || $returned;

        $subtotal = Cell::kobo($row, 'Subtotal');
        $total = Cell::kobo($row, 'Total');
        $paid = Cell::kobo($row, 'Amount Paid');
        $due = Cell::kobo($row, 'Amount Due');

        $cashier = Cell::text($row, 'Created By', 80);
        $note = trim('Imported from previous system'.($cashier ? " · cashier {$cashier}" : '').(Cell::text($row, 'Note', 200) ? ' · '.Cell::text($row, 'Note', 200) : ''));

        $saleId = DB::table('sales')->insertGetId([
            'store_id' => $this->storeId, 'branch_id' => $this->branchId, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer($row, $report), 'number' => $number, 'client_ref' => $ref,
            'channel' => strtoupper(Cell::text($row, 'Channel') ?? '') === 'WEB' ? 'delivery' : 'counter',
            'status' => $void ? 'void' : 'completed',
            'payment_status' => $due <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
            'sold_at' => $soldAt, 'subtotal' => max($subtotal, $total), 'discount_total' => max(0, $subtotal - $total), 'total' => $total,
            'amount_paid' => $paid, 'balance_due' => $due, 'cogs_total' => 0, 'note' => mb_substr($note, 0, 500),
            'created_by' => $this->actor->id,
            'voided_by' => $void ? $this->actor->id : null, 'voided_at' => $void ? $soldAt : null,
            'void_reason' => $void ? ($returned ? 'Returned (previous system)' : 'Cancelled (previous system)') : null,
            'created_at' => $soldAt, 'updated_at' => $soldAt,
        ]);

        $this->lines($saleId, Cell::text($row, 'Items', 4000), $line, $report);

        if ($paid > 0) {
            DB::table('sale_payments')->insert([
                'store_id' => $this->storeId, 'branch_id' => $this->branchId, 'sale_id' => $saleId, 'method' => $this->method(Cell::text($row, 'Payment Method')),
                'amount' => $paid, 'reference' => null, 'paid_at' => $soldAt, 'received_by' => $this->actor->id,
                'voided_at' => $void ? $soldAt : null, 'created_at' => $soldAt, 'updated_at' => $soldAt,
            ]);
        }

        $report->add($void ? 'created_void' : 'created');
    }

    private function method(?string $raw): string
    {
        // When an order was split across methods the file does not say how, so the first one listed takes it all.
        $first = strtoupper(trim(explode(',', (string) $raw)[0]));

        return match ($first) {
            'CASH' => 'cash',
            'POS' => 'pos',
            default => 'transfer',
        };
    }

    private function customer(array $row, ImportReport $report): ?int
    {
        $oldId = Cell::text($row, 'Customer ID', 30);
        $name = Cell::text($row, 'Customer', 150);
        $phone = Cell::text($row, 'Phone', 40);

        if ($oldId === null || CustomerImporter::isWalkIn($name, $phone)) {
            return null;
        }

        return $this->customers[$oldId] ??= $this->customerImporter->findOrCreate($oldId, $name, $phone, [], $report);
    }

    /** "Pet Coke 50cl x2 @ 4550; RGB Coke 50cl(full crate (24)) x5 @ 6000" */
    private function lines(int $saleId, ?string $items, int $row, ImportReport $report): void
    {
        foreach (array_filter(array_map('trim', explode(';', (string) $items))) as $part) {
            if (! preg_match('/^(.*) x(\d+(?:\.\d+)?) @ (\d+(?:\.\d+)?)$/u', $part, $m)) {
                $report->add('lines_skipped');
                $report->note("Row {$row}: could not read the item \"{$part}\".");

                continue;
            }

            [$name, $factor] = $this->split($m[1]);
            $product = $this->product($name);

            if (! $product) {
                $report->add('lines_skipped');
                $report->note("No product called \"{$name}\" — import products first. Its lines were left off.");

                continue;
            }

            [$unitId, $factor] = $this->unit($product, $factor, (float) $m[3]);
            $qty = max(1, (int) round((float) $m[2]));
            $price = (int) round(((float) $m[3]) * 100);

            DB::table('sale_lines')->insert([
                'store_id' => $this->storeId, 'sale_id' => $saleId, 'product_id' => $product->id, 'unit_id' => $unitId, 'factor' => $factor,
                'quantity' => $qty, 'list_price' => $price, 'unit_price' => $price, 'line_total' => $qty * $price, 'cost_total' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $report->add('lines');
        }
    }

    /** "RGB Coke 50cl - (full crate (24))" -> ["RGB Coke 50cl", 24]; a plain name -> [name, 1]. */
    public function split(string $text): array
    {
        $text = trim($text);
        $name = $text;
        $label = null;

        foreach (['/^(.*?)\s*:\s*(.+?)\s*\(\d{5,}\)$/', '/^(.*?)\s*-\s*\((.+)\)$/', '/^(.*?)\s*\((.+)\)$/'] as $re) {
            if (preg_match($re, $text, $m)) {
                [$name, $label] = [trim($m[1]), $m[2]];
                break;
            }
        }

        if ($label !== null && preg_match('/\((\d+)\)\s*$/', $label, $f)) {
            return [$name, max(1, (int) $f[1])];
        }

        return [$label !== null ? $name : $text, 1];
    }

    private function product(string $name): Product|false|null
    {
        $key = ProductImporter::key($name);

        return $this->products[$key] ??= (Product::whereRaw('LOWER(name) = ?', [$key])->first() ?? false);
    }

    /** The product's unit for this pack size, creating a "Pack of N" the first time one is seen. */
    private function unit(Product $product, int $factor, float $price): array
    {
        $key = "{$product->id}:{$factor}";

        if (isset($this->units[$key])) {
            return $this->units[$key];
        }

        $pu = ProductUnit::where('product_id', $product->id)->where('factor', $factor)->orderByDesc('is_active')->first();

        if (! $pu) {
            $unit = Unit::firstOrCreate(['store_id' => $this->storeId, 'abbreviation' => "PK{$factor}"], ['name' => "Pack of {$factor}"]);
            $pu = ProductUnit::create(['store_id' => $this->storeId, 'product_id' => $product->id, 'unit_id' => $unit->id, 'factor' => $factor, 'is_base' => false, 'is_active' => true]);

            if ($this->listId) {
                ProductPrice::firstOrCreate(['price_list_id' => $this->listId, 'product_unit_id' => $pu->id], ['store_id' => $this->storeId, 'price' => $price]);
            }
        }

        return $this->units[$key] = [$pu->unit_id, $pu->factor];
    }
}
