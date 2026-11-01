<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\Branch;
use App\Modules\Organization\Models\Warehouse;
use App\Modules\Organization\Models\ImportBatch;
use App\Support\Imports\CustomerImporter;
use App\Support\Imports\ImportReport;
use App\Support\Imports\OrderImporter;
use App\Support\Imports\ProductImporter;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $storeId = $this->access->requireStoreId();

        return $this->tracked($request, 'customers', fn () => (new CustomerImporter($storeId))->run($data['rows']), count($data['rows']));
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

        return $this->tracked($request, 'products', fn () => $importer->run($data['rows']), count($data['rows']));
    }

    public function orders(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $request->validate([...$this->rowRules(), 'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('store_id', $storeId)]]);

        $branch = Branch::findOrFail($data['branch_id']);
        $warehouse = Warehouse::where('branch_id', $branch->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->first();
        abort_unless($warehouse, 422, 'That branch has no warehouse yet. Add one first.');

        $importer = new OrderImporter($storeId, $request->user(), $branch->id, $warehouse);

        return $this->tracked($request, 'orders', fn () => $importer->run($data['rows']), count($data['rows']));
    }

    private function rowRules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1', 'max:300'], 'rows.*' => ['array'],
            'batch' => ['sometimes', 'array'], 'batch.id' => ['required_with:batch', 'string', 'max:40'],
            'batch.file_name' => ['nullable', 'string', 'max:200'], 'batch.total' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * Runs one chunk and records it against the upload's batch. Everything the chunk created is stamped with the batch
     * so the upload can be reviewed, and for customers and orders taken back out.
     */
    private function tracked(Request $request, string $kind, \Closure $run, int $rowCount): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $info = $request->input('batch');
        $before = [
            'customers' => (int) DB::table('customers')->where('store_id', $storeId)->max('id'),
            'products' => (int) DB::table('products')->where('store_id', $storeId)->max('id'),
            'sales' => (int) DB::table('sales')->where('store_id', $storeId)->max('id'),
        ];

        /** @var ImportReport $report */
        $report = $run();

        if (! $info) {
            return response()->json($report->toArray());
        }

        $batch = ImportBatch::firstOrCreate(
            ['store_id' => $storeId, 'uuid' => $info['id']],
            ['user_id' => $request->user()->id, 'kind' => $kind, 'file_name' => $info['file_name'] ?? null, 'rows_total' => $info['total'] ?? 0],
        );

        DB::table('customers')->where('store_id', $storeId)->where('id', '>', $before['customers'])->whereNull('import_batch_id')->update(['import_batch_id' => $batch->id]);
        DB::table('products')->where('store_id', $storeId)->where('id', '>', $before['products'])->whereNull('import_batch_id')->update(['import_batch_id' => $batch->id]);
        DB::table('sales')->where('store_id', $storeId)->where('id', '>', $before['sales'])->where('client_ref', 'like', 'import:%')->whereNull('import_batch_id')->update(['import_batch_id' => $batch->id]);

        $counts = $batch->counts ?? [];
        foreach ($report->counts as $key => $n) {
            $counts[$key] = ($counts[$key] ?? 0) + $n;
        }
        $batch->update(['counts' => $counts, 'rows_sent' => $batch->rows_sent + $rowCount, 'problems' => (int) ($counts['problems'] ?? 0)]);

        return response()->json($report->toArray());
    }
}
