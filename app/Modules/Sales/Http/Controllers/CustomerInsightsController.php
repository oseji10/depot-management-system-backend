<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerGroup;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\NetworkProvider;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Who buys, who has gone quiet, and how customers fall into networks and groups. Figures come from completed sales
 * (a branch user sees only their own branch's sales).
 */
class CustomerInsightsController extends Controller
{
    /** Days without a purchase that count as lapsed. */
    public const BUCKETS = ['active' => [0, 30], 'd31_90' => [31, 90], 'd91_180' => [91, 180], 'd181_365' => [181, 365], 'd365_plus' => [366, null]];

    public function __construct(private AccessScope $access)
    {
    }

    public function insights(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'bucket' => ['nullable', Rule::in([...array_keys(self::BUCKETS), 'never', 'lapsed'])],
            'network' => ['nullable', Rule::in(NetworkProvider::NETWORKS)],
            'group_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['last_purchase', 'lifetime', 'orders', 'name'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'all' => ['nullable', 'boolean'],
        ]);

        $now = now();
        $stats = Sale::query()->where('status', 'completed')->whereNotNull('customer_id')->groupBy('customer_id')
            ->selectRaw('customer_id, MAX(sold_at) AS last_at, COUNT(*) AS orders, SUM(total) AS lifetime, SUM(CASE WHEN sold_at >= ? THEN total ELSE 0 END) AS recent, SUM(balance_due) AS due', [$now->copy()->subDays(90)->toDateTimeString()])
            ->get()->keyBy('customer_id');

        $groupsOf = DB::table('customer_group_members')->join('customer_groups', 'customer_groups.id', '=', 'customer_group_members.customer_group_id')
            ->where('customer_groups.store_id', $this->access->requireStoreId())->where('customer_groups.is_active', true)
            ->get(['customer_group_members.customer_id', 'customer_groups.id', 'customer_groups.name'])->groupBy('customer_id');

        $all = Customer::query()->where('is_active', true)->orderBy('name')->limit(20000)->get(['id', 'name', 'type', 'phone', 'address', 'created_at'])
            ->map(function (Customer $c) use ($stats, $groupsOf, $now) {
                $s = $stats[$c->id] ?? null;
                $last = $s ? \Illuminate\Support\Carbon::parse($s->last_at) : null;
                $days = $last ? (int) $last->copy()->startOfDay()->diffInDays($now->copy()->startOfDay()) : null;

                return [
                    'id' => $c->id, 'name' => $c->name, 'type' => $c->type, 'phone' => $c->phone, 'address' => $c->address,
                    'network' => NetworkProvider::detect($c->phone),
                    'last_purchase_at' => $last?->toIso8601String(), 'days_since' => $days, 'bucket' => $this->bucketOf($days),
                    'orders' => (int) ($s->orders ?? 0), 'lifetime' => (int) ($s->lifetime ?? 0), 'recent_90' => (int) ($s->recent ?? 0),
                    'average_order' => $s && $s->orders ? intdiv((int) $s->lifetime, (int) $s->orders) : 0, 'owes' => (int) ($s->due ?? 0),
                    'groups' => ($groupsOf[$c->id] ?? collect())->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->values(),
                ];
            });

        $summary = [
            'customers' => $all->count(),
            'buckets' => collect([...array_keys(self::BUCKETS), 'never'])->mapWithKeys(fn ($b) => [$b => $all->where('bucket', $b)->count()]),
            'networks' => collect(NetworkProvider::NETWORKS)->map(fn ($n) => [
                'name' => $n, 'customers' => $all->where('network', $n)->count(), 'lifetime' => (int) $all->where('network', $n)->sum('lifetime'),
                'lapsed' => $all->where('network', $n)->filter(fn ($r) => in_array($r['bucket'], ['d91_180', 'd181_365', 'd365_plus'], true))->count(),
            ])->filter(fn ($n) => $n['customers'] > 0)->values(),
            'lifetime' => (int) $all->sum('lifetime'),
            'recent_90' => (int) $all->sum('recent_90'),
        ];

        $rows = $all
            ->when($filters['bucket'] ?? null, fn ($c, $b) => $b === 'lapsed' ? $c->filter(fn ($r) => in_array($r['bucket'], ['d31_90', 'd91_180', 'd181_365', 'd365_plus'], true)) : $c->where('bucket', $b))
            ->when($filters['network'] ?? null, fn ($c, $n) => $c->where('network', $n))
            ->when($filters['group_id'] ?? null, fn ($c, $g) => $c->filter(fn ($r) => collect($r['groups'])->contains('id', (int) $g)))
            ->when($filters['search'] ?? null, fn ($c, $q) => $c->filter(fn ($r) => str_contains(mb_strtolower($r['name'].' '.$r['phone']), mb_strtolower($q))));

        $sorted = match ($filters['sort'] ?? 'last_purchase') {
            'lifetime' => $rows->sortByDesc('lifetime'),
            'orders' => $rows->sortByDesc('orders'),
            'name' => $rows->sortBy('name'),
            // Longest gap first; people who never bought come last of all.
            default => $rows->sortByDesc(fn ($r) => $r['days_since'] ?? -1),
        };

        $total = $sorted->count();
        $per = 50;
        $page = max(1, (int) ($filters['page'] ?? 1));
        $data = ! empty($filters['all']) ? $sorted->take(5000)->values() : $sorted->forPage($page, $per)->values();

        $groups = CustomerGroup::query()->orderBy('name')->get()->map(function (CustomerGroup $g) use ($all) {
            $members = $all->filter(fn ($r) => collect($r['groups'])->contains('id', $g->id));

            return [...$g->toArray(), 'customers' => $members->count(), 'lifetime' => (int) $members->sum('lifetime'), 'lapsed' => $members->filter(fn ($r) => in_array($r['bucket'], ['d91_180', 'd181_365', 'd365_plus'], true))->count()];
        });

        return response()->json([
            'summary' => $summary, 'groups' => $groups,
            'data' => $data, 'total' => $total, 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $per)),
        ]);
    }

    // ---- groups ----

    public function storeGroup(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        return response()->json(CustomerGroup::create([...$this->groupRules($request, $storeId), 'store_id' => $storeId]), 201);
    }

    public function updateGroup(Request $request, int $id): JsonResponse
    {
        $group = CustomerGroup::findOrFail($id);
        $group->update($this->groupRules($request, $group->store_id, $group));

        return response()->json($group);
    }

    public function deleteGroup(int $id): JsonResponse
    {
        CustomerGroup::findOrFail($id)->delete();   // members go with it; the customers themselves are untouched

        return response()->json(null, 204);
    }

    /** Add or remove many customers at once, e.g. everyone on a route, or everyone the current filter shows. */
    public function members(Request $request, int $id): JsonResponse
    {
        $group = CustomerGroup::findOrFail($id);
        $data = $request->validate(['mode' => ['required', Rule::in(['add', 'remove'])], 'customer_ids' => ['required', 'array', 'min:1', 'max:5000'], 'customer_ids.*' => ['integer']]);

        $ids = Customer::whereIn('id', $data['customer_ids'])->pluck('id');

        if ($data['mode'] === 'add') {
            foreach ($ids as $customerId) {
                DB::table('customer_group_members')->insertOrIgnore(['customer_group_id' => $group->id, 'customer_id' => $customerId, 'created_at' => now()]);
            }
        } else {
            DB::table('customer_group_members')->where('customer_group_id', $group->id)->whereIn('customer_id', $ids)->delete();
        }

        return response()->json(['changed' => $ids->count()]);
    }

    /** Replace one customer's groups. */
    public function setCustomerGroups(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $data = $request->validate(['group_ids' => ['present', 'array', 'max:50'], 'group_ids.*' => ['integer']]);

        $valid = CustomerGroup::whereIn('id', $data['group_ids'])->pluck('id');

        DB::transaction(function () use ($customer, $valid) {
            DB::table('customer_group_members')->where('customer_id', $customer->id)->delete();

            foreach ($valid as $groupId) {
                DB::table('customer_group_members')->insert(['customer_group_id' => $groupId, 'customer_id' => $customer->id, 'created_at' => now()]);
            }
        });

        return response()->json(['group_ids' => $valid]);
    }

    private function bucketOf(?int $days): string
    {
        if ($days === null) {
            return 'never';
        }

        foreach (self::BUCKETS as $name => [$from, $to]) {
            if ($days >= $from && ($to === null || $days <= $to)) {
                return $name;
            }
        }

        return 'd365_plus';
    }

    private function groupRules(Request $request, int $storeId, ?CustomerGroup $current = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('customer_groups', 'name')->where('store_id', $storeId)->ignore($current?->id)],
            'kind' => ['required', Rule::in(array_keys(CustomerGroup::KINDS))],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
