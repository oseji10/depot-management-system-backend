<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Customer;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Modules\Sales\Models\CustomerGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        // A salesperson with routes sees their routes' customers unless they ask for everyone.
        $routeCustomers = $request->boolean('all_routes') ? null : \App\Modules\FieldSales\Services\RepRoutes::customerIds((int) $request->user()->id);

        $paginator = Customer::query()
            ->when($routeCustomers !== null, fn ($q) => $q->whereIn('id', $routeCustomers))
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
        $groupIds = $data['group_ids'] ?? [];
        unset($data['group_ids']);

        $customer = DB::transaction(function () use ($data, $storeId, $groupIds) {
            $customer = Customer::create([...$data, 'store_id' => $storeId]);

            // Put the new customer straight into the groups (route, area, segment) picked on the form.
            foreach (CustomerGroup::whereIn('id', $groupIds)->pluck('id') as $groupId) {
                DB::table('customer_group_members')->insert(['customer_group_id' => $groupId, 'customer_id' => $customer->id, 'created_at' => now()]);
            }

            return $customer;
        });

        return response()->json($customer, 201);
    }

    /** Deleting hides the customer (it can be restored). It is refused once anything has used it. */
    public function destroy(int $id): JsonResponse
    {
        \App\Support\Deletion::remove(\App\Modules\Sales\Models\Customer::findOrFail($id), ['sales' => 'customer_id', 'sale_returns' => 'customer_id'], 'This customer');

        return response()->json(null, 204);
    }

    /** Customers are never deleted (sales reference them). Disable them instead. */
    public function update(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $customer->update(collect($request->validate($this->rules($customer->store_id, $customer)))->except('group_ids')->all());

        return response()->json($customer);
    }

    private function rules(int $storeId, ?Customer $customer = null): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'type' => ['sometimes', Rule::in(Customer::TYPES)],
            // Required for every customer. Editing an old customer who has none only forces it when the form sends the field.
            'phone' => [$customer ? 'sometimes' : 'required', 'string', 'max:30', Rule::unique('customers', 'phone')->where('store_id', $storeId)->ignore($customer?->id)],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => [$customer ? 'sometimes' : 'required', 'string', 'max:255'],
            'group_ids' => ['sometimes', 'array', 'max:50'], 'group_ids.*' => ['integer'],
            'empties_mode' => ['sometimes', Rule::in(Customer::EMPTIES_MODES)],
            'credit_limit' => ['sometimes', 'integer', 'min:0', 'max:100000000000'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
