<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\InventoryPosting;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Services\DocumentNumber;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Support\Approvals;
use App\Modules\Inventory\Support\StockStatus;
use App\Modules\Organization\Models\Warehouse;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Physical count: snapshot -> count -> submit -> approve.
 * Approval posts (counted - current balance) so the ledger ends up exactly equal to what was counted.
 * Pause sales and transfers in the warehouse while counting; the sheet flags lines whose stock moved meanwhile.
 */
class StockCountController extends Controller
{
    private const WITH = ['warehouse:id,name', 'creator:id,name'];

    public function __construct(private AccessScope $access, private StockService $stock, private InventoryPosting $posting)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rows = StockCount::query()
            ->with(self::WITH)->withCount('lines')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    public function show(int $id): JsonResponse
    {
        $count = StockCount::with([...self::WITH, 'lines.product:id,name,sku,size_label', 'lines.product.units.unit:id,name'])->findOrFail($id);

        $current = StockBalance::withoutGlobalScopes()->where('warehouse_id', $count->warehouse_id)->get()
            ->keyBy(fn ($b) => "{$b->product_id}:{$b->status}");

        $count->lines->each(function ($line) use ($current, $count) {
            $now = $count->status === 'approved' ? null : (int) ($current["{$line->product_id}:{$line->status}"]->quantity ?? 0);
            $line->setAttribute('current_quantity', $now);
            $line->setAttribute('drifted', $now !== null && $now !== $line->expected_quantity);
        });

        $count->setRelation('lines', $count->lines->sortBy(fn ($l) => $l->product->name)->values());

        return response()->json($count);
    }

    /** Starts a count and snapshots everything the system believes is in the warehouse. */
    public function store(Request $request, DocumentNumber $numbers): JsonResponse
    {
        $data = $request->validate(['warehouse_id' => ['required', 'integer'], 'note' => ['nullable', 'string', 'max:500']]);
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);

        $count = DB::transaction(function () use ($data, $warehouse, $numbers, $request) {
            $count = StockCount::create([
                'store_id' => $warehouse->store_id, 'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id,
                'number' => $numbers->next($warehouse->store_id, 'CNT'), 'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            StockBalance::withoutGlobalScopes()->where('warehouse_id', $warehouse->id)->where('quantity', '>', 0)->get()
                ->each(fn ($b) => $count->lines()->create([
                    'store_id' => $warehouse->store_id, 'product_id' => $b->product_id,
                    'status' => $b->status, 'expected_quantity' => $b->quantity,
                ]));

            return $count;
        });

        return response()->json($count->load(self::WITH), 201);
    }

    /** Save counted quantities. Lines for products that were not expected can be added by naming product and status. */
    public function saveLines(Request $request, int $id): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $count = StockCount::findOrFail($id);

        abort_unless($count->status === 'counting', 422, 'This count is no longer open for editing.');

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('store_id', $storeId)],
            'lines.*.status' => ['required', Rule::in(StockStatus::ALL)],
            'lines.*.counted_quantity' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ]);

        DB::transaction(function () use ($count, $data) {
            foreach ($data['lines'] as $row) {
                $line = $count->lines()->firstOrCreate(
                    ['product_id' => $row['product_id'], 'status' => $row['status']],
                    ['store_id' => $count->store_id, 'expected_quantity' => $this->stock->balance($count->warehouse_id, $row['product_id'], $row['status'])],
                );

                $line->update(['counted_quantity' => $row['counted_quantity']]);
            }
        });

        return $this->show($id);
    }

    public function submit(int $id): JsonResponse
    {
        DB::transaction(function () use ($id) {
            $count = StockCount::lockForUpdate()->findOrFail($id);

            abort_unless($count->status === 'counting', 422, 'This count is not open.');

            $missing = $count->lines()->whereNull('counted_quantity')->count();
            abort_if($missing > 0, 422, "{$missing} line(s) have no count yet. Enter 0 for anything you could not find.");
            abort_if($count->lines()->doesntExist(), 422, 'Nothing has been counted.');

            $count->update(['status' => 'submitted', 'submitted_at' => now()]);
        });

        return $this->show($id);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        DB::transaction(function () use ($id, $actor) {
            $count = StockCount::with('lines')->lockForUpdate()->findOrFail($id);

            abort_unless($count->status === 'submitted', 422, 'Only a submitted count can be approved.');
            Approvals::assertCanDecide($actor, $count->created_by);

            $warehouse = Warehouse::findOrFail($count->warehouse_id);

            $variances = [];

            foreach ($count->lines as $line) {
                $variance = $line->counted_quantity - $this->stock->balance($warehouse->id, $line->product_id, $line->status);

                if ($variance !== 0) {
                    $this->stock->move($warehouse, $line->product_id, $line->status, $variance, 'count_variance', [
                        'source' => $count, 'reference' => $count->number, 'user_id' => $actor->id,
                        'idempotency_key' => "cnt:{$count->id}:{$line->id}",
                    ]);
                }

                $line->update(['posted_variance' => $variance]);
                $variances[] = ['product_id' => $line->product_id, 'status' => $line->status, 'variance' => $variance];
            }

            $this->posting->countVariances($count, $variances, $actor);

            $count->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
        });

        return $this->show($id);
    }

    public function cancel(int $id): JsonResponse
    {
        $count = StockCount::findOrFail($id);

        abort_unless(in_array($count->status, ['counting', 'submitted'], true), 422, 'This count can no longer be cancelled.');

        $count->update(['status' => 'cancelled']);

        return $this->show($id);
    }
}
