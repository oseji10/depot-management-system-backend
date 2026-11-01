<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalePayment;
use App\Modules\Sales\Models\SaleReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** What customers owe and for how long, and a customer's statement of account. */
class CustomerFinanceController extends Controller
{
    /** Days an unpaid sale has been open -> which column it falls in. */
    private const BUCKETS = ['current' => [0, 30], 'd31_60' => [31, 60], 'd61_90' => [61, 90], 'd90_plus' => [91, null]];

    public function aging(Request $request): JsonResponse
    {
        $request->validate(['over' => ['nullable', 'in:30,60,90'], 'search' => ['nullable', 'string', 'max:100']]);
        $today = now()->startOfDay();

        $sales = Sale::query()->where('status', 'completed')->where('balance_due', '>', 0)->whereNotNull('customer_id')
            ->with('customer:id,name,phone,credit_limit')->get(['id', 'customer_id', 'number', 'sold_at', 'balance_due']);

        $rows = $sales->groupBy('customer_id')->map(function ($group) use ($today) {
            $c = $group->first()->customer;
            $row = ['customer_id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'credit_limit' => (int) $c->credit_limit, 'invoices' => $group->count(), 'total' => 0,
                'current' => 0, 'd31_60' => 0, 'd61_90' => 0, 'd90_plus' => 0, 'oldest_days' => 0, 'oldest_at' => null];

            foreach ($group as $sale) {
                $days = (int) $sale->sold_at->copy()->startOfDay()->diffInDays($today);
                $bucket = collect(self::BUCKETS)->search(fn ($r) => $days >= $r[0] && ($r[1] === null || $days <= $r[1]));
                $row[$bucket] += $sale->balance_due;
                $row['total'] += $sale->balance_due;

                if ($days >= $row['oldest_days']) {
                    [$row['oldest_days'], $row['oldest_at']] = [$days, $sale->sold_at->toDateString()];
                }
            }

            return $row;
        })->values();

        $rows = $rows
            ->when($request->query('over'), fn ($c, $over) => $c->filter(fn ($r) => $r['oldest_days'] > (int) $over))
            ->when($request->query('search'), fn ($c, $q) => $c->filter(fn ($r) => str_contains(mb_strtolower($r['name'].' '.$r['phone']), mb_strtolower($q))))
            ->sortByDesc('total')->values();

        return response()->json([
            'summary' => [
                'customers' => $rows->count(), 'total' => (int) $rows->sum('total'), 'current' => (int) $rows->sum('current'), 'd31_60' => (int) $rows->sum('d31_60'),
                'd61_90' => (int) $rows->sum('d61_90'), 'd90_plus' => (int) $rows->sum('d90_plus'),
            ],
            'data' => $rows,
        ]);
    }

    /**
     * Debit = what they owe us (sales, and refunds we paid out); credit = what reduced it (payments, credit notes).
     * The closing balance equals what the customer owes now.
     */
    public function statement(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $customer = Customer::findOrFail($id);

        $events = collect();

        foreach (Sale::where('customer_id', $id)->where('status', 'completed')->get(['id', 'number', 'sold_at', 'total', 'deposit_total']) as $s) {
            $events->push(['at' => $s->sold_at, 'type' => 'sale', 'ref' => $s->number, 'note' => 'Sale', 'debit' => $s->total + $s->deposit_total, 'credit' => 0, 'sale_id' => $s->id]);
        }

        foreach (SalePayment::whereIn('sale_id', Sale::where('customer_id', $id)->where('status', 'completed')->pluck('id'))->whereNull('voided_at')->with('sale:id,number')->get() as $p) {
            $events->push(['at' => $p->paid_at, 'type' => 'payment', 'ref' => $p->sale?->number, 'note' => 'Payment ('.$p->method.')', 'debit' => 0, 'credit' => $p->amount, 'sale_id' => $p->sale_id]);
        }

        foreach (SaleReturn::where('customer_id', $id)->get() as $r) {
            $events->push(['at' => $r->returned_at, 'type' => 'credit_note', 'ref' => $r->number, 'note' => 'Goods returned', 'debit' => 0, 'credit' => $r->total, 'sale_id' => $r->sale_id]);

            if ($r->refunded > 0) {
                $events->push(['at' => $r->returned_at, 'type' => 'refund', 'ref' => $r->number, 'note' => 'Money handed back ('.$r->refund_method.')', 'debit' => $r->refunded, 'credit' => 0, 'sale_id' => $r->sale_id]);
            }
        }

        $events = $events->sortBy(fn ($e) => $e['at']->timestamp)->values();
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : null;
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : null;

        $opening = $from ? $events->filter(fn ($e) => $e['at']->lt($from))->sum(fn ($e) => $e['debit'] - $e['credit']) : 0;
        $shown = $events->filter(fn ($e) => (! $from || $e['at']->gte($from)) && (! $to || $e['at']->lte($to)))->values();

        $balance = $opening;
        $rows = $shown->map(function ($e) use (&$balance) {
            $balance += $e['debit'] - $e['credit'];

            return [...$e, 'at' => $e['at']->toIso8601String(), 'balance' => $balance];
        });

        return response()->json([
            'customer' => $customer->only(['id', 'name', 'phone', 'address', 'credit_limit']),
            'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null, 'opening' => (int) $opening, 'closing' => (int) $balance,
            'total_debit' => (int) $shown->sum('debit'), 'total_credit' => (int) $shown->sum('credit'), 'rows' => $rows,
        ]);
    }
}
