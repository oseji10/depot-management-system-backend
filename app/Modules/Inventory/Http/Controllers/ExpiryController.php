<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\Product;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Services\DocumentNumber;
use App\Modules\Inventory\Support\StockStatus;
use App\Modules\Organization\Models\Warehouse;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExpiryController extends Controller
{
    public const DEFAULT_ALERT_DAYS = 30;

    public function __construct(private AccessScope $access)
    {
    }

    /** Batches of expiry-tracked products, nearest expiry first, plus stock that has no expiry date on record. */
    public function index(Request $request): JsonResponse
    {
        $today = now()->startOfDay();

        $rows = StockBatch::query()
            ->with(['product:id,name,sku,size_label,expiry_alert_days', 'warehouse:id,name'])
            ->where('quantity', '>', 0)
            ->when($request->query('warehouse_id'), fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($request->query('search'), fn ($q, $s) => $q->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%")))
            ->orderBy('expiry_date')->orderBy('id')
            ->limit(2000)->get()
            ->map(function (StockBatch $b) use ($today) {
                $alert = $b->product->expiry_alert_days ?: self::DEFAULT_ALERT_DAYS;
                $days = (int) $today->diffInDays($b->expiry_date->startOfDay(), false);

                return [
                    'id' => $b->id, 'batch_no' => $b->batch_no, 'expiry_date' => $b->expiry_date->toDateString(),
                    'quantity' => $b->quantity, 'days_left' => $days,
                    'state' => $days < 0 ? 'expired' : ($days <= $alert ? 'soon' : 'ok'),
                    'product' => $b->product->only(['id', 'name', 'sku', 'size_label']), 'warehouse' => $b->warehouse->only(['id', 'name']),
                ];
            });

        $summary = [
            'expired_units' => (int) $rows->where('state', 'expired')->sum('quantity'),
            'expired_batches' => $rows->where('state', 'expired')->count(),
            'soon_units' => (int) $rows->where('state', 'soon')->sum('quantity'),
            'soon_batches' => $rows->where('state', 'soon')->count(),
        ];

        $state = $request->query('state', 'attention');
        $rows = match ($state) {
            'all' => $rows,
            'attention' => $rows->whereIn('state', ['expired', 'soon']),
            default => $rows->where('state', $state),
        };

        $untracked = $this->untracked($request->query('warehouse_id'));
        $summary['untracked_products'] = count($untracked);

        return response()->json(['summary' => $summary, 'data' => $rows->values(), 'untracked' => $untracked]);
    }

    /** Saleable stock of tracked products that is not covered by any dated batch (counted before tracking began, say). */
    private function untracked(mixed $warehouseId): array
    {
        $balances = StockBalance::query()
            ->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->where('products.tracks_expiry', true)->where('stock_balances.status', StockStatus::SALEABLE)->where('stock_balances.quantity', '>', 0)
            ->when($warehouseId, fn ($q, $v) => $q->where('stock_balances.warehouse_id', $v))
            ->get(['stock_balances.warehouse_id', 'stock_balances.product_id', 'stock_balances.quantity']);

        if ($balances->isEmpty()) {
            return [];
        }

        $batched = StockBatch::query()->whereIn('product_id', $balances->pluck('product_id'))
            ->selectRaw('warehouse_id, product_id, SUM(quantity) AS qty')->groupBy('warehouse_id', 'product_id')->get()
            ->keyBy(fn ($b) => "{$b->warehouse_id}:{$b->product_id}");

        $products = Product::whereIn('id', $balances->pluck('product_id'))->get(['id', 'name', 'sku'])->keyBy('id');
        $warehouses = Warehouse::whereIn('id', $balances->pluck('warehouse_id'))->get(['id', 'name'])->keyBy('id');

        return $balances->map(function ($b) use ($batched, $products, $warehouses) {
            $rest = $b->quantity - (int) ($batched["{$b->warehouse_id}:{$b->product_id}"]->qty ?? 0);

            return $rest > 0 ? [
                'product' => $products[$b->product_id]->only(['id', 'name', 'sku']),
                'warehouse' => $warehouses[$b->warehouse_id]->only(['id', 'name']), 'quantity' => $rest,
            ] : null;
        })->filter()->values()->all();
    }

    /**
     * Puts an expiry date on stock that was already in the warehouse (counted before tracking began, or opening stock).
     * Stock quantities do not change, the units are only labelled.
     */
    public function assign(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('store_id', $storeId)->where('tracks_expiry', true)],
            'batch_no' => ['nullable', 'string', 'max:40'],
            'expiry_date' => ['required', 'date_format:Y-m-d'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000000'],
        ]);

        $warehouse = Warehouse::findOrFail($data['warehouse_id']);

        DB::transaction(function () use ($data, $warehouse) {
            $onHand = (int) StockBalance::withoutGlobalScopes()
                ->where(['warehouse_id' => $warehouse->id, 'product_id' => $data['product_id'], 'status' => StockStatus::SALEABLE])->lockForUpdate()->value('quantity');
            $dated = (int) StockBatch::withoutGlobalScopes()->where(['warehouse_id' => $warehouse->id, 'product_id' => $data['product_id']])->sum('quantity');

            if ($data['quantity'] > $onHand - $dated) {
                abort(422, 'Only '.max(0, $onHand - $dated).' units here have no expiry date yet.');
            }

            $keys = ['warehouse_id' => $warehouse->id, 'product_id' => $data['product_id'], 'batch_no' => $data['batch_no'] ?? '', 'expiry_date' => $data['expiry_date']];

            DB::table('stock_batches')->insertOrIgnore([
                ...$keys, 'store_id' => $warehouse->store_id, 'branch_id' => $warehouse->branch_id, 'quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);

            StockBatch::withoutGlobalScopes()->where($keys)->lockForUpdate()->firstOrFail()->increment('quantity', $data['quantity']);
        });

        return response()->json(['ok' => true]);
    }

    /** Raises a pending stock adjustment that removes the chosen batches. A manager approves it like any other. */
    public function writeOff(Request $request, DocumentNumber $numbers): JsonResponse
    {
        $this->access->requireStoreId();

        $data = $request->validate([
            'batch_ids' => ['required', 'array', 'min:1', 'max:100'],
            'batch_ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $batches = StockBatch::whereIn('id', $data['batch_ids'])->where('quantity', '>', 0)->get();
        abort_if($batches->isEmpty(), 422, 'Those batches have no stock left.');
        abort_if($batches->pluck('warehouse_id')->unique()->count() > 1, 422, 'Write off one warehouse at a time.');
        abort_if($batches->contains(fn ($b) => $b->expiry_date->isFuture() && ! $b->expiry_date->isToday()) && ! $request->boolean('include_in_date'), 422, 'Only expired batches can be written off here.');

        $warehouse = Warehouse::findOrFail($batches->first()->warehouse_id);

        $adjustment = DB::transaction(function () use ($batches, $warehouse, $numbers, $request, $data) {
            $adjustment = StockAdjustment::create([
                'store_id' => $warehouse->store_id, 'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id,
                'number' => $numbers->next($warehouse->store_id, 'ADJ'), 'kind' => 'adjustment', 'reason' => 'expiry_write_off',
                'note' => $data['note'] ?? 'Expired stock', 'created_by' => $request->user()->id,
            ]);

            foreach ($batches as $b) {
                $adjustment->lines()->create([
                    'store_id' => $warehouse->store_id, 'product_id' => $b->product_id, 'status' => StockStatus::SALEABLE,
                    'quantity' => -$b->quantity, 'stock_batch_id' => $b->id, 'batch_no' => $b->batch_no, 'expiry_date' => $b->expiry_date,
                ]);
            }

            return $adjustment;
        });

        return response()->json($adjustment->load('warehouse:id,name', 'creator:id,name'), 201);
    }
}
