<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\Warehouse;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalePayment;
use App\Modules\Sales\Services\SaleService;
use App\Support\Tenancy\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SaleController extends Controller
{
    private const WITH = ['customer:id,name,phone', 'warehouse:id,name', 'branch:id,name', 'creator:id,name'];

    public function __construct(private AccessScope $access, private SaleService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rows = $this->filtered($request)
            ->with(self::WITH)->withCount('lines')
            ->orderByDesc('sold_at')->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    /** Headline figures for the same filters as the list, ignoring status so voids are counted separately. */
    public function summary(Request $request): JsonResponse
    {
        $base = $this->filtered($request, withStatus: false);

        $done = (clone $base)->where('status', 'completed');

        return response()->json([
            'orders' => (clone $base)->count(),
            'completed' => (clone $done)->count(),
            'voided' => (clone $base)->where('status', 'void')->count(),
            'unpaid' => (clone $done)->where('balance_due', '>', 0)->count(),
            'sales' => (int) (clone $done)->sum('total'),
            'collected' => (int) (clone $done)->sum('amount_paid'),
            'outstanding' => (int) (clone $done)->sum('balance_due'),
            'discounts' => (int) (clone $done)->sum('discount_total'),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->load($id));
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('store_id', $storeId)],
            'channel' => ['sometimes', Rule::in(Sale::CHANNELS)],
            'note' => ['nullable', 'string', 'max:500'],
            'client_ref' => ['nullable', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.unit_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_price' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'lines.*.mix_layout_id' => ['nullable', 'integer', 'required_with:lines.*.mix_group'], 'lines.*.mix_group' => ['nullable', 'string', 'max:20'],
            'payments' => ['sometimes', 'array', 'max:5'],
            'empties_returned' => ['sometimes', 'array', 'max:20'],
            'empties_returned.*.container_type_id' => ['required', 'integer'],
            'empties_returned.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'payments.*.method' => ['required', Rule::in(SalePayment::METHODS)],
            'payments.*.amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'payments.*.reference' => ['nullable', 'string', 'max:80'], 'payments.*.pos_terminal_id' => ['nullable', 'integer'],
        ]);

        $sale = $this->service->create($data, $request->user());

        return response()->json($this->load($sale->id), 201);
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(SalePayment::METHODS)],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'reference' => ['nullable', 'string', 'max:80'],
            'pos_terminal_id' => ['nullable', 'integer'],
        ]);

        $this->visible()->findOrFail($id);
        $this->service->addPayment($id, $data, $request->user());

        return response()->json($this->load($id));
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->visible()->findOrFail($id);
        $this->service->void($id, $data['reason'], $request->user());

        return response()->json($this->load($id));
    }

    private function load(int $id): Sale
    {
        return $this->visible()->with([
            ...self::WITH, 'lines.product:id,name,sku,size_label', 'lines.unit:id,name',
            'payments.receiver:id,name', 'payments.terminal:id,name',
        ])->findOrFail($id);
    }

    /** Salespeople see only the sales they made themselves. */
    private function visible(): Builder
    {
        $user = request()->user();

        return Sale::query()->when(Warehouse::vanOf($user->id), fn ($q) => $q->where('created_by', $user->id));
    }

    private function filtered(Request $request, bool $withStatus = true): Builder
    {
        return $this->visible()
            ->when($withStatus && $request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('payment_status'), fn ($q, $v) => $q->where('payment_status', $v)->where('status', 'completed'))
            ->when($request->query('customer_id'), fn ($q, $v) => $q->where('customer_id', $v))
            ->when($request->query('warehouse_id'), fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($request->query('branch_id'), fn ($q, $v) => $q->where('branch_id', $v))
            ->when($request->query('channel'), fn ($q, $v) => $q->where('channel', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('sold_at', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('sold_at', '<=', $v.' 23:59:59'))
            ->when($request->query('search'), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))
            ));
    }
}
