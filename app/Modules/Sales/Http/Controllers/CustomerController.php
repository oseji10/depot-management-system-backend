<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Customer;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $paginator = Customer::query()
            ->when($request->query('search'), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")
            ))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->when($request->boolean('owing'), fn ($q) => $q->whereIn('id', DB::table('sales')
                ->where('store_id', $this->access->requireStoreId())->where('status', 'completed')->where('balance_due', '>', 0)->select('customer_id')))
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        $ids = $paginator->getCollection()->pluck('id')->all();
        $owing = Customer::outstanding($ids);
        $stats = $ids === [] ? collect() : DB::table('sales')->whereIn('customer_id', $ids)->where('status', 'completed')
            ->groupBy('customer_id')->selectRaw('customer_id, COUNT(*) AS orders, MAX(sold_at) AS last_sale_at')->get()->keyBy('customer_id');

        $paginator->setCollection($paginator->getCollection()->map(fn ($c) => [
            ...$c->toArray(),
            'outstanding' => $owing[$c->id] ?? 0,
            'orders_count' => (int) ($stats[$c->id]->orders ?? 0),
            'last_sale_at' => $stats[$c->id]->last_sale_at ?? null,
        ]));

        return response()->json($paginator);
    }

    public function show(int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        return response()->json([...$customer->toArray(), 'outstanding' => Customer::outstanding([$customer->id])[$customer->id] ?? 0]);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        $data = $request->validate($this->rules($storeId));
        $data['empties_mode'] ??= ($data['type'] ?? 'retail') === 'wholesale' ? 'balance' : 'deposit';

        return response()->json(Customer::create([...$data, 'store_id' => $storeId]), 201);
    }

    /** Customers are never deleted (sales reference them). Disable them instead. */
    public function update(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $customer->update($request->validate($this->rules($customer->store_id, $customer)));

        return response()->json($customer);
    }

    private function rules(int $storeId, ?Customer $customer = null): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'type' => ['sometimes', Rule::in(Customer::TYPES)],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('customers', 'phone')->where('store_id', $storeId)->ignore($customer?->id)],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'empties_mode' => ['sometimes', Rule::in(Customer::EMPTIES_MODES)],
            'credit_limit' => ['sometimes', 'integer', 'min:0', 'max:100000000000'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
