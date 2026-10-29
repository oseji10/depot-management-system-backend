<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\DocumentNumber;
use App\Modules\Organization\Models\Warehouse;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Services\PurchaseOrderProgress;
use App\Modules\Procurement\Support\UnitResolver;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** An order is a request to a supplier. It never touches stock; only a posted goods receipt does. */
class PurchaseOrderController extends Controller
{
    private const WITH = ['supplier:id,name', 'warehouse:id,name', 'creator:id,name'];

    public function __construct(private AccessScope $access, private PurchaseOrderProgress $progress)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rows = PurchaseOrder::query()
            ->with(self::WITH)->withCount('lines')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('supplier_id'), fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($request->query('search'), fn ($q, $s) => $q->where('number', 'like', "%{$s}%"))
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    public function show(int $id): JsonResponse
    {
        $po = PurchaseOrder::with([
            ...self::WITH,
            'lines.product:id,name,sku,base_unit_id,tracks_expiry', 'lines.product.baseUnit:id,name', 'lines.product.units.unit:id,name', 'lines.unit:id,name',
        ])->findOrFail($id);
        $received = $this->progress->received($po);

        $po->lines->each(function ($l) use ($received) {
            $base = $l->quantity * $l->factor;
            $l->setAttribute('quantity_base', $base);
            $l->setAttribute('received_base', $received[$l->id] ?? 0);
            $l->setAttribute('outstanding_base', max(0, $base - ($received[$l->id] ?? 0)));
        });
        $po->setAttribute('total_value', number_format($po->lines->sum(fn ($l) => $l->quantity * (float) $l->unit_cost), 2, '.', ''));

        return response()->json($po);
    }

    public function store(Request $request, DocumentNumber $numbers): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $request->validate($this->rules($storeId));
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        $lines = $this->resolveLines($data['lines']);

        $po = DB::transaction(function () use ($data, $warehouse, $lines, $numbers, $request) {
            $po = PurchaseOrder::create([
                'store_id' => $warehouse->store_id, 'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id,
                'supplier_id' => $data['supplier_id'], 'number' => $numbers->next($warehouse->store_id, 'PO'),
                'order_date' => $data['order_date'], 'expected_date' => $data['expected_date'] ?? null,
                'note' => $data['note'] ?? null, 'created_by' => $request->user()->id,
            ]);

            foreach ($lines as $line) {
                $po->lines()->create([...$line, 'store_id' => $warehouse->store_id]);
            }

            return $po;
        });

        return response()->json($po->load(self::WITH), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $po = PurchaseOrder::findOrFail($id);
        abort_unless($po->status === 'draft', 422, 'Only a draft order can be edited.');

        $data = $request->validate($this->rules($po->store_id));
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        $lines = $this->resolveLines($data['lines']);

        DB::transaction(function () use ($po, $data, $warehouse, $lines) {
            $po->update([
                'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id, 'supplier_id' => $data['supplier_id'],
                'order_date' => $data['order_date'], 'expected_date' => $data['expected_date'] ?? null, 'note' => $data['note'] ?? null,
            ]);

            $po->lines()->delete();

            foreach ($lines as $line) {
                $po->lines()->create([...$line, 'store_id' => $po->store_id]);
            }
        });

        return $this->show($id);
    }

    public function issue(int $id): JsonResponse
    {
        $po = PurchaseOrder::findOrFail($id);
        abort_unless($po->status === 'draft', 422, 'Only a draft order can be issued.');

        $po->update(['status' => 'ordered']);

        return $this->show($id);
    }

    public function cancel(int $id): JsonResponse
    {
        $po = PurchaseOrder::findOrFail($id);
        abort_unless(in_array($po->status, ['draft', 'ordered'], true), 422, 'Goods have already been received against this order.');

        $po->update(['status' => 'cancelled']);

        return $this->show($id);
    }

    /** Outstanding lines, ready to pre-fill a goods receipt. */
    public function receiptTemplate(int $id): JsonResponse
    {
        $po = PurchaseOrder::with(['lines.product:id,name,sku,tracks_expiry', 'lines.unit:id,name'])->findOrFail($id);

        abort_unless(in_array($po->status, ['ordered', 'partially_received'], true), 422, 'This order is not open for receiving.');

        $received = $this->progress->received($po);

        $lines = $po->lines->map(function ($l) use ($received) {
            $outstanding = max(0, $l->quantity * $l->factor - ($received[$l->id] ?? 0));

            return [
                'purchase_order_line_id' => $l->id, 'product' => $l->product, 'unit' => $l->unit, 'unit_id' => $l->unit_id,
                'factor' => $l->factor, 'outstanding' => (int) ceil($outstanding / $l->factor), 'unit_cost' => $l->unit_cost,
            ];
        })->filter(fn ($l) => $l['outstanding'] > 0)->values();

        return response()->json([
            'purchase_order' => $po->only(['id', 'number', 'supplier_id', 'warehouse_id']),
            'lines' => $lines,
        ]);
    }

    /** Frozen at order time so later pack-size edits never rewrite history. */
    private function resolveLines(array $lines): array
    {
        return collect($lines)->map(fn ($l, $i) => [
            'product_id' => $l['product_id'], 'unit_id' => $l['unit_id'], 'quantity' => $l['quantity'], 'unit_cost' => $l['unit_cost'],
            'factor' => UnitResolver::factor($l['product_id'], $l['unit_id'], $i),
        ])->all();
    }

    private function rules(int $storeId): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('store_id', $storeId)->where('is_active', true)],
            'warehouse_id' => ['required', 'integer'],
            'order_date' => ['required', 'date_format:Y-m-d'],
            'expected_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('store_id', $storeId)],
            'lines.*.unit_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }
}
