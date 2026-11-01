<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Accounting\Services\InventoryCosting;
use App\Modules\Accounting\Services\SalesPosting;
use App\Modules\FieldSales\Models\Trip;
use App\Modules\Inventory\Services\DocumentNumber;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Organization\Models\Warehouse;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\SaleReturnLine;
use App\Support\Imports\OrderImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Goods a customer sends back after buying (a credit note). One transaction: the goods go back on a shelf, their cost
 * goes back into inventory, the sale is reduced in the books, and the value is first taken off whatever the customer
 * still owes on that sale; only the rest is handed back as money (cash or transfer).
 *
 * Container deposits are not touched here; settle empties on the Containers page.
 */
class ReturnService
{
    public function __construct(
        private StockService $stock,
        private InventoryCosting $costing,
        private SalesPosting $posting,
        private DocumentNumber $numbers,
    ) {
    }

    /**
     * @param  array{reason:string, restock_status?:string, refund_method?:?string, lines:array<int, array{sale_line_id:int, quantity:int}>}  $data
     */
    public function create(int $saleId, array $data, User $actor): SaleReturn
    {
        return DB::transaction(function () use ($saleId, $data, $actor) {
            $sale = Sale::with('lines')->lockForUpdate()->findOrFail($saleId);

            abort_unless($sale->status === 'completed', 422, 'Only a completed sale can have goods returned.');
            abort_if(str_starts_with((string) $sale->client_ref, OrderImporter::REF), 422, 'This order was imported as history. Record the return with a stock adjustment instead.');

            $already = SaleReturnLine::whereIn('sale_line_id', $sale->lines->pluck('id'))->groupBy('sale_line_id')
                ->selectRaw('sale_line_id, SUM(quantity) AS qty')->pluck('qty', 'sale_line_id');

            $picked = [];
            $total = 0;
            $cogs = 0;

            foreach ($data['lines'] as $i => $in) {
                $line = $sale->lines->firstWhere('id', (int) $in['sale_line_id'])
                    ?? throw ValidationException::withMessages(["lines.$i.sale_line_id" => 'That item is not on this sale.']);
                $qty = (int) $in['quantity'];
                $left = $line->quantity - (int) ($already[$line->id] ?? 0);

                if ($qty < 1 || $qty > $left) {
                    throw ValidationException::withMessages(["lines.$i.quantity" => $left > 0 ? "Only {$left} can still be returned." : 'All of this item has already been returned.']);
                }

                $value = $qty * $line->unit_price;
                $cost = intdiv($line->cost_total * $qty, max($line->quantity, 1));
                $total += $value;
                $cogs += $cost;
                $picked[] = [$line, $qty, $value, $cost];
            }

            $applied = min($total, $sale->balance_due);
            $refund = $total - $applied;
            $method = $data['refund_method'] ?? null;

            if ($refund > 0 && ! in_array($method, ['cash', 'transfer'], true)) {
                throw ValidationException::withMessages(['refund_method' => sprintf('%s must be handed back. Choose cash or transfer.', number_format($refund / 100, 2))]);
            }

            $warehouse = $this->shelf($sale);
            $status = ($data['restock_status'] ?? 'saleable') === 'damaged' ? 'damaged' : 'saleable';

            $return = SaleReturn::create([
                'store_id' => $sale->store_id, 'branch_id' => $sale->branch_id, 'warehouse_id' => $warehouse->id, 'sale_id' => $sale->id,
                'customer_id' => $sale->customer_id, 'number' => $this->numbers->next($sale->store_id, 'CN'), 'returned_at' => now(),
                'reason' => $data['reason'], 'restock_status' => $status, 'total' => $total, 'cogs_back' => $cogs,
                'applied_to_balance' => $applied, 'refunded' => $refund, 'refund_method' => $refund > 0 ? $method : null, 'created_by' => $actor->id,
            ]);

            foreach ($picked as $k => [$line, $qty, $value, $cost]) {
                $return->lines()->create([
                    'store_id' => $sale->store_id, 'sale_line_id' => $line->id, 'product_id' => $line->product_id, 'unit_id' => $line->unit_id,
                    'factor' => $line->factor, 'quantity' => $qty, 'unit_price' => $line->unit_price, 'line_total' => $value, 'cost_total' => $cost,
                ]);

                $base = $qty * $line->factor;
                $this->stock->move($warehouse, $line->product_id, $status, $base, 'sale_return', [
                    'source' => $return, 'reference' => $return->number, 'note' => "Return on {$sale->number}: {$data['reason']}", 'user_id' => $actor->id,
                    'idempotency_key' => "salereturn:{$return->id}:{$k}",
                ]);
                $this->costing->add($sale->store_id, $line->product_id, $base, $cost);
            }

            $this->posting->saleReturn($return, $actor);

            $due = $sale->balance_due - $applied;
            $sale->update([
                'balance_due' => $due, 'returned_total' => $sale->returned_total + $total,
                'payment_status' => $due === 0 ? 'paid' : ($sale->amount_paid > 0 ? 'partial' : 'unpaid'),
            ]);

            return $return;
        });
    }

    /** Back to the shelf the goods left from; a closed van has nothing to hold them, so they go to the branch's main warehouse. */
    private function shelf(Sale $sale): Warehouse
    {
        $own = Warehouse::withoutGlobalScopes()->findOrFail($sale->warehouse_id);

        if (! $own->isVan()) {
            return $own;
        }

        $open = $sale->trip_id && Trip::withoutGlobalScopes()->whereKey($sale->trip_id)->where('status', 'open')->exists();

        if ($open) {
            return $own;
        }

        return Warehouse::withoutGlobalScopes()->where('store_id', $sale->store_id)->where('branch_id', $sale->branch_id)->where('type', '!=', 'van')
            ->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->first()
            ?? throw ValidationException::withMessages(['lines' => 'This branch has no warehouse to return the goods to.']);
    }
}
