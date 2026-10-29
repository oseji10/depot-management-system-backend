<?php

namespace App\Modules\FieldSales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\FieldSales\Models\Trip;
use App\Modules\FieldSales\Services\TripService;
use App\Modules\Inventory\Support\StockStatus;
use App\Modules\Organization\Models\Warehouse;
use App\Modules\Sales\Models\Sale;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TripController extends Controller
{
    public function __construct(private AccessScope $access, private TripService $trips)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $page = Trip::query()->with('rep:id,name')->withSum('lines as issued_units', 'issued')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('rep_id'), fn ($q, $v) => $q->where('rep_id', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('opened_at', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('opened_at', '<=', $v.' 23:59:59'))
            ->orderByRaw("status = 'open' DESC")->orderByDesc('opened_at')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        $sales = Sale::withoutGlobalScopes()->where('status', 'completed')->whereIn('trip_id', $page->pluck('id'))
            ->groupBy('trip_id')->selectRaw('trip_id, SUM(total) AS total, COUNT(*) AS orders')->get()->keyBy('trip_id');

        $page->getCollection()->transform(function (Trip $t) use ($sales) {
            $t->setAttribute('sales_total', (int) ($sales[$t->id]->total ?? 0));
            $t->setAttribute('orders', (int) ($sales[$t->id]->orders ?? 0));
            $t->setAttribute('issued_units', (int) $t->issued_units);

            return $t;
        });

        return response()->json($page);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->trips->report(Trip::findOrFail($id)));
    }

    /** The signed-in salesperson's own van and open trip. Anyone else gets an empty answer. */
    public function mine(Request $request): JsonResponse
    {
        $van = Warehouse::vanOf($request->user()->id);
        $trip = $van ? $this->trips->openTripFor($request->user()) : null;

        return response()->json([
            'van' => $van ? ['id' => $van->id, 'name' => $van->name] : null,
            'report' => $trip ? $this->trips->report($trip) : null,
        ]);
    }

    public function issue(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        $data = $request->validate([
            'rep_id' => ['required', 'integer'],
            'from_warehouse_id' => ['required', 'integer'],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->where('store_id', $storeId)],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('store_id', $storeId)],
            'lines.*.unit_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        $rep = User::query()->visibleToActor()->findOrFail($data['rep_id']);
        $from = Warehouse::findOrFail($data['from_warehouse_id']);

        $trip = $this->trips->issue($rep, $from, $data['lines'], $data['note'] ?? null, $request->user(), $data['vehicle_id'] ?? null);

        return response()->json($this->trips->report($trip), 201);
    }

    public function checkIn(Request $request, int $id): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        $data = $request->validate([
            'to_warehouse_id' => ['nullable', 'integer'],
            'returns' => ['sometimes', 'array', 'max:200'],
            'returns.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('store_id', $storeId)],
            'returns.*.unit_id' => ['required', 'integer'],
            'returns.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'returns.*.status' => ['sometimes', Rule::in([StockStatus::SALEABLE, StockStatus::DAMAGED])],
            'close' => ['sometimes', 'boolean'],
            'imprest_returned' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'cash_handed_in' => ['required_if:close,true', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'note' => ['nullable', 'string', 'max:500'],
            'confirm_pending' => ['sometimes', 'boolean'],
        ]);

        $returns = $data['returns'] ?? [];
        $to = ! empty($data['to_warehouse_id']) ? Warehouse::findOrFail($data['to_warehouse_id']) : null;

        // Closing while the phone still holds unsent sales (or the server turned some down) would strand them.
        if (($data['close'] ?? false) && ! ($data['confirm_pending'] ?? false)) {
            $sync = $this->trips->syncState(Trip::findOrFail($id));

            if ($sync['pending'] > 0 || $sync['issues'] > 0) {
                return response()->json([
                    'message' => 'This salesperson still has sales that have not reached the server. Wait for them to sync, or confirm to close anyway.',
                    'code' => 'pending_sync', 'sync' => $sync,
                ], 409);
            }
        }

        $trip = $this->trips->checkIn($id, $to, $returns, (bool) ($data['close'] ?? false), $data['cash_handed_in'] ?? null, $data['note'] ?? null, $request->user(), $data['imprest_returned'] ?? null);

        return response()->json($this->trips->report($trip));
    }
}
