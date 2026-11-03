<?php

namespace App\Modules\FieldSales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\ProductUnit;
use App\Modules\FieldSales\Services\TargetService;
use App\Modules\Organization\Models\Warehouse;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TargetController extends Controller
{
    public function __construct(private AccessScope $access, private TargetService $targets)
    {
    }

    private function period(Request $request): string
    {
        return $request->validate(['period' => ['nullable', 'date_format:Y-m']])['period'] ?? now()->format('Y-m');
    }

    /** All salespeople's targets for a month, with the reward rules. */
    public function index(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $period = $this->period($request);

        return response()->json([
            'period' => $period,
            'reps' => $this->targets->progress($storeId, $period),
            'rules' => ['tiers' => $this->targets->tiers($storeId), 'pay_lag' => $this->targets->payLag($storeId)],
        ]);
    }

    /** Set (or change) the same target for one or more salespeople. */
    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'user_ids' => ['required', 'array', 'min:1', 'max:200'],
            'user_ids.*' => ['integer'],
            'all_products' => ['sometimes', 'boolean'],
            'product_id' => ['required_unless:all_products,true', 'nullable', 'integer', Rule::exists('products', 'id')->where('store_id', $storeId)],
            'unit_id' => ['required_unless:all_products,true', 'nullable', 'integer'],
            'quantity' => ['required_unless:all_products,true', 'nullable', 'integer', 'min:1', 'max:10000000'],
            'target_amount' => ['required_if:all_products,true', 'nullable', 'integer', 'min:1', 'max:100000000000000'], // kobo of sales
            'reward_type' => ['required', 'in:flat,salary_percent,fixed_percent'],
            'reward_amount' => ['required_if:reward_type,flat', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'reward_percent' => ['required_unless:reward_type,flat', 'nullable', 'numeric', 'min:0.01', 'max:1000'],
            'reward_base' => ['required_if:reward_type,fixed_percent', 'nullable', 'integer', 'min:1', 'max:100000000000'],
        ]);

        $all = (bool) ($data['all_products'] ?? false);
        $unit = null;

        if (! $all) {
            $unit = ProductUnit::where('product_id', $data['product_id'])->where('unit_id', $data['unit_id'])->first();
            if (! $unit) {
                throw ValidationException::withMessages(['unit_id' => 'That product is not sold in this unit.']);
            }
        }

        // Only real salespeople (accounts that have a van) can be given a target.
        $reps = Warehouse::where('type', 'van')->whereIn('assigned_user_id', $data['user_ids'])->pluck('assigned_user_id')->all();
        if (count($reps) !== count(array_unique($data['user_ids']))) {
            throw ValidationException::withMessages(['user_ids' => 'Targets can only be set for salespeople.']);
        }

        $type = $data['reward_type'];
        $values = [
            'unit_id' => $all ? null : $data['unit_id'],
            'quantity' => $all ? 0 : $data['quantity'],
            'factor' => $all ? 1 : $unit->factor,
            'target_base' => $all ? $data['target_amount'] : $data['quantity'] * $unit->factor,
            'reward_type' => $type,
            'reward_amount' => $type === 'flat' ? (int) $data['reward_amount'] : 0,
            'reward_percent' => $type === 'flat' ? 0 : $data['reward_percent'],
            'reward_base' => $type === 'fixed_percent' ? (int) $data['reward_base'] : 0,
            'updated_at' => now(),
        ];

        DB::transaction(function () use ($storeId, $data, $all, $values) {
            foreach (array_unique($data['user_ids']) as $uid) {
                $where = DB::table('sales_targets')->where(['store_id' => $storeId, 'period' => $data['period'], 'user_id' => $uid]);
                $all ? $where->whereNull('product_id') : $where->where('product_id', $data['product_id']);

                if ($where->exists()) {
                    $where->update($values);
                } else {
                    DB::table('sales_targets')->insert([...$values, 'store_id' => $storeId, 'period' => $data['period'], 'user_id' => $uid, 'product_id' => $all ? null : $data['product_id'], 'created_at' => now()]);
                }
            }
        });

        return response()->json(['ok' => true], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        abort_unless(DB::table('sales_targets')->where('store_id', $storeId)->where('id', $id)->delete(), 404);

        return response()->json(['ok' => true]);
    }

    /** Start a month from last month's targets. */
    public function copy(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $request->validate(['from' => ['required', 'date_format:Y-m'], 'to' => ['required', 'date_format:Y-m', 'different:from']]);

        $copied = 0;
        foreach (DB::table('sales_targets')->where('store_id', $storeId)->where('period', $data['from'])->get() as $row) {
            $q = DB::table('sales_targets')->where(['store_id' => $storeId, 'period' => $data['to'], 'user_id' => $row->user_id]);
            $exists = ($row->product_id === null ? $q->whereNull('product_id') : $q->where('product_id', $row->product_id))->exists();

            if (! $exists) {
                DB::table('sales_targets')->insert([...(array) $row, 'id' => null, 'period' => $data['to'], 'created_at' => now(), 'updated_at' => now()]);
                $copied++;
            }
        }

        return response()->json(['copied' => $copied]);
    }

    /** The reward levels, e.g. 100% of target pays everything, 80% pays half. */
    public function saveRules(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        $data = $request->validate([
            'tiers' => ['required', 'array', 'min:1', 'max:6'],
            'tiers.*.min' => ['required', 'integer', 'min:1', 'max:1000'],
            'tiers.*.pays' => ['required', 'integer', 'min:1', 'max:200'],
            'pay_lag' => ['required', 'integer', 'in:0,1'],
        ]);

        $mins = array_column($data['tiers'], 'min');
        if (count($mins) !== count(array_unique($mins))) {
            throw ValidationException::withMessages(['tiers' => 'Each level needs a different achievement percentage.']);
        }

        $tiers = collect($data['tiers'])->sortByDesc('min')->values()->all();
        $this->targets->saveTiers($storeId, $tiers);
        $this->targets->savePayLag($storeId, $data['pay_lag']);

        return response()->json(['tiers' => $tiers, 'pay_lag' => $data['pay_lag']]);
    }

    /** A salesperson's own targets and how they are doing. */
    public function mine(Request $request): JsonResponse
    {
        return response()->json($this->targets->mine($request->user(), $this->period($request)));
    }
}
