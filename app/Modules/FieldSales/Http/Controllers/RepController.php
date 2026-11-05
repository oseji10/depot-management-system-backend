<?php

namespace App\Modules\FieldSales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\FieldSales\Models\Trip;
use App\Modules\FieldSales\Services\TripService;
use App\Modules\FieldSales\Services\RepRoutes;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Organization\Models\Warehouse;
use App\Modules\Sales\Models\Sale;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** Salesperson accounts: a login plus a van, created together. */
class RepController extends Controller
{
    public function __construct(private AccessScope $access, private TripService $trips)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $vans = Warehouse::where('type', 'van')->with('branch:id,name')
            ->when($request->query('branch_id'), fn ($q, $b) => $q->where('branch_id', $b))
            ->get();

        $users = User::query()->visibleToActor()->whereIn('users.id', $vans->pluck('assigned_user_id'))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('users.name', 'like', "%{$s}%")->orWhere('users.phone', 'like', "%{$s}%")))
            ->orderBy('users.name')->get()->keyBy('id');

        $staff = \App\Modules\Hr\Models\Employee::withoutGlobalScopes()->whereIn('user_id', $users->keys())->get(['id', 'user_id', 'staff_no', 'status'])->keyBy('user_id');
        $open = Trip::where('status', 'open')->whereIn('rep_id', $users->keys())->get()->keyBy('rep_id');
        $onVan = StockBalance::whereIn('warehouse_id', $vans->pluck('id'))->where('status', 'saleable')
            ->groupBy('warehouse_id')->selectRaw('warehouse_id, SUM(quantity) AS units')->pluck('units', 'warehouse_id');
        $routes = \Illuminate\Support\Facades\DB::table('rep_routes')->join('customer_groups', 'customer_groups.id', '=', 'rep_routes.customer_group_id')
            ->whereIn('rep_routes.user_id', $users->keys())->orderBy('customer_groups.name')->get(['rep_routes.user_id', 'customer_groups.id', 'customer_groups.name'])->groupBy('user_id');
        $today = Sale::where('status', 'completed')->whereIn('created_by', $users->keys())->where('sold_at', '>=', now()->startOfDay())
            ->groupBy('created_by')->selectRaw('created_by, SUM(total) AS total, COUNT(*) AS orders')->get()->keyBy('created_by');

        $kept = \App\Modules\Sales\Models\PosTerminal::where('keep_with_rep', true)->whereIn('assigned_user_id', $users->keys())->get(['id', 'name', 'assigned_user_id'])->keyBy('assigned_user_id');
        $used = collect(['sales' => 'created_by', 'trips' => 'rep_id', 'trip_loads' => 'user_id', 'expenses' => 'created_by'])
            ->flatMap(fn ($col, $table) => \Illuminate\Support\Facades\DB::table($table)->whereIn($col, $users->keys())->distinct()->pluck($col))->flip();

        $rows = $vans->filter(fn ($v) => $users->has($v->assigned_user_id))->map(function (Warehouse $v) use ($users, $staff, $open, $onVan, $today, $routes, $kept, $used) {
            $u = $users[$v->assigned_user_id];
            $trip = $open[$u->id] ?? null;

            return [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'is_active' => (bool) $u->is_active,
                'employee' => isset($staff[$u->id]) ? ['id' => $staff[$u->id]->id, 'staff_no' => $staff[$u->id]->staff_no, 'status' => $staff[$u->id]->status] : null,
                'branch' => $v->branch, 'van' => ['id' => $v->id, 'name' => $v->name], 'branch_id' => $v->branch_id,
                'terminal' => isset($kept[$u->id]) ? ['id' => $kept[$u->id]->id, 'name' => $kept[$u->id]->name] : null,
                'can_delete' => ! isset($used[$u->id]),
                'open_trip' => $trip ? ['id' => $trip->id, 'number' => $trip->number, 'opened_at' => $trip->opened_at, 'device_pending' => (int) $trip->device_pending, 'device_seen_at' => $trip->device_seen_at] : null,
                'routes' => ($routes[$u->id] ?? collect())->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values(),
                'units_on_van' => (int) ($onVan[$v->id] ?? 0),
                'sold_today' => (int) ($today[$u->id]->total ?? 0), 'orders_today' => (int) ($today[$u->id]->orders ?? 0),
            ];
        })->sortBy('name')->values();

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', Password::min(8)],
            'branch_id' => ['required', 'integer'],
            'route_ids' => ['sometimes', 'array'],
            'route_ids.*' => ['integer'],
            'pos_terminal_id' => ['nullable', 'integer'],
        ]);
        $routeIds = $data['route_ids'] ?? [];
        $terminalId = $data['pos_terminal_id'] ?? null;
        unset($data['route_ids'], $data['pos_terminal_id']);

        $user = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $storeId, $routeIds, $terminalId) {
            $user = $this->trips->createRep($data, $storeId);
            RepRoutes::assign($user->id, $routeIds);

            if ($terminalId) {
                $this->trips->assignTerminal($user, $terminalId);
            }

            return $user;
        });

        return response()->json($user->only(['id', 'name', 'email', 'phone']), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::query()->visibleToActor()->findOrFail($id);
        abort_unless(Warehouse::vanOf($user->id), 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:150', \Illuminate\Validation\Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', Password::min(8)],
            'is_active' => ['sometimes', 'boolean'],
            'route_ids' => ['sometimes', 'array'],
            'route_ids.*' => ['integer'],
            'branch_id' => ['sometimes', 'integer'],
            'pos_terminal_id' => ['nullable', 'integer'],
        ]);

        if (array_key_exists('pos_terminal_id', $data)) {
            $this->trips->assignTerminal($user, $data['pos_terminal_id'] ?: null);
            unset($data['pos_terminal_id']);
        }

        if (array_key_exists('branch_id', $data)) {
            $van = Warehouse::vanOf($user->id);
            abort_if($van->branch_id !== (int) $data['branch_id'] && ($this->trips->openTripFor($user) || \App\Modules\Inventory\Models\StockBalance::where('warehouse_id', $van->id)->where('quantity', '>', 0)->exists()), 422, 'This salesperson still has stock out. Check them in before moving them to another branch.');
            \App\Modules\Organization\Models\Branch::findOrFail($data['branch_id']);
            $van->update(['branch_id' => $data['branch_id']]);
        }

        if (array_key_exists('route_ids', $data)) {
            RepRoutes::assign($user->id, $data['route_ids']);
            unset($data['route_ids']);
        }

        abort_if(
            array_key_exists('is_active', $data) && ! $data['is_active'] && $this->trips->openTripFor($user),
            422, 'This salesperson still has stock out. Check them in first.',
        );

        $fill = collect($data)->except('password')->all();
        if (! empty($data['password'])) {
            $fill['password'] = $data['password'];
        }

        $user->update($fill);

        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $user->tokens()->delete();
        }

        return response()->json($user->fresh()->only(['id', 'name', 'email', 'phone', 'is_active']));
    }

    /** Only a salesperson who has never sold, taken stock, or logged an expense can be deleted. Anyone else is disabled instead. */
    public function destroy(int $id): JsonResponse
    {
        $user = User::query()->visibleToActor()->findOrFail($id);
        $van = Warehouse::vanOf($user->id);
        abort_unless($van, 404);

        foreach (['sales' => 'created_by', 'trips' => 'rep_id', 'trip_loads' => 'user_id', 'expenses' => 'created_by'] as $table => $column) {
            abort_if(\Illuminate\Support\Facades\DB::table($table)->where($column, $user->id)->exists(), 422, 'This salesperson already has sales or trip history, so they cannot be deleted. Disable their account instead.');
        }

        abort_if(StockBalance::where('warehouse_id', $van->id)->where('quantity', '<>', 0)->exists(), 422, 'There is stock on this salesperson\'s van. Check it in first.');

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($user, $van) {
                \App\Modules\Sales\Models\PosTerminal::where('assigned_user_id', $user->id)->update(['assigned_user_id' => null, 'keep_with_rep' => false]);
                RepRoutes::assign($user->id, []);
                \App\Modules\Hr\Models\Employee::withoutGlobalScopes()->where('user_id', $user->id)->update(['user_id' => null]);
                $user->tokens()->delete();
                $user->syncRoles([]);
                $van->forceDelete();
                $user->delete();
            });
        } catch (\Illuminate\Database\QueryException) {
            abort(422, 'This salesperson is referenced by other records, so they cannot be deleted. Disable their account instead.');
        }

        return response()->json(null, 204);
    }
}
