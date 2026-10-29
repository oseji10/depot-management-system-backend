<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\InventoryPosting;
use App\Modules\Inventory\Models\StockAdjustment;
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
use Illuminate\Validation\ValidationException;

class StockAdjustmentController extends Controller
{
    private const WITH = ['warehouse:id,name', 'creator:id,name', 'approver:id,name'];

    public function __construct(private AccessScope $access, private StockService $stock, private InventoryPosting $posting)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rows = StockAdjustment::query()
            ->with(self::WITH)->withCount('lines')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('warehouse_id'), fn ($q, $v) => $q->where('warehouse_id', $v))
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(StockAdjustment::with([...self::WITH, 'lines.product:id,name,sku'])->findOrFail($id));
    }

    public function store(Request $request, DocumentNumber $numbers): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'kind' => ['required', Rule::in(array_keys(StockAdjustment::REASONS))],
            'reason' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('store_id', $storeId)],
            'lines.*.status' => ['required', Rule::in(StockStatus::ALL)],
            'lines.*.to_status' => ['nullable', Rule::in(StockStatus::ALL)],
            'lines.*.quantity' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            // Cost per base unit (naira) for stock being added. Required for opening balances.
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            // Expiry tracking: stock being added says which batch it is; stock being removed may name the exact batch.
            'lines.*.batch_no' => ['nullable', 'string', 'max:40'],
            'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.stock_batch_id' => ['nullable', 'integer', Rule::exists('stock_batches', 'id')->where('store_id', $storeId)],
        ]);

        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        $this->assertLinesMakeSense($data);

        $adjustment = DB::transaction(function () use ($data, $warehouse, $numbers, $request) {
            $adjustment = StockAdjustment::create([
                'store_id' => $warehouse->store_id, 'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id,
                'number' => $numbers->next($warehouse->store_id, 'ADJ'),
                'kind' => $data['kind'], 'reason' => $data['reason'], 'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['lines'] as $line) {
                $adjustment->lines()->create([
                    'store_id' => $warehouse->store_id, 'product_id' => $line['product_id'], 'status' => $line['status'],
                    'to_status' => $line['to_status'] ?? null, 'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'] ?? null, 'batch_no' => $line['batch_no'] ?? null,
                    'expiry_date' => $line['expiry_date'] ?? null, 'stock_batch_id' => $line['stock_batch_id'] ?? null,
                ]);
            }

            return $adjustment;
        });

        return response()->json($adjustment->load(self::WITH), 201);
    }

    /** Posts the ledger movements. All or nothing: if any line lacks stock, nothing is posted. */
    public function approve(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        DB::transaction(function () use ($id, $actor) {
            $adjustment = StockAdjustment::with('lines')->lockForUpdate()->findOrFail($id);

            abort_unless($adjustment->status === 'pending', 422, 'This adjustment has already been decided.');
            Approvals::assertCanDecide($actor, $adjustment->created_by);

            $warehouse = Warehouse::findOrFail($adjustment->warehouse_id);
            $type = $adjustment->reason === 'opening_balance' ? 'opening' : $adjustment->kind;

            foreach ($adjustment->lines as $line) {
                $meta = fn (string $part) => [
                    'source' => $adjustment, 'reference' => $adjustment->number, 'note' => $adjustment->note,
                    'idempotency_key' => "adj:{$adjustment->id}:{$line->id}:{$part}", 'user_id' => $actor->id,
                ];

                $out = $line->stock_batch_id ? ['batches' => [['batch_id' => $line->stock_batch_id]]] : [];
                $in = $line->expiry_date ? ['batches' => [['batch_no' => $line->batch_no, 'expiry_date' => $line->expiry_date, 'quantity' => abs($line->quantity)]]] : [];

                if ($adjustment->kind === 'reclassify') {
                    $this->stock->move($warehouse, $line->product_id, $line->status, -$line->quantity, $type, [...$meta('out'), ...$out]);
                    $this->stock->move($warehouse, $line->product_id, $line->to_status, $line->quantity, $type, $meta('in'));
                } else {
                    $this->stock->move($warehouse, $line->product_id, $line->status, $line->quantity, $type, [...$meta('move'), ...($line->quantity < 0 ? $out : $in)]);
                }
            }

            $this->posting->adjustment($adjustment, $actor);

            $adjustment->update(['status' => 'approved', 'approved_by' => $actor->id, 'decided_at' => now()]);
        });

        return $this->show($id);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['decision_note' => ['required', 'string', 'max:255']]);

        DB::transaction(function () use ($id, $request, $data) {
            $adjustment = StockAdjustment::lockForUpdate()->findOrFail($id);

            abort_unless($adjustment->status === 'pending', 422, 'This adjustment has already been decided.');

            $adjustment->update([
                'status' => 'rejected', 'approved_by' => $request->user()->id,
                'decided_at' => now(), 'decision_note' => $data['decision_note'],
            ]);
        });

        return $this->show($id);
    }

    private function assertLinesMakeSense(array $data): void
    {
        $rule = StockAdjustment::REASONS[$data['kind']][$data['reason']] ?? null;

        if ($rule === null) {
            throw ValidationException::withMessages(['reason' => 'That reason does not apply to this kind of adjustment.']);
        }

        $seen = [];

        foreach ($data['lines'] as $i => $line) {
            $key = $line['product_id'].':'.$line['status'];

            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["lines.$i.product_id" => 'The same product and status appears twice.']);
            }
            $seen[$key] = true;

            if (! empty($line['stock_batch_id']) && $line['status'] === StockStatus::SALEABLE && $line['quantity'] > 0 && $data['kind'] !== 'reclassify') {
                throw ValidationException::withMessages(["lines.$i.stock_batch_id" => 'A batch can only be named when removing stock.']);
            }

            if ($data['reason'] === 'opening_balance' && $line['status'] === StockStatus::SALEABLE && ! isset($line['unit_cost'])) {
                throw ValidationException::withMessages(["lines.$i.unit_cost" => 'Enter what each unit cost, so the opening stock can be valued.']);
            }

            if ($data['kind'] === 'reclassify') {
                if (empty($line['to_status']) || $line['to_status'] === $line['status'] || $line['quantity'] < 1) {
                    throw ValidationException::withMessages(["lines.$i.to_status" => 'Choose a different status to move stock to, with a positive quantity.']);
                }
            } elseif (! empty($line['to_status'])) {
                throw ValidationException::withMessages(["lines.$i.to_status" => 'Only reclassifications move stock between statuses.']);
            } elseif (($rule === 'positive' && $line['quantity'] < 0) || ($rule === 'negative' && $line['quantity'] > 0)) {
                throw ValidationException::withMessages(["lines.$i.quantity" => 'This reason '.($rule === 'positive' ? 'adds' : 'removes').' stock, so the quantity must be '.($rule === 'positive' ? 'positive' : 'negative').'.']);
            }
        }
    }
}
