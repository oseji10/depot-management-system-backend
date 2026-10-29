<?php

namespace App\Modules\Catalogue\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\Product;
use App\Modules\Catalogue\Models\ProductUnit;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    private const WITH = [
        'category:id,name', 'rebateCategory:id,name', 'baseUnit:id,name,abbreviation', 'units.unit:id,name,abbreviation',
    ];

    public function __construct(private AccessScope $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rows = Product::query()
            ->with(self::WITH)
            ->when($request->query('category_id'), fn ($q, $v) => $q->where('category_id', $v))
            ->when($request->query('rebate_category_id'), fn ($q, $v) => $q->where('rebate_category_id', $v))
            ->when($request->query('packaging'), fn ($q, $v) => $q->where('packaging', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('is_active', $v === 'active'))
            ->when($request->query('search'), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%")->orWhere('brand', 'like', "%{$s}%")
            ))
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Product::with(self::WITH)->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $request->validate($this->rules($storeId));

        $product = DB::transaction(function () use ($data, $storeId) {
            $product = Product::create([
                ...collect($data)->except(['units', 'barcode'])->all(),
                'store_id' => $storeId,
            ]);
            $this->syncUnits($product, $data['barcode'] ?? null, $data['units'] ?? []);

            return $product;
        });

        return response()->json($product->load(self::WITH), 201);
    }

    /**
     * A product can only be deleted if nothing ever referenced it (no stock, sales, orders...). Products with history
     * are refused, because the database keeps every reference; switch those off with "Product is active" instead.
     */
    public function destroy(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        try {
            DB::transaction(function () use ($product) {
                foreach ($product->units()->get() as $unit) {
                    $unit->prices()->delete();
                }
                $product->units()->delete();
                $product->delete();
            });
        } catch (\Illuminate\Database\QueryException) {
            abort(422, 'This product has stock, sales or purchase history and cannot be deleted. Untick "Product is active" to stop selling it.');
        }

        if ($product->image_path) {
            Storage::disk('local')->delete($product->image_path);
        }

        return response()->json(['deleted' => true]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $data = $request->validate($this->rules($product->store_id, $product));

        // The base unit is what stock is counted in, so it can only change while the product has no history at all.
        $newBase = isset($data['base_unit_id']) ? (int) $data['base_unit_id'] : null;
        unset($data['base_unit_id']);

        if ($newBase !== null && $newBase !== (int) $product->base_unit_id) {
            abort_if($this->hasHistory($product->id), 422, 'This product already has stock, sales or purchases counted in its current unit, so the unit cannot change. Untick Active and add a new product instead.');
            abort_unless(array_key_exists('units', $data), 422, 'Send the pack list along with a new base unit.');
        }

        DB::transaction(function () use ($product, $data, $newBase) {
            if ($newBase !== null && $newBase !== (int) $product->base_unit_id) {
                ProductUnit::where('product_id', $product->id)->update(['is_base' => false]);
                $product->update(['base_unit_id' => $newBase]);
            }

            $product->update(collect($data)->except(['units', 'barcode'])->all());

            // Only touch units when the caller sent the list; an omitted key must never deactivate packs.
            if (array_key_exists('units', $data)) {
                $this->syncUnits($product, $data['barcode'] ?? null, $data['units']);
            }
        });

        return response()->json($product->fresh(self::WITH));
    }

    /** True once anything has been counted, sold or bought in this product's unit. */
    private function hasHistory(int $productId): bool
    {
        foreach (['stock_movements', 'stock_balances', 'sale_lines', 'purchase_order_lines', 'goods_receipt_lines', 'stock_adjustment_lines', 'trip_lines'] as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table) && DB::table($table)->where('product_id', $productId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The base unit always exists with factor 1. Other units are upserted; anything left out is
     * deactivated rather than deleted, so old prices and (later) transactions keep their references.
     */
    private function syncUnits(Product $product, ?string $baseBarcode, array $units): void
    {
        $this->assertUnitsValid($product, $baseBarcode, $units);

        $keep = [$product->base_unit_id];

        ProductUnit::updateOrCreate(
            ['product_id' => $product->id, 'unit_id' => $product->base_unit_id],
            ['store_id' => $product->store_id, 'factor' => 1, 'barcode' => $baseBarcode, 'is_base' => true, 'is_active' => true],
        );

        foreach ($units as $u) {
            $keep[] = (int) $u['unit_id'];

            ProductUnit::updateOrCreate(
                ['product_id' => $product->id, 'unit_id' => $u['unit_id']],
                ['store_id' => $product->store_id, 'factor' => $u['factor'], 'barcode' => $u['barcode'] ?? null, 'is_base' => false, 'is_active' => true],
            );
        }

        ProductUnit::where('product_id', $product->id)->whereNotIn('unit_id', $keep)->update(['is_active' => false]);
    }

    private function assertUnitsValid(Product $product, ?string $baseBarcode, array $units): void
    {
        $unitIds = array_map(fn ($u) => (int) $u['unit_id'], $units);

        if (in_array($product->base_unit_id, $unitIds, true) || count($unitIds) !== count(array_unique($unitIds))) {
            throw ValidationException::withMessages(['units' => 'Each unit can only be listed once, and the base unit is added automatically.']);
        }

        $barcodes = array_values(array_filter([$baseBarcode, ...array_map(fn ($u) => $u['barcode'] ?? null, $units)]));

        if (count($barcodes) !== count(array_unique($barcodes))) {
            throw ValidationException::withMessages(['units' => 'The same barcode is used twice on this product.']);
        }

        $taken = ProductUnit::whereIn('barcode', $barcodes)->where('product_id', '!=', $product->id)->exists();

        if ($taken) {
            throw ValidationException::withMessages(['units' => 'One of these barcodes already belongs to another product.']);
        }
    }

    /**
     * The browser shrinks the picture and sends it as a data URL (JPEG, PNG or WebP). It is checked as a real image,
     * kept on private storage, and served back only to signed-in users of the same store.
     */
    public function uploadImage(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $data = $request->validate(['image' => ['required', 'string', 'max:3000000']]);

        if (! preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#s', $data['image'], $m)) {
            throw ValidationException::withMessages(['image' => 'Choose a JPEG, PNG or WebP picture.']);
        }

        $bytes = base64_decode($m[2], true);
        $info = $bytes ? @getimagesizefromstring($bytes) : false;

        if (! $bytes || ! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages(['image' => 'That file is not a valid picture.']);
        }

        if (strlen($bytes) > 2_000_000) {
            throw ValidationException::withMessages(['image' => 'The picture is too large. Use one under 2 MB.']);
        }

        $ext = ['jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp'][$m[1]];
        $path = "product-images/{$product->store_id}/".Str::uuid().".{$ext}";

        Storage::disk('local')->put($path, $bytes);
        $old = $product->image_path;
        $product->update(['image_path' => $path]);

        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return response()->json($product->fresh(self::WITH));
    }

    public function removeImage(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        if ($product->image_path) {
            Storage::disk('local')->delete($product->image_path);
            $product->update(['image_path' => null]);
        }

        return response()->json($product->fresh(self::WITH));
    }

    public function image(int $id)
    {
        $product = Product::findOrFail($id);
        abort_unless($product->image_path && Storage::disk('local')->exists($product->image_path), 404);

        return Storage::disk('local')->response($product->image_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    private function rules(int $storeId, ?Product $product = null): array
    {
        $unit = Rule::exists('units', 'id')->where('store_id', $storeId);

        return [
            'name' => ['required', 'string', 'max:150'],
            'sku' => ['required', 'string', 'max:40',
                Rule::unique('products', 'sku')->where('store_id', $storeId)->ignore($product?->id)],
            'brand' => ['nullable', 'string', 'max:60'],
            'size_label' => ['nullable', 'string', 'max:30'],
            'packaging' => ['required', Rule::in(Product::PACKAGING)],
            'is_returnable' => ['sometimes', 'boolean'],
            'tracks_expiry' => ['sometimes', 'boolean'],
            'expiry_alert_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'bottle_container_id' => ['nullable', 'integer', Rule::exists('container_types', 'id')->where('store_id', $storeId)->where('kind', 'bottle')],
            'crate_container_id' => ['nullable', 'integer', Rule::exists('container_types', 'id')->where('store_id', $storeId)->where('kind', 'crate')],
            'category_id' => ['required', 'integer', Rule::exists('product_categories', 'id')->where('store_id', $storeId)],
            'rebate_category_id' => ['nullable', 'integer', Rule::exists('rebate_categories', 'id')->where('store_id', $storeId)],
            'base_unit_id' => [$product ? 'sometimes' : 'required', 'integer', $unit],
            'barcode' => ['nullable', 'string', 'max:64'],
            // Base units in one physical case (what Coca-Cola counts for rebates). Empty = use the largest pack.
            'units_per_case' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
            'units' => ['sometimes', 'array', 'max:6'],
            'units.*.unit_id' => ['required', 'integer', $unit],
            'units.*.factor' => ['required', 'integer', 'min:2', 'max:100000'],
            'units.*.barcode' => ['nullable', 'string', 'max:64'],
        ];
    }
}
