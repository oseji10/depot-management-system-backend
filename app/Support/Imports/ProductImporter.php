<?php

namespace App\Support\Imports;

use App\Models\User;
use App\Modules\Catalogue\Models\PriceList;
use App\Modules\Catalogue\Models\Product;
use App\Modules\Catalogue\Models\ProductPrice;
use App\Modules\Catalogue\Models\ProductUnit;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Services\DocumentNumber;
use App\Modules\Inventory\Support\StockStatus;
use App\Modules\Organization\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Products from an export. New products get the chosen category and base unit and a price in the standard list. Stock
 * is never put straight on the shelf: counts become ONE pending opening-balance adjustment per batch, which someone
 * approves on the Stock adjustments page like any other.
 */
class ProductImporter
{
    public function __construct(private int $storeId, private User $actor, private int $categoryId, private int $baseUnitId, private ?Warehouse $warehouse)
    {
    }

    public static function packaging(string $name): string
    {
        $n = mb_strtolower($name);

        return match (true) {
            (bool) preg_match('/\brgb\b/', $n) => 'rgb',
            (bool) preg_match('/\bpet\b/', $n) => 'pet',
            (bool) preg_match('/\bcan\b/', $n) => 'can',
            default => 'other',
        };
    }

    public static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)));
    }

    public function run(array $rows): ImportReport
    {
        $report = new ImportReport();
        $list = PriceList::where('is_default', true)->first();
        $opening = [];

        foreach ($rows as $i => $row) {
            $line = (int) ($row['_row'] ?? $i + 2);
            $name = Cell::text($row, 'Name', 150);

            if ($name === null) {
                $report->problem($line, 'No product name.');

                continue;
            }

            try {
                DB::transaction(function () use ($row, $name, $line, $list, &$opening, $report) {
                    $sku = mb_substr(Cell::text($row, 'Product ID', 40) ?? strtoupper(substr(preg_replace('/\W+/', '', $name), 0, 12)), 0, 40);
                    $price = Cell::number($row, 'Price');
                    $existing = Product::where('sku', $sku)->first() ?? Product::whereRaw('LOWER(name) = ?', [self::key($name)])->first();

                    if ($existing) {
                        $report->add('existing');
                        // Fill in a missing price, but never overwrite one someone has set.
                        if ($price && $list && ($base = ProductUnit::where('product_id', $existing->id)->where('is_base', true)->first())) {
                            $made = ProductPrice::firstOrCreate(
                                ['price_list_id' => $list->id, 'product_unit_id' => $base->id],
                                ['store_id' => $this->storeId, 'price' => $price],
                            );

                            if ($made->wasRecentlyCreated) {
                                $report->add('prices_added');
                            }
                        }

                        return;
                    }

                    $packaging = self::packaging($name);
                    $product = Product::create([
                        'store_id' => $this->storeId, 'sku' => $sku, 'name' => $name, 'packaging' => $packaging, 'is_returnable' => $packaging === 'rgb',
                        'size_label' => preg_match('/(\d+(?:\.\d+)?)\s?(cl|ml|l)\b/i', $name, $m) ? mb_strtolower($m[1].$m[2]) : null,
                        'category_id' => $this->categoryId, 'base_unit_id' => $this->baseUnitId, 'is_active' => true,
                    ]);

                    $unit = ProductUnit::create(['store_id' => $this->storeId, 'product_id' => $product->id, 'unit_id' => $this->baseUnitId, 'factor' => 1, 'is_base' => true, 'is_active' => true]);
                    $report->add('created');

                    if ($price && $list) {
                        ProductPrice::create(['store_id' => $this->storeId, 'price_list_id' => $list->id, 'product_unit_id' => $unit->id, 'price' => $price]);
                    } else {
                        $report->add('without_price');
                    }

                    $qty = (int) round(Cell::number($row, 'Stock Qty') ?? 0);

                    if ($this->warehouse && $qty > 0) {
                        $cost = Cell::number($row, 'Cost');
                        $opening[] = ['product_id' => $product->id, 'quantity' => $qty, 'unit_cost' => $cost && $cost > 0 ? $cost : ($price ?: 0), 'guessed' => ! ($cost && $cost > 0)];
                    }
                });
            } catch (\Throwable $e) {
                $report->problem($line, "{$name}: ".$e->getMessage());
            }
        }

        if ($opening) {
            $this->openingStock($opening, $report);
        }

        return $report;
    }

    private function openingStock(array $opening, ImportReport $report): void
    {
        DB::transaction(function () use ($opening, $report) {
            $adjustment = StockAdjustment::create([
                'store_id' => $this->storeId, 'branch_id' => $this->warehouse->branch_id, 'warehouse_id' => $this->warehouse->id,
                'number' => app(DocumentNumber::class)->next($this->storeId, 'ADJ'), 'kind' => 'adjustment', 'reason' => 'opening_balance',
                'status' => 'pending', 'note' => 'Opening stock imported from a product file. Check the counts, then approve.', 'created_by' => $this->actor->id,
            ]);

            foreach ($opening as $o) {
                $adjustment->lines()->create([
                    'store_id' => $this->storeId, 'product_id' => $o['product_id'], 'status' => StockStatus::SALEABLE,
                    'quantity' => $o['quantity'], 'unit_cost' => $o['unit_cost'],
                ]);
            }

            $report->add('opening_lines', count($opening));
            $report->add('cost_guessed', count(array_filter($opening, fn ($o) => $o['guessed'])));
            $report->note("Opening stock waits for approval as {$adjustment->number}.");
        });
    }
}
