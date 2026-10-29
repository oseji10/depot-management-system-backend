<?php

namespace App\Modules\FieldSales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\FieldSales\Models\Trip;
use App\Modules\FieldSales\Services\TripService;
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
        $today = Sale::where('status', 'completed')->whereIn('created_by', $users->keys())->where('sold_at', '>=', now()->startOfDay())
            ->groupBy('created_by')->selectRaw('created_by, SUM(total) AS total, COUNT(*) AS orders')->get()->keyBy('created_by');

        $rows = $vans->filter(fn ($v) => $users->has($v->assigned_user_id))->map(function (Warehouse $v) use ($users, $staff, $open, $onVan, $today) {
            $u = $users[$v->assigned_user_id];
            $trip = $open[$u->id] ?? null;

            return [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'is_active' => (bool) $u->is_active,
                'employee' => isset($staff[$u->id]) ? ['id' => $staff[$u->id]->id, 'staff_no' => $staff[$u->id]->staff_no, 'status' => $staff[$u->id]->status] : null,
                'branch' => $v->branch, 'van' => ['id' => $v->id, 'name' => $v->name],
                'open_trip' => $trip ? ['id' => $trip->id, 'number' => $trip->number, 'opened_at' => $trip->opened_at, 'device_pending' => (int) $trip->device_pending, 'device_seen_at' => $trip->device_seen_at] : null,
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
        ]);

        $user = $this->trips->createRep($data, $storeId);

        return response()->json($user->only(['id', 'name', 'email', 'phone']), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::query()->visibleToActor()->findOrFail($id);
        abort_unless(Warehouse::vanOf($user->id), 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', Password::min(8)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

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
}
