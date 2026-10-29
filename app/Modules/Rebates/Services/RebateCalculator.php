<?php

namespace App\Modules\Rebates\Services;

use App\Modules\Rebates\Models\RebateAgreement;
use App\Modules\Rebates\Models\RebateCluster;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pure calculation: reads posted receipts and the agreement, returns the result, saves nothing.
 * Every rate, threshold and rule comes from the agreement data. Nothing about Coca-Cola's chart is in this code.
 *
 * Eligible = accepted goods on POSTED receipts from a manufacturer supplier, for products with a rebate category.
 * Volume is in physical cases: base units / the product's units_per_case (or its largest pack when that is not set).
 */
class RebateCalculator
{
    public function agreementFor(int $storeId, CarbonImmutable $month): RebateAgreement
    {
        $agreement = RebateAgreement::withoutGlobalScopes()->where('store_id', $storeId)->where('is_active', true)
            ->where('effective_from', '<=', $month->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $month->toDateString()))
            ->orderByDesc('effective_from')->first();

        abort_if(! $agreement, 422, 'There is no active rebate agreement covering '.$month->format('F Y').'. Set one up under Rebate agreements.');

        return $agreement->load('clusters.tiers');
    }

    /** The calendar quarter before the one that contains `$month`. */
    public function previousQuarter(CarbonImmutable $month): array
    {
        $start = $month->startOfQuarter()->subMonthsNoOverflow(3);

        return [$start, $start->addMonthsNoOverflow(2)->endOfMonth()];
    }

    /**
     * @return array{agreement:RebateAgreement, month:CarbonImmutable, cluster:RebateCluster, cluster_volume:float,
     *     cluster_from:CarbonImmutable, cluster_to:CarbonImmutable, overridden:bool, lines:array, sources:Collection,
     *     total_value:int, total_rebate:int, warnings:array}
     */
    public function compute(int $storeId, CarbonImmutable $month, ?int $clusterOverrideId = null): array
    {
        $month = $month->startOfMonth();
        $agreement = $this->agreementFor($storeId, $month);

        [$qFrom, $qTo] = $this->previousQuarter($month);
        $quarterVolume = (float) $this->eligible($storeId, $qFrom, $qTo)->sum('cases');

        $overridden = $clusterOverrideId !== null;
        $cluster = $overridden
            ? ($agreement->clusters->firstWhere('id', $clusterOverrideId) ?? abort(422, 'That cluster does not belong to the agreement.'))
            : $this->clusterFor($agreement, $quarterVolume);

        $sources = $this->eligible($storeId, $month, $month->endOfMonth());
        $names = DB::table('rebate_categories')->where('store_id', $storeId)->pluck('name', 'id');

        $lines = [];

        foreach ($sources->groupBy('rebate_category_id') as $categoryId => $rows) {
            $cases = round((float) $rows->sum('cases'), 3);
            $value = (int) $rows->sum('value');
            $tiers = $cluster->tiers->where('rebate_category_id', $categoryId)->sortBy('from_cases')->values();

            [$rebate, $top] = $agreement->method === 'marginal'
                ? $this->marginal($tiers, $cases, $value)
                : $this->allUnits($tiers, $cases, $value);

            $lines[] = [
                'rebate_category_id' => (int) $categoryId, 'category_name' => $names[$categoryId] ?? "#{$categoryId}",
                'cases' => $cases, 'eligible_value' => $value,
                'tier_from' => $top?->from_cases, 'tier_rate' => $top?->rate ?? 0.0,
                'effective_rate' => $value > 0 ? round($rebate / $value * 100, 4) : 0.0,
                'rebate' => $rebate, 'no_tier' => $top === null,
            ];
        }

        usort($lines, fn ($a, $b) => strcmp($a['category_name'], $b['category_name']));

        $assumed = $sources->where('cases_assumed', true)->pluck('product_name')->unique()->values()->all();

        return [
            'agreement' => $agreement, 'month' => $month, 'cluster' => $cluster, 'cluster_volume' => round($quarterVolume, 3),
            'cluster_from' => $qFrom, 'cluster_to' => $qTo, 'overridden' => $overridden, 'lines' => $lines, 'sources' => $sources,
            'total_value' => (int) array_sum(array_column($lines, 'eligible_value')),
            'total_rebate' => (int) array_sum(array_column($lines, 'rebate')),
            'warnings' => $assumed ? [['type' => 'cases_assumed', 'products' => $assumed]] : [],
        ];
    }

    /** The highest cluster whose threshold the previous quarter's volume is strictly above. */
    public function clusterFor(RebateAgreement $agreement, float $quarterVolume): RebateCluster
    {
        $chosen = null;

        foreach ($agreement->clusters->sortBy(fn ($c) => $c->volume_above ?? -1) as $cluster) {
            if ($cluster->volume_above === null || $quarterVolume > $cluster->volume_above) {
                $chosen = $cluster;
            }
        }

        return $chosen ?? abort(422, 'The agreement has no base cluster (one with no volume threshold).');
    }

    /** One rate, taken from the tier the month's volume reaches, applied to the whole value. */
    private function allUnits(Collection $tiers, float $cases, int $value): array
    {
        $tier = $tiers->last(fn ($t) => $cases >= $t->from_cases);

        return [$tier ? (int) round($value * $tier->rate / 100) : 0, $tier];
    }

    /** Each slice of volume earns its own tier's rate; the value is spread over the slices in proportion to cases. */
    private function marginal(Collection $tiers, float $cases, int $value): array
    {
        if ($cases <= 0) {
            return [0, null];
        }

        $rebate = 0.0;
        $top = null;

        foreach ($tiers->values() as $i => $tier) {
            $upper = $tiers[$i + 1]->from_cases ?? INF;
            $slice = min($cases, $upper) - $tier->from_cases;

            if ($slice > 0) {
                $rebate += $value * ($slice / $cases) * $tier->rate / 100;
                $top = $tier;
            }
        }

        return [(int) round($rebate), $top];
    }

    /** One row per eligible receipt line in the date range. */
    public function eligible(int $storeId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $largestPack = DB::table('product_units')->where('store_id', $storeId)->where('is_active', true)
            ->groupBy('product_id')->selectRaw('product_id, MAX(factor) AS f')->pluck('f', 'product_id');

        return DB::table('goods_receipt_lines as l')
            ->join('goods_receipts as r', 'r.id', '=', 'l.goods_receipt_id')
            ->join('suppliers as s', 's.id', '=', 'r.supplier_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.store_id', $storeId)->where('r.status', 'posted')->where('s.is_manufacturer', true)
            ->whereBetween('r.received_on', [$from->toDateString(), $to->toDateString()])
            ->where('l.accepted', '>', 0)->whereNotNull('p.rebate_category_id')
            ->orderBy('l.id')
            ->get(['l.id', 'l.product_id', 'l.accepted', 'l.factor', 'l.unit_cost', 'p.rebate_category_id', 'p.units_per_case', 'p.name as product_name'])
            ->map(function ($row) use ($largestPack) {
                $assumed = $row->units_per_case === null;
                $perCase = $assumed ? max((int) ($largestPack[$row->product_id] ?? 1), 1) : (int) $row->units_per_case;

                return (object) [
                    'line_id' => $row->id, 'product_id' => $row->product_id, 'product_name' => $row->product_name,
                    'rebate_category_id' => $row->rebate_category_id,
                    'cases' => round($row->accepted * $row->factor / $perCase, 3),
                    'value' => Money::kobo($row->accepted * (float) $row->unit_cost),
                    'cases_assumed' => $assumed,
                ];
            });
    }
}
