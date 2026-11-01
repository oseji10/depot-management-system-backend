<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\SaleReturnLine;
use App\Modules\Sales\Services\ReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SaleReturnController extends Controller
{
    private const WITH = ['sale:id,number,sold_at', 'customer:id,name,phone', 'creator:id,name', 'lines.product:id,name,sku', 'lines.unit:id,name'];

    public function __construct(private ReturnService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rows = SaleReturn::query()->with(['sale:id,number', 'customer:id,name', 'creator:id,name'])
            ->when($request->query('sale_id'), fn ($q, $v) => $q->where('sale_id', $v))
            ->when($request->query('customer_id'), fn ($q, $v) => $q->where('customer_id', $v))
            ->when($request->query('search'), fn ($q, $s) => $q->where('number', 'like', "%{$s}%"))
            ->orderByDesc('returned_at')->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(SaleReturn::with(self::WITH)->findOrFail($id));
    }

    /** What is still returnable on each line of a sale. */
    public function returnable(int $saleId): JsonResponse
    {
        $sale = Sale::with(['lines.product:id,name,sku', 'lines.unit:id,name'])->findOrFail($saleId);
        $done = SaleReturnLine::whereIn('sale_line_id', $sale->lines->pluck('id'))->groupBy('sale_line_id')->selectRaw('sale_line_id, SUM(quantity) AS qty')->pluck('qty', 'sale_line_id');

        return response()->json(['data' => $sale->lines->map(fn ($l) => [
            'sale_line_id' => $l->id, 'product' => $l->product?->name, 'unit' => $l->unit?->name, 'factor' => $l->factor,
            'unit_price' => $l->unit_price, 'sold' => $l->quantity, 'returned' => (int) ($done[$l->id] ?? 0), 'left' => $l->quantity - (int) ($done[$l->id] ?? 0),
        ])->values()]);
    }

    public function store(Request $request, int $saleId): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'restock_status' => ['sometimes', Rule::in(['saleable', 'damaged'])],
            'refund_method' => ['nullable', Rule::in(['cash', 'transfer'])],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.sale_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        Sale::findOrFail($saleId);
        $return = $this->service->create($saleId, $data, $request->user());

        return response()->json($return->load(self::WITH), 201);
    }
}
