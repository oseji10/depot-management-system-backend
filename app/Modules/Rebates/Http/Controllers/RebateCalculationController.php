<?php

namespace App\Modules\Rebates\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\StoreLevelOnly;
use App\Modules\Rebates\Models\RebateCalculation;
use App\Modules\Rebates\Services\RebateCalculator;
use App\Modules\Rebates\Services\RebateService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RebateCalculationController extends Controller
{
    use StoreLevelOnly;

    private const WITH = ['agreement:id,name', 'creator:id,name', 'approver:id,name'];

    public function __construct(private RebateService $service, private RebateCalculator $calculator)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->guardStoreLevel();

        return response()->json(RebateCalculation::query()->with(self::WITH)
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('period_start')->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public function show(int $id): JsonResponse
    {
        $this->guardStoreLevel();

        return response()->json(RebateCalculation::with([...self::WITH, 'lines' => fn ($q) => $q->orderBy('category_name')])->withCount('sources')->findOrFail($id));
    }

    /** The received goods behind a calculation, line by line. */
    public function sources(Request $request, int $id): JsonResponse
    {
        $storeId = $this->guardStoreLevel();
        $calc = RebateCalculation::findOrFail($id);

        $rows = DB::table('rebate_calculation_sources as s')
            ->join('goods_receipt_lines as l', 'l.id', '=', 's.goods_receipt_line_id')
            ->join('goods_receipts as r', 'r.id', '=', 'l.goods_receipt_id')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->join('rebate_categories as c', 'c.id', '=', 's.rebate_category_id')
            ->where('s.store_id', $storeId)->where('s.rebate_calculation_id', $calc->id)
            ->orderBy('r.received_on')->orderBy('s.id')
            ->select('s.id', 'r.id as receipt_id', 'r.number as receipt_number', 'r.received_on', 'r.supplier_reference',
                'p.name as product_name', 'c.name as category_name', 's.cases', 's.value', 's.cases_assumed')
            ->paginate(min((int) $request->query('per_page', 50), 200));

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->guardStoreLevel();

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'cluster_name' => ['nullable', 'string', 'max:60'],
            'override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $month = CarbonImmutable::createFromFormat('Y-m-d', $data['month'].'-01');
        abort_if($month->isAfter(now()->endOfMonth()), 422, 'That month has not started yet.');

        $clusterId = null;

        if (! empty($data['cluster_name'])) {
            $agreement = $this->calculator->agreementFor($storeId, $month);
            $clusterId = $agreement->clusters->firstWhere('name', $data['cluster_name'])?->id ?? abort(422, 'That cluster is not in the agreement.');
        }

        $calc = $this->service->calculate($storeId, $month, $request->user(), $clusterId, $data['override_reason'] ?? null);

        return response()->json($calc->load([...self::WITH, 'lines']), 201);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $this->guardStoreLevel();
        $data = $request->validate(['accrual_date' => ['nullable', 'date']]);

        $this->service->approve($id, $request->user(), $data['accrual_date'] ?? null);

        return $this->show($id);
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $this->guardStoreLevel();
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->service->void($id, $request->user(), $data['reason']);

        return $this->show($id);
    }

    public function settle(Request $request, int $id): JsonResponse
    {
        $this->guardStoreLevel();
        $data = $request->validate([
            'method' => ['required', Rule::in(['bank', 'credit_note'])],
            'settled_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:80'],
        ]);

        $this->service->settle($id, $request->user(), $data['method'], $data['settled_on'], $data['reference'] ?? null);

        return $this->show($id);
    }

    /**
     * Month-to-date position: where each category stands and how far the next tier is. Saves nothing.
     */
    public function progress(Request $request): JsonResponse
    {
        $storeId = $this->guardStoreLevel();
        $month = CarbonImmutable::createFromFormat('Y-m-d', ($request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m')).'-01');

        $r = $this->calculator->compute($storeId, $month);

        $lines = collect($r['lines'])->map(function ($line) use ($r) {
            $next = $r['cluster']->tiers->where('rebate_category_id', $line['rebate_category_id'])
                ->filter(fn ($t) => $t->from_cases > $line['cases'])->sortBy('from_cases')->first();

            return [...$line,
                'next_tier_from' => $next?->from_cases, 'next_tier_rate' => $next?->rate,
                'cases_to_next_tier' => $next ? (int) ceil($next->from_cases - $line['cases']) : null,
            ];
        })->values();

        return response()->json([
            'month' => $month->format('Y-m'), 'agreement' => ['id' => $r['agreement']->id, 'name' => $r['agreement']->name, 'method' => $r['agreement']->method],
            'cluster' => $r['cluster']->name, 'cluster_volume' => $r['cluster_volume'],
            'cluster_from' => $r['cluster_from']->toDateString(), 'cluster_to' => $r['cluster_to']->toDateString(),
            'lines' => $lines, 'total_eligible_value' => $r['total_value'], 'total_rebate' => $r['total_rebate'], 'warnings' => $r['warnings'],
        ]);
    }
}
