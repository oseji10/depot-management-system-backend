<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\Branch;
use App\Modules\Organization\Models\Warehouse;
use App\Support\Imports\CustomerImporter;
use App\Support\Imports\OrderImporter;
use App\Support\Imports\ProductImporter;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bulk uploads. The browser reads the spreadsheet and sends it a batch of rows at a time; each batch reports what it did
 * and the page adds the batches up. Every importer recognises rows it has already brought in, so a failed or repeated
 * upload can simply be run again.
 */
class ImportController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function customers(Request $request): JsonResponse
    {
        $data = $request->validate($this->rowRules());

        return response()->json((new CustomerImporter($this->access->requireStoreId()))->run($data['rows'])->toArray());
    }

    public function products(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $request->validate([
            ...$this->rowRules(),
            'category_id' => ['required', 'integer', Rule::exists('product_categories', 'id')->where('store_id', $storeId)],
            'base_unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where('store_id', $storeId)],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('store_id', $storeId)],
        ]);

        $warehouse = ! empty($data['warehouse_id']) ? Warehouse::findOrFail($data['warehouse_id']) : null;
        $importer = new ProductImporter($storeId, $request->user(), $data['category_id'], $data['base_unit_id'], $warehouse);

        return response()->json($importer->run($data['rows'])->toArray());
    }

    public function orders(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $request->validate([...$this->rowRules(), 'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('store_id', $storeId)]]);

        $branch = Branch::findOrFail($data['branch_id']);
        $warehouse = Warehouse::where('branch_id', $branch->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->first();
        abort_unless($warehouse, 422, 'That branch has no warehouse yet. Add one first.');

        return response()->json((new OrderImporter($storeId, $request->user(), $branch->id, $warehouse))->run($data['rows'])->toArray());
    }

    private function rowRules(): array
    {
        return ['rows' => ['required', 'array', 'min:1', 'max:300'], 'rows.*' => ['array']];
    }
}
