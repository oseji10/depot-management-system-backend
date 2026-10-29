<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Modules\Catalogue\Models\Product;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\DocumentNumber;
use App\Modules\Organization\Models\Warehouse;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\GoodsReceiptLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Services\GoodsReceiptService;
use App\Modules\Procurement\Support\UnitResolver;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GoodsReceiptController extends Controller
{
    private const WITH = ['supplier:id,name,is_manufacturer', 'warehouse:id,name', 'purchaseOrder:id,number', 'creator:id,name', 'poster:id,name'];

    public function __construct(private AccessScope $access, private GoodsReceiptService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rows = GoodsReceipt::query()
            ->with(self::WITH)->withCount('lines')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('supplier_id'), fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($request->query('warehouse_id'), fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('received_on', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('received_on', '<=', $v))
            ->when($request->query('search'), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhere('supplier_reference', 'like', "%{$s}%")
            ))
            ->orderByDesc('received_on')->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(GoodsReceipt::with([
            ...self::WITH,
            'lines.product:id,name,sku,base_unit_id,tracks_expiry', 'lines.product.baseUnit:id,name', 'lines.product.units.unit:id,name', 'lines.unit:id,name',
        ])->findOrFail($id));
    }

    public function store(Request $request, DocumentNumber $numbers): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        [$data, $lines, $warehouse] = $this->prepare($request, $storeId);

        $receipt = DB::transaction(function () use ($data, $lines, $warehouse, $numbers, $request) {
            $receipt = GoodsReceipt::create([
                'store_id' => $warehouse->store_id, 'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id,
                'supplier_id' => $data['supplier_id'], 'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'number' => $numbers->next($warehouse->store_id, 'GRN'), 'supplier_reference' => $data['supplier_reference'] ?? null,
                'received_on' => $data['received_on'], 'vehicle_no' => $data['vehicle_no'] ?? null, 'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($lines as $line) {
                $receipt->lines()->create([...$line, 'store_id' => $warehouse->store_id]);
            }

            return $receipt;
        });

        return response()->json($receipt->load(self::WITH), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $receipt = GoodsReceipt::findOrFail($id);
        abort_unless($receipt->status === 'draft', 422, 'A posted receipt cannot be edited. Reverse it and record a new one.');

        [$data, $lines, $warehouse] = $this->prepare($request, $receipt->store_id, $receipt);

        DB::transaction(function () use ($receipt, $data, $lines, $warehouse) {
            $receipt->update([
                'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id, 'supplier_id' => $data['supplier_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null, 'supplier_reference' => $data['supplier_reference'] ?? null,
                'received_on' => $data['received_on'], 'vehicle_no' => $data['vehicle_no'] ?? null, 'note' => $data['note'] ?? null,
            ]);

            $receipt->lines()->delete();

            foreach ($lines as $line) {
                $receipt->lines()->create([...$line, 'store_id' => $receipt->store_id]);
            }
        });

        return $this->show($id);
    }

    public function destroy(int $id): JsonResponse
    {
        $receipt = GoodsReceipt::findOrFail($id);
        abort_unless($receipt->status === 'draft', 422, 'Only a draft receipt can be deleted.');

        $receipt->delete();

        return response()->json(null, 204);
    }

    public function post(Request $request, int $id): JsonResponse
    {
        $this->service->post($id, $request->user());

        return $this->show($id);
    }

    public function reverse(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->service->reverse($id, $request->user(), $data['reason']);

        return $this->show($id);
    }

    /** Posted lines where goods arrived damaged or were rejected: the basis for supplier claims. */
    public function discrepancies(Request $request): JsonResponse
    {
        $rows = GoodsReceiptLine::query()
            ->with(['product:id,name,sku', 'unit:id,name', 'receipt:id,number,received_on,supplier_reference,supplier_id', 'receipt.supplier:id,name'])
            ->whereHas('receipt', fn ($q) => $q->where('status', 'posted')
                ->when($request->query('supplier_id'), fn ($w, $v) => $w->where('supplier_id', $v))
                ->when($request->query('from'), fn ($w, $v) => $w->where('received_on', '>=', $v))
                ->when($request->query('to'), fn ($w, $v) => $w->where('received_on', '<=', $v)))
            ->where(fn ($q) => $q->where('damaged', '>', 0)->orWhere('rejected', '>', 0))
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    /** @return array{0: array, 1: array, 2: Warehouse} */
    private function prepare(Request $request, int $storeId, ?GoodsReceipt $current = null): array
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('store_id', $storeId)->where('is_active', true)],
            'warehouse_id' => ['required', 'integer'],
            'purchase_order_id' => ['nullable', 'integer', Rule::exists('purchase_orders', 'id')->where('store_id', $storeId)],
            'supplier_reference' => ['nullable', 'string', 'max:60'],
            'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:tomorrow'],
            'vehicle_no' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.purchase_order_line_id' => ['nullable', 'integer'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('store_id', $storeId)],
            'lines.*.unit_id' => ['required', 'integer'],
            'lines.*.delivered' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.accepted' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'lines.*.damaged' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'lines.*.rejected' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'lines.*.reason' => ['nullable', 'string', 'max:255'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:40'],
            'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $tracked = Product::whereIn('id', collect($data['lines'])->pluck('product_id'))->where('tracks_expiry', true)->pluck('id')->all();

        $warehouse = Warehouse::findOrFail($data['warehouse_id']);

        $this->assertNotDuplicate($storeId, $data, $current);
        $this->assertPurchaseOrderMatches($data);

        $lines = collect($data['lines'])->map(function ($l, $i) use ($tracked) {
            $damaged = $l['damaged'] ?? 0;
            $rejected = $l['rejected'] ?? 0;
            $accepted = $l['delivered'] - $damaged - $rejected;

            if ($accepted < 0 || (isset($l['accepted']) && (int) $l['accepted'] !== $accepted)) {
                throw ValidationException::withMessages(["lines.$i.delivered" => 'Delivered must equal accepted + damaged + rejected.']);
            }

            if (($damaged + $rejected) > 0 && blank($l['reason'] ?? null)) {
                throw ValidationException::withMessages(["lines.$i.reason" => 'Say why goods were damaged or rejected.']);
            }

            $isTracked = in_array($l['product_id'], $tracked, true);

            if ($isTracked && $accepted > 0 && blank($l['expiry_date'] ?? null)) {
                throw ValidationException::withMessages(["lines.$i.expiry_date" => 'This product is expiry-tracked. Enter the expiry date printed on the goods.']);
            }

            if ($isTracked && ! blank($l['expiry_date'] ?? null) && $l['expiry_date'] < now()->toDateString() && $accepted > 0) {
                throw ValidationException::withMessages(["lines.$i.expiry_date" => 'That date has already passed. Record expired goods as rejected or damaged.']);
            }

            return [
                'batch_no' => $isTracked ? ($l['batch_no'] ?? null) : null, 'expiry_date' => $isTracked ? ($l['expiry_date'] ?? null) : null,
                'purchase_order_line_id' => $l['purchase_order_line_id'] ?? null, 'product_id' => $l['product_id'], 'unit_id' => $l['unit_id'],
                'factor' => UnitResolver::factor($l['product_id'], $l['unit_id'], $i),
                'delivered' => $l['delivered'], 'accepted' => $accepted, 'damaged' => $damaged, 'rejected' => $rejected,
                'unit_cost' => $l['unit_cost'], 'reason' => $l['reason'] ?? null,
            ];
        })->all();

        return [$data, $lines, $warehouse];
    }

    /** One supplier delivery note, one receipt. Checked store-wide, not just within the user's branch. */
    private function assertNotDuplicate(int $storeId, array $data, ?GoodsReceipt $current): void
    {
        if (blank($data['supplier_reference'] ?? null)) {
            return;
        }

        $exists = GoodsReceipt::withoutGlobalScopes()->where('store_id', $storeId)
            ->where('supplier_id', $data['supplier_id'])->where('supplier_reference', $data['supplier_reference'])
            ->when($current, fn ($q) => $q->whereKeyNot($current->id))->exists();

        if ($exists) {
            throw ValidationException::withMessages(['supplier_reference' => 'A receipt with this supplier reference has already been recorded.']);
        }
    }

    private function assertPurchaseOrderMatches(array $data): void
    {
        $poId = $data['purchase_order_id'] ?? null;
        $poLineIds = collect($data['lines'])->pluck('purchase_order_line_id')->filter();

        if ($poId === null) {
            if ($poLineIds->isNotEmpty()) {
                throw ValidationException::withMessages(['purchase_order_id' => 'Choose the purchase order these lines belong to.']);
            }

            return;
        }

        $po = PurchaseOrder::findOrFail($poId);

        if (! in_array($po->status, ['ordered', 'partially_received'], true)) {
            throw ValidationException::withMessages(['purchase_order_id' => 'This purchase order is not open for receiving.']);
        }

        if ($po->supplier_id !== (int) $data['supplier_id']) {
            throw ValidationException::withMessages(['supplier_id' => 'This purchase order belongs to a different supplier.']);
        }

        foreach ($data['lines'] as $i => $line) {
            $lineId = $line['purchase_order_line_id'] ?? null;

            if ($lineId !== null && ! $po->lines()->whereKey($lineId)->where('product_id', $line['product_id'])->exists()) {
                throw ValidationException::withMessages(["lines.$i.purchase_order_line_id" => 'This line is not on the purchase order.']);
            }
        }
    }
}
