<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\Branch;
use App\Modules\Organization\Models\Warehouse;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $warehouses = Warehouse::query()
            ->with('branch:id,name')
            // Vans are managed under Field sales; they stay out of every ordinary warehouse list.
            ->when($request->query('type') !== 'all', fn ($q) => $q->where('type', 'standard'))
            ->when($request->query('branch_id'), fn ($q, $id) => $q->where('branch_id', $id))
            ->when($request->query('search'), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")
            ))
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($warehouses);
    }

    public function show(Warehouse $warehouse): JsonResponse
    {
        return response()->json($warehouse->load('branch:id,name'));
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $request->validate($this->rules($storeId));

        // findOrFail goes through the branch scope, so a branch from another store/branch is a 404.
        $branch = Branch::findOrFail($data['branch_id']);

        $warehouse = DB::transaction(function () use ($data, $branch, $storeId) {
            $warehouse = Warehouse::create([...$data, 'store_id' => $storeId, 'branch_id' => $branch->id]);
            $this->enforceSingleDefault($warehouse);

            return $warehouse;
        });

        return response()->json($warehouse->load('branch:id,name'), 201);
    }

    public function update(Request $request, Warehouse $warehouse): JsonResponse
    {
        $data = $request->validate($this->rules($warehouse->store_id, $warehouse));
        unset($data['branch_id']); // moving a warehouse between branches needs a stock transfer, not an edit

        DB::transaction(function () use ($warehouse, $data) {
            $warehouse->update($data);
            $this->enforceSingleDefault($warehouse);
        });

        return response()->json($warehouse->load('branch:id,name'));
    }

    public function destroy(Warehouse $warehouse): JsonResponse
    {
        abort_if($warehouse->isVan(), 422, 'A van belongs to a salesperson and cannot be deleted.');
        abort_if($warehouse->is_default, 422, 'Make another warehouse the default first.');
        // Once stock exists (phase 2) this must also refuse when the warehouse has movements.
        $warehouse->delete();

        return response()->json(null, 204);
    }

    private function enforceSingleDefault(Warehouse $warehouse): void
    {
        if ($warehouse->is_default) {
            Warehouse::where('branch_id', $warehouse->branch_id)
                ->whereKeyNot($warehouse->id)->update(['is_default' => false]);
        }
    }

    private function rules(int $storeId, ?Warehouse $warehouse = null): array
    {
        return [
            'branch_id' => [$warehouse ? 'sometimes' : 'required', 'integer'],
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20', Rule::unique('warehouses', 'code')
                ->where('store_id', $storeId)->ignore($warehouse?->id)],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
