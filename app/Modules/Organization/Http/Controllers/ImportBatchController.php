<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\ImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The history of uploaded files, and taking one back out. */
class ImportBatchController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = ImportBatch::with('user:id,name')->orderByDesc('id')->limit(100)->get()->map(fn ($b) => [...$b->toArray(), 'can_undo' => $b->status === 'active' && $b->kind !== 'products']);

        return response()->json(['data' => $rows]);
    }

    /**
     * Imported orders are history only (no stock, no ledger), so they can be deleted cleanly. Customers the upload created
     * are removed only when nothing else refers to them. Products are not undone automatically because stock may have moved.
     */
    public function undo(Request $request, int $id): JsonResponse
    {
        $batch = ImportBatch::findOrFail($id);
        abort_unless($batch->status === 'active', 422, 'This upload has already been undone.');
        abort_if($batch->kind === 'products', 422, 'Products cannot be undone automatically because stock and prices may already be in use. Deactivate them from the products page instead.');

        $result = DB::transaction(function () use ($batch) {
            $saleIds = DB::table('sales')->where('store_id', $batch->store_id)->where('import_batch_id', $batch->id)->pluck('id');
            $out = ['orders_removed' => 0, 'customers_removed' => 0, 'customers_kept' => 0];

            foreach ($saleIds->chunk(500) as $chunk) {
                DB::table('sale_payments')->whereIn('sale_id', $chunk)->delete();
                DB::table('sale_lines')->whereIn('sale_id', $chunk)->delete();
                $out['orders_removed'] += DB::table('sales')->whereIn('id', $chunk)->delete();
            }

            foreach (DB::table('customers')->where('store_id', $batch->store_id)->where('import_batch_id', $batch->id)->pluck('id') as $customerId) {
                if (DB::table('sales')->where('customer_id', $customerId)->exists()) {
                    $out['customers_kept']++;
                    continue;
                }

                try {
                    DB::transaction(function () use ($customerId) {
                        DB::table('customer_group_members')->where('customer_id', $customerId)->delete();
                        DB::table('customers')->where('id', $customerId)->delete();
                    });
                    $out['customers_removed']++;
                } catch (\Throwable) {
                    $out['customers_kept']++;
                }
            }

            return $out;
        });

        $note = "Removed {$result['orders_removed']} orders and {$result['customers_removed']} customers".($result['customers_kept'] ? "; kept {$result['customers_kept']} customers that are used elsewhere" : '').'.';
        $batch->update(['status' => 'undone', 'undone_by' => $request->user()->id, 'undone_at' => now(), 'undo_note' => $note]);

        return response()->json(['batch' => $batch->fresh('user:id,name'), 'result' => $result]);
    }
}
