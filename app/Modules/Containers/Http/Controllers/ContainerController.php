<?php

namespace App\Modules\Containers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Containers\Models\ContainerMovement;
use App\Modules\Containers\Models\ContainerType;
use App\Modules\Containers\Services\ContainerService;
use App\Modules\Sales\Models\Customer;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContainerController extends Controller
{
    public function __construct(private AccessScope $access, private ContainerService $service)
    {
    }

    /** One row per container type: empties on the shelf, held by customers, deposits held, owed to the supplier. */
    public function summary(): JsonResponse
    {
        $sums = ContainerMovement::query()->groupBy('container_type_id')->selectRaw(
            "container_type_id,
             COALESCE(SUM(qty_depot),0) AS on_shelf,
             COALESCE(SUM(CASE WHEN party_type = 'customer' THEN qty_party ELSE 0 END),0) AS with_customers,
             COALESCE(SUM(CASE WHEN party_type = 'supplier' THEN qty_party ELSE 0 END),0) AS owed_to_supplier,
             COALESCE(SUM(deposit),0) AS deposits_held",
        )->get()->keyBy('container_type_id');

        $shelf = ContainerMovement::query()->whereNotNull('warehouse_id')->groupBy('container_type_id', 'warehouse_id')
            ->selectRaw('container_type_id, warehouse_id, SUM(qty_depot) AS qty')->having('qty', '!=', 0)->get()->groupBy('container_type_id');

        $warehouses = \App\Modules\Organization\Models\Warehouse::pluck('name', 'id');

        return response()->json(['data' => ContainerType::orderBy('name')->get()->map(fn (ContainerType $t) => [
            ...$t->toArray(),
            'on_shelf' => (int) ($sums[$t->id]->on_shelf ?? 0),
            'with_customers' => (int) ($sums[$t->id]->with_customers ?? 0),
            'owed_to_supplier' => (int) ($sums[$t->id]->owed_to_supplier ?? 0),
            'deposits_held' => (int) ($sums[$t->id]->deposits_held ?? 0),
            'by_warehouse' => ($shelf[$t->id] ?? collect())->map(fn ($r) => ['warehouse_id' => $r->warehouse_id, 'name' => $warehouses[$r->warehouse_id] ?? '–', 'qty' => (int) $r->qty])->values(),
        ])]);
    }

    /** Who holds our containers: one row per customer and type, including the walk-in pool. */
    public function holdings(): JsonResponse
    {
        $rows = ContainerMovement::query()->where('party_type', 'customer')->groupBy('party_id', 'container_type_id')
            ->selectRaw('party_id, container_type_id, SUM(qty_party) AS qty, SUM(deposit) AS deposit')
            ->havingRaw('SUM(qty_party) != 0 OR SUM(deposit) != 0')->get();

        $names = Customer::whereIn('id', $rows->pluck('party_id')->filter()->unique())->pluck('name', 'id');
        $types = ContainerType::pluck('name', 'id');

        return response()->json(['data' => $rows->map(fn ($r) => [
            'customer_id' => $r->party_id, 'customer' => $r->party_id ? ($names[$r->party_id] ?? "Customer #{$r->party_id}") : 'Walk-in customers',
            'container_type_id' => $r->container_type_id, 'container' => $types[$r->container_type_id] ?? '–',
            'quantity' => (int) $r->qty, 'deposit' => (int) $r->deposit,
        ])->sortBy('customer')->values()]);
    }

    public function movements(Request $request): JsonResponse
    {
        $page = ContainerMovement::query()->with('type:id,name')
            ->when($request->query('container_type_id'), fn ($q, $v) => $q->where('container_type_id', $v))
            ->when($request->query('customer_id'), fn ($q, $v) => $q->where('party_type', 'customer')->where('party_id', $v))
            ->orderByDesc('id')->paginate(min((int) $request->query('per_page', 25), 100));

        $ids = $page->getCollection()->where('party_type', 'customer')->pluck('party_id')->filter()->unique();
        $names = Customer::whereIn('id', $ids)->pluck('name', 'id');
        $page->getCollection()->transform(fn ($m) => [...$m->toArray(), 'party_name' => $m->party_type === 'customer' ? ($m->party_id ? ($names[$m->party_id] ?? null) : 'Walk-in') : ($m->party_type === 'supplier' ? 'Supplier' : null)]);

        return response()->json($page);
    }

    public function receive(Request $request): JsonResponse
    {
        $d = $request->validate([
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('store_id', $this->access->requireStoreId())],
            ...$this->base(),
            'method' => ['sometimes', Rule::in(['cash', 'transfer'])],
        ]);

        return response()->json($this->service->receive($d, $request->user()), 201);
    }

    public function writeOff(Request $request): JsonResponse
    {
        $d = $request->validate([
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('store_id', $this->access->requireStoreId())],
            ...$this->base(),
        ]);

        return response()->json($this->service->writeOff($d, $request->user()), 201);
    }

    public function adjust(Request $request): JsonResponse
    {
        $d = $request->validate([
            'container_type_id' => ['required', 'integer'], 'warehouse_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'], 'note' => ['required', 'string', 'max:255'],
        ]);

        return response()->json($this->service->adjust($d, $request->user()), 201);
    }

    public function supplier(Request $request): JsonResponse
    {
        $d = $request->validate([
            'direction' => ['required', Rule::in(['in', 'out'])],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('store_id', $this->access->requireStoreId())],
            ...$this->base(),
        ]);

        return response()->json($this->service->supplier($d, $request->user()), 201);
    }

    private function base(): array
    {
        return [
            'container_type_id' => ['required', 'integer'], 'warehouse_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'], 'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
