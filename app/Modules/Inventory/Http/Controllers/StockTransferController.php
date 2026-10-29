<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\InventoryPosting;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Services\DocumentNumber;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Support\StockStatus;
use App\Modules\Organization\Models\Warehouse;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** A transfer posts immediately: stock leaves one warehouse and arrives in the other in a single transaction. */
class StockTransferController extends Controller
{
    private const WITH = ['fromWarehouse:id,name', 'toWarehouse:id,name', 'creator:id,name'];

    public function __construct(private AccessScope $access, private StockService $stock, private InventoryPosting $posting)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rows = StockTransfer::query()
            ->with(self::WITH)->withCount('lines')
            ->when($this->access->branchId(), fn ($q, $b) => $q->where(
                fn ($w) => $w->where('from_branch_id', $b)->orWhere('to_branch_id', $b)
            ))
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->visible()->with([...self::WITH, 'lines.product:id,name,sku'])->findOrFail($id));
    }

    public function store(Request $request, DocumentNumber $numbers): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        $data = $request->validate([
            'from_warehouse_id' => ['required', 'integer'],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->where('store_id', $storeId)],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        // Both lookups are scoped: a branch-level user cannot send to or from a warehouse outside their branch.
        $from = Warehouse::findOrFail($data['from_warehouse_id']);
        $to = Warehouse::findOrFail($data['to_warehouse_id']);

        if (! $from->is_active || ! $to->is_active) {
            throw ValidationException::withMessages(['to_warehouse_id' => 'Both warehouses must be active.']);
        }

        $transfer = DB::transaction(function () use ($data, $from, $to, $numbers, $request) {
            $transfer = StockTransfer::create([
                'store_id' => $from->store_id, 'number' => $numbers->next($from->store_id, 'TRF'),
                'from_branch_id' => $from->branch_id, 'to_branch_id' => $to->branch_id,
                'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id,
                'note' => $data['note'] ?? null, 'created_by' => $request->user()->id, 'posted_at' => now(),
            ]);

            foreach ($data['lines'] as $line) {
                $transfer->lines()->create(['store_id' => $from->store_id, 'product_id' => $line['product_id'], 'quantity' => $line['quantity']]);

                $meta = ['source' => $transfer, 'reference' => $transfer->number, 'note' => $transfer->note];

                $this->stock->move($from, $line['product_id'], StockStatus::SALEABLE, -$line['quantity'], 'transfer_out',
                    [...$meta, 'idempotency_key' => "trf:{$transfer->id}:{$line['product_id']}:out"]);
                $this->stock->move($to, $line['product_id'], StockStatus::SALEABLE, $line['quantity'], 'transfer_in',
                    [...$meta, 'idempotency_key' => "trf:{$transfer->id}:{$line['product_id']}:in"]);
            }

            $this->posting->transfer($transfer->load('lines'), $from, $to, $request->user());

            return $transfer;
        });

        return response()->json($transfer->load(self::WITH), 201);
    }

    private function visible()
    {
        return StockTransfer::query()->when($this->access->branchId(), fn ($q, $b) => $q->where(
            fn ($w) => $w->where('from_branch_id', $b)->orWhere('to_branch_id', $b)
        ));
    }
}
