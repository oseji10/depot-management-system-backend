<?php

namespace App\Modules\FieldSales\Services;

use App\Models\User;
use App\Modules\Organization\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly sales targets per van salesman and product, with a reward for reaching them. At or above the top level the whole
 * reward is paid; at a lower level a smaller share; below every level, nothing. Levels are set by the business.
 * Rewards are not part of basic pay: payroll adds them to the payslip as their own earning lines.
 */
class TargetService
{
    public const DEFAULT_TIERS = [['min' => 100, 'pays' => 100], ['min' => 80, 'pays' => 50]];

    /** @return list<array{min:int,pays:int}> highest level first */
    public function tiers(int $storeId): array
    {
        $rows = DB::table('target_tiers')->where('store_id', $storeId)->orderByDesc('min_percent')->get(['min_percent', 'payout_percent']);

        return $rows->isEmpty() ? self::DEFAULT_TIERS : $rows->map(fn ($r) => ['min' => (int) $r->min_percent, 'pays' => (int) $r->payout_percent])->all();
    }

    public function saveTiers(int $storeId, array $tiers): void
    {
        DB::transaction(function () use ($storeId, $tiers) {
            DB::table('target_tiers')->where('store_id', $storeId)->delete();

            foreach ($tiers as $t) {
                DB::table('target_tiers')->insert(['store_id' => $storeId, 'min_percent' => $t['min'], 'payout_percent' => $t['pays'], 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    /** 0 = rewards for a month go on that month's payroll; 1 = on the following month's. */
    public function payLag(int $storeId): int
    {
        return (int) (Store::withoutGlobalScopes()->find($storeId)?->settings['targets']['pay_lag'] ?? 0);
    }

    public function savePayLag(int $storeId, int $lag): void
    {
        $store = Store::withoutGlobalScopes()->findOrFail($storeId);
        $settings = $store->settings ?? [];
        $settings['targets']['pay_lag'] = $lag;
        $store->update(['settings' => $settings]);
    }

    /** What share of the full reward a given achievement earns. */
    public function payoutPercent(float $percent, array $tiers): int
    {
        foreach ($tiers as $t) {
            if ($percent + 1e-9 >= $t['min']) {
                return $t['pays'];
            }
        }

        return 0;
    }

    /**
     * Everyone's targets for a month with how far they have got.
     *
     * @return list<array<string,mixed>>
     */
    public function progress(int $storeId, string $period, ?int $userId = null): array
    {
        $targets = DB::table('sales_targets as t')->leftJoin('products as p', 'p.id', '=', 't.product_id')->leftJoin('units as u', 'u.id', '=', 't.unit_id')->join('users as r', 'r.id', '=', 't.user_id')
            ->where('t.store_id', $storeId)->where('t.period', $period)->when($userId, fn ($q) => $q->where('t.user_id', $userId))
            ->orderBy('r.name')->orderByRaw('p.name is not null')->orderBy('p.name')
            ->get(['t.id', 't.user_id', 'r.name as rep', 't.product_id', 'p.name as product', 'u.name as unit', 't.quantity', 't.factor', 't.target_base', 't.reward_amount', 't.reward_type', 't.reward_percent', 't.reward_base']);

        $ids = $targets->pluck('user_id')->unique()->all();
        $achieved = $this->achieved($storeId, $period, $ids);
        $worth = $this->achievedValue($storeId, $period, $ids);
        $salary = $this->salaries($storeId, $ids);
        $tiers = $this->tiers($storeId);

        $out = [];

        foreach ($targets->groupBy('user_id') as $uid => $rows) {
            $lines = $rows->map(function ($t) use ($achieved, $worth, $salary, $tiers) {
                $all = $t->product_id === null;
                $base = $all ? (int) ($worth[$t->user_id] ?? 0) : (int) ($achieved[$t->user_id][$t->product_id] ?? 0);
                $percent = $t->target_base > 0 ? round($base / $t->target_base * 100, 1) : 0.0;
                $pays = $this->payoutPercent($percent, $tiers);
                $full = $this->fullReward($t, $salary[$t->user_id] ?? 0);

                return [
                    'id' => $t->id, 'product_id' => $t->product_id, 'all_products' => $all, 'product' => $all ? 'All products (total sales)' : $t->product, 'unit' => $all ? 'naira' : $t->unit, 'quantity' => $all ? 0 : (int) $t->quantity,
                    'target_base' => (int) $t->target_base, 'achieved_base' => $base, 'achieved' => $all ? $base : round($base / max(1, $t->factor), 1), 'percent' => $percent,
                    'reward_type' => $t->reward_type, 'reward_percent' => (float) $t->reward_percent, 'reward_base' => (int) $t->reward_base, 'salary' => (int) ($salary[$t->user_id] ?? 0),
                    'payout_percent' => $pays, 'reward_full' => $full, 'reward' => (int) round($full * $pays / 100),
                ];
            })->values()->all();

            $out[] = ['user_id' => (int) $uid, 'name' => $rows->first()->rep, 'targets' => $lines, 'reward_total' => array_sum(array_column($lines, 'reward')), 'reward_full' => array_sum(array_column($lines, 'reward_full'))];
        }

        return $out;
    }

    /** The reward for reaching 100%: a flat amount, or a percentage of basic salary, or of a fixed amount. */
    public function fullReward(object $t, int $salary): int
    {
        return match ($t->reward_type ?? 'flat') {
            'salary_percent' => (int) round($salary * (float) $t->reward_percent / 100),
            'fixed_percent' => (int) round((int) $t->reward_base * (float) $t->reward_percent / 100),
            default => (int) $t->reward_amount,
        };
    }

    /**
     * Current basic salary (kobo) of each van salesman who is on the staff list.
     *
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    public function salaries(int $storeId, array $userIds): array
    {
        return DB::table('employees')->where('store_id', $storeId)->whereIn('user_id', $userIds)->where('status', 'active')->pluck('basic_salary', 'user_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Money each van salesman sold in the month (all products), less the value of what customers returned.
     *
     * @param  list<int>  $userIds
     * @return array<int, int> [user] => kobo
     */
    public function achievedValue(int $storeId, string $period, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $from = Carbon::createFromFormat('Y-m-d', "{$period}-01")->startOfDay();
        $to = (clone $from)->endOfMonth();

        $out = DB::table('sales')->where('store_id', $storeId)->where('status', 'completed')->whereIn('created_by', $userIds)->whereBetween('sold_at', [$from, $to])
            ->groupBy('created_by')->selectRaw('created_by as uid, SUM(total) as v')->pluck('v', 'uid')->map(fn ($v) => (int) $v)->all();

        $back = DB::table('sale_returns as r')->join('sales as s', 's.id', '=', 'r.sale_id')->where('s.store_id', $storeId)->whereIn('s.created_by', $userIds)->whereBetween('r.returned_at', [$from, $to])
            ->groupBy('s.created_by')->selectRaw('s.created_by as uid, SUM(r.total) as v')->pluck('v', 'uid');

        foreach ($back as $uid => $v) {
            $out[$uid] = max(0, ($out[$uid] ?? 0) - (int) $v);
        }

        return $out;
    }

    /**
     * Base units each van salesman sold of each product in the month, less what customers returned from those sales in it.
     *
     * @param  list<int>  $userIds
     * @return array<int, array<int, int>> [user][product] => base units
     */
    public function achieved(int $storeId, string $period, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $from = Carbon::createFromFormat('Y-m-d', "{$period}-01")->startOfDay();
        $to = (clone $from)->endOfMonth();

        $out = [];

        $sold = DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')
            ->where('s.store_id', $storeId)->where('s.status', 'completed')->whereIn('s.created_by', $userIds)->whereBetween('s.sold_at', [$from, $to])
            ->groupBy('s.created_by', 'l.product_id')->selectRaw('s.created_by as uid, l.product_id as pid, SUM(l.quantity * l.factor) as base')->get();

        foreach ($sold as $r) {
            $out[$r->uid][$r->pid] = (int) $r->base;
        }

        $returned = DB::table('sale_return_lines as l')->join('sale_returns as r', 'r.id', '=', 'l.sale_return_id')->join('sales as s', 's.id', '=', 'r.sale_id')
            ->where('s.store_id', $storeId)->whereIn('s.created_by', $userIds)->whereBetween('r.returned_at', [$from, $to])
            ->groupBy('s.created_by', 'l.product_id')->selectRaw('s.created_by as uid, l.product_id as pid, SUM(l.quantity * l.factor) as base')->get();

        foreach ($returned as $r) {
            $out[$r->uid][$r->pid] = max(0, ($out[$r->uid][$r->pid] ?? 0) - (int) $r->base);
        }

        return $out;
    }

    /**
     * The earning lines payroll for `$payrollPeriod` adds to a van salesman's payslip.
     *
     * @return list<array{id:int,label:string,amount:int}>
     */
    public function payrollLines(int $storeId, int $userId, string $payrollPeriod): array
    {
        $period = Carbon::createFromFormat('Y-m-d', "{$payrollPeriod}-01")->subMonths($this->payLag($storeId))->format('Y-m');
        $label = Carbon::createFromFormat('Y-m-d', "{$period}-01")->format('M Y');
        $lines = [];

        foreach ($this->progress($storeId, $period, $userId) as $rep) {
            foreach ($rep['targets'] as $t) {
                if ($t['reward'] > 0) {
                    $lines[] = ['id' => $t['id'], 'label' => sprintf('Target reward %s - %s (%s%% of target)', $label, $t['product'], rtrim(rtrim(number_format($t['percent'], 1), '0'), '.')), 'amount' => $t['reward']];
                }
            }
        }

        return $lines;
    }

    /** A van salesman's own view of the month. */
    public function mine(User $user, string $period): array
    {
        return $this->progress($user->store_id, $period, $user->id)[0] ?? ['user_id' => $user->id, 'name' => $user->name, 'targets' => [], 'reward_total' => 0, 'reward_full' => 0];
    }
}
