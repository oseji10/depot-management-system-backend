<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\Product;
use App\Modules\Catalogue\Models\ProductUnit;
use App\Modules\Catalogue\Services\PriceResolver;
use App\Modules\Inventory\Services\DocumentNumber;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Proforma;
use App\Modules\Sales\Models\SalePayment;
use App\Modules\Sales\Services\SaleService;
use App\Support\Money;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Proforma invoices: price quotations that can be sent to a customer and, once accepted, turned into a sale. */
class ProformaController extends Controller
{
    private const OPEN = ['draft', 'sent', 'accepted'];

    public function __construct(private AccessScope $access, private PriceResolver $prices)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $today = now()->toDateString();

        $rows = Proforma::query()->withCount('lines')
            ->when($request->query('status') === 'expired', fn ($q) => $q->whereIn('status', self::OPEN)->whereNotNull('valid_until')->where('valid_until', '<', $today))
            ->when($request->query('status') && $request->query('status') !== 'expired', fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhere('customer_name', 'like', "%{$s}%")->orWhere('customer_phone', 'like', "%{$s}%")))
            ->orderByDesc('issued_on')->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Proforma::with(['lines', 'customer:id,name,phone,email,address'])->findOrFail($id));
    }

    /** Active products with the unit prices in force today, for the quote builder. */
    public function catalogue(): JsonResponse
    {
        $effective = $this->prices->effective()->keyBy('product_unit_id');

        $products = Product::where('is_active', true)->with(['units' => fn ($q) => $q->where('is_active', true)->orderBy('factor'), 'units.unit:id,name'])->orderBy('name')->get();

        $rows = $products->map(fn (Product $p) => [
            'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'size_label' => $p->size_label,
            'units' => $p->units->filter(fn ($u) => isset($effective[$u->id]))->map(fn ($u) => [
                'unit_id' => $u->unit_id, 'name' => $u->unit?->name, 'factor' => $u->factor, 'price' => Money::kobo($effective[$u->id]['price']),
            ])->values(),
        ])->filter(fn ($p) => $p['units']->isNotEmpty())->values();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request, DocumentNumber $numbers): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        [$data, $lines, $totals] = $this->prepare($request, $storeId);

        $proforma = DB::transaction(function () use ($data, $lines, $totals, $numbers, $request, $storeId) {
            $proforma = Proforma::create([
                ...$data, ...$totals, 'store_id' => $storeId, 'branch_id' => $request->user()->branch_id, 'number' => $numbers->next($storeId, 'PRO'),
                'issued_on' => $data['issued_on'] ?? now()->toDateString(), 'status' => 'draft', 'created_by' => $request->user()->id,
            ]);

            foreach ($lines as $line) {
                $proforma->lines()->create([...$line, 'store_id' => $storeId]);
            }

            return $proforma;
        });

        return response()->json($proforma->load('lines'), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $proforma = Proforma::findOrFail($id);
        abort_unless(in_array($proforma->status, self::OPEN, true), 422, 'This proforma can no longer be edited.');

        [$data, $lines, $totals] = $this->prepare($request, $proforma->store_id);

        DB::transaction(function () use ($proforma, $data, $lines, $totals) {
            $proforma->update([...$data, ...$totals]);
            $proforma->lines()->delete();

            foreach ($lines as $line) {
                $proforma->lines()->create([...$line, 'store_id' => $proforma->store_id]);
            }
        });

        return $this->show($id);
    }

    /** draft -> sent -> accepted, or cancelled; a cancelled one can be reopened as a draft. */
    public function setStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['draft', 'sent', 'accepted', 'cancelled'])]]);
        $proforma = Proforma::findOrFail($id);

        abort_if($proforma->status === 'converted', 422, 'This proforma has already become a sale.');
        $proforma->update(['status' => $data['status']]);

        return $this->show($id);
    }

    /**
     * Turn the quote into a sale from a warehouse. With no payments it is a credit sale (the customer must be on file); with
     * payments, the same rules as the till apply. The sale uses the quoted prices exactly, even when they are above the list (rebate partners).
     */
    public function convert(Request $request, int $id, SaleService $sales): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'payments' => ['sometimes', 'array', 'max:5'],
            'payments.*.method' => ['required', Rule::in(SalePayment::METHODS)],
            'payments.*.amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'payments.*.reference' => ['nullable', 'string', 'max:80'], 'payments.*.pos_terminal_id' => ['nullable', 'integer'],
            'payments.*.transfer_to' => ['nullable', Rule::in(['pos', 'account'])], 'payments.*.account_id' => ['nullable', 'integer'],
        ]);

        $proforma = Proforma::with('lines')->findOrFail($id);
        abort_unless(in_array($proforma->status, self::OPEN, true), 422, 'Only an open proforma can become a sale.');
        abort_if($proforma->lines->contains(fn ($l) => $l->product_id === null), 422, 'A product on this proforma no longer exists. Edit the proforma first.');

        $effective = $this->prices->effective()->keyBy('product_unit_id');
        $units = ProductUnit::whereIn('product_id', $proforma->lines->pluck('product_id'))->get()->groupBy('product_id');

        $lines = $proforma->lines->map(function ($l) use ($effective, $units) {
            $unit = ($units[$l->product_id] ?? collect())->firstWhere('unit_id', $l->unit_id);
            $list = $unit && isset($effective[$unit->id]) ? Money::kobo($effective[$unit->id]['price']) : null;

            return ['product_id' => $l->product_id, 'unit_id' => $l->unit_id, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price];
        })->values()->all();

        if (empty($data['payments']) && $proforma->customer_id === null) {
            throw ValidationException::withMessages(['customer_id' => 'Choose a customer on the proforma first, or take payment now. A sale with nothing paid must be on credit for a known customer.']);
        }

        $sale = $sales->create([
            'warehouse_id' => $data['warehouse_id'], 'customer_id' => $proforma->customer_id, 'channel' => 'counter',
            'note' => 'From proforma '.$proforma->number, 'lines' => $lines, 'payments' => $data['payments'] ?? [],
        ], $request->user(), ['honour_quote' => true]);

        $proforma->update(['status' => 'converted', 'converted_sale_id' => $sale->id]);

        return response()->json(['sale_id' => $sale->id, 'proforma' => $proforma->fresh('lines')]);
    }

    public function destroy(int $id): JsonResponse
    {
        $proforma = Proforma::findOrFail($id);
        abort_if($proforma->status === 'converted', 422, 'A proforma that became a sale is kept for the record. Cancel it instead.');

        $proforma->delete();

        return response()->json(null, 204);
    }

    /** @return array{0: array, 1: array, 2: array} */
    private function prepare(Request $request, int $storeId): array
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('store_id', $storeId)],
            'customer_name' => ['nullable', 'string', 'max:150', 'required_without:customer_id'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:150'],
            'customer_address' => ['nullable', 'string', 'max:255'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'discount' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'delivery_fee' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.unit_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0', 'max:100000000000'],
        ]);

        if (! empty($data['customer_id'])) {
            $customer = Customer::findOrFail($data['customer_id']);
            $data['customer_name'] = $data['customer_name'] ?: $customer->name;
            $data['customer_phone'] = $data['customer_phone'] ?? $customer->phone;
            $data['customer_email'] = $data['customer_email'] ?? $customer->email;
            $data['customer_address'] = $data['customer_address'] ?? $customer->address;
        }

        $products = Product::where('is_active', true)->whereIn('id', array_column($data['lines'], 'product_id'))->get()->keyBy('id');
        $units = ProductUnit::where('is_active', true)->whereIn('product_id', $products->keys())->with('unit:id,name')->get()->groupBy('product_id');
        $effective = $this->prices->effective()->keyBy('product_unit_id');
        $actor = $request->user();
        $lines = [];

        foreach ($data['lines'] as $i => $l) {
            $product = $products[$l['product_id']] ?? throw ValidationException::withMessages(["lines.$i.product_id" => 'This product is not available.']);
            $unit = ($units[$product->id] ?? collect())->firstWhere('unit_id', $l['unit_id'])
                ?? throw ValidationException::withMessages(["lines.$i.unit_id" => "{$product->name} is not sold in that unit."]);
            $list = isset($effective[$unit->id]) ? Money::kobo($effective[$unit->id]['price']) : throw ValidationException::withMessages(["lines.$i.unit_id" => "{$product->name} has no price for that unit. Set it in a price list."]);

            if ($l['unit_price'] < $list && ! $actor->can('sales.discount')) {
                throw ValidationException::withMessages(["lines.$i.unit_price" => 'You do not have permission to quote below the list price.']);
            }

            $lines[] = [
                'product_id' => $product->id, 'unit_id' => $l['unit_id'], 'factor' => $unit->factor, 'quantity' => $l['quantity'],
                'description' => trim($product->name.($product->size_label ? ' '.$product->size_label : '').' · '.($unit->unit?->name ?? '')),
                'unit_price' => $l['unit_price'], 'list_price' => $list, 'line_total' => $l['unit_price'] * $l['quantity'],
            ];
        }

        $subtotal = array_sum(array_column($lines, 'line_total'));
        $discount = (int) ($data['discount'] ?? 0);
        $delivery = (int) ($data['delivery_fee'] ?? 0);

        if ($discount > $subtotal) {
            throw ValidationException::withMessages(['discount' => 'The discount cannot be more than the goods.']);
        }

        if ($discount > 0 && ! $actor->can('sales.discount')) {
            throw ValidationException::withMessages(['discount' => 'You do not have permission to give a discount.']);
        }

        $fields = collect($data)->only(['customer_id', 'customer_name', 'customer_phone', 'customer_email', 'customer_address', 'issued_on', 'valid_until', 'notes', 'terms'])->all();

        return [$fields, $lines, ['subtotal' => $subtotal, 'discount' => $discount, 'delivery_fee' => $delivery, 'total' => $subtotal - $discount + $delivery]];
    }
}
