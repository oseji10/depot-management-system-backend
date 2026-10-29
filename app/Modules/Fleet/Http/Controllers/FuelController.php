<?php

namespace App\Modules\Fleet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Fleet\Models\FuelStation;
use App\Modules\Fleet\Models\FuelVoucher;
use App\Modules\Fleet\Services\FuelService;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FuelController extends Controller
{
    public function __construct(private AccessScope $access, private FuelService $service)
    {
    }

    // ---- stations ----

    public function stations(): JsonResponse
    {
        $rows = FuelStation::query()->orderBy('name')->get()->map(fn (FuelStation $s) => $this->station($s));

        return response()->json(['data' => $rows]);
    }

    public function storeStation(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $request->validate($this->stationRules($storeId));

        return response()->json($this->station(FuelStation::create([...$data, 'store_id' => $storeId])), 201);
    }

    public function updateStation(Request $request, int $id): JsonResponse
    {
        $station = FuelStation::findOrFail($id);
        $station->update($request->validate($this->stationRules($station->store_id, $station)));

        return response()->json($this->station($station));
    }

    public function topUp(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'method' => ['required', Rule::in(['cash', 'bank'])],
            'reference' => ['nullable', 'string', 'max:120'],
            'entry_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $this->service->topUp(FuelStation::findOrFail($id), $data['amount'], $data['method'], $data['reference'] ?? null, $data['entry_date'] ?? null, $request->user());

        return response()->json($this->station(FuelStation::findOrFail($id)), 201);
    }

    public function entries(int $id): JsonResponse
    {
        $station = FuelStation::findOrFail($id);

        return response()->json($station->entries()->orderByDesc('id')->limit(100)->get());
    }

    // ---- vouchers ----

    public function vouchers(Request $request): JsonResponse
    {
        $rows = FuelVoucher::query()->with(['station:id,name', 'driver:id,name', 'trip:id,number'])
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('fuel_station_id'), fn ($q, $v) => $q->where('fuel_station_id', $v))
            ->when($request->query('driver_id'), fn ($q, $v) => $q->where('driver_id', $v))
            ->orderByDesc('id')->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    public function issue(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        $data = $request->validate([
            'fuel_station_id' => ['required', 'integer', Rule::exists('fuel_stations', 'id')->where('store_id', $storeId)],
            'driver_id' => ['required', 'integer'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'vehicle' => ['nullable', 'string', 'max:40'],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->where('store_id', $storeId)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $voucher = $this->service->issue(
            FuelStation::findOrFail($data['fuel_station_id']), User::query()->visibleToActor()->findOrFail($data['driver_id']),
            $data['amount'], $data['vehicle'] ?? null, $data['note'] ?? null, $request->user(), $data['vehicle_id'] ?? null,
        );

        return response()->json($voucher->load(['station:id,name', 'driver:id,name']), 201);
    }

    public function redeem(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount_used' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'litres' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        return response()->json($this->service->redeem($id, $data['amount_used'], $data['litres'] ?? null, $request->user())->load(['station:id,name', 'driver:id,name']));
    }

    public function cancel(int $id): JsonResponse
    {
        return response()->json($this->service->cancel($id)->load(['station:id,name', 'driver:id,name']));
    }

    private function station(FuelStation $s): array
    {
        $balance = $s->balance();
        $committed = $s->committed();

        return [...$s->only(['id', 'name', 'location', 'phone', 'is_active']), 'balance' => $balance, 'committed' => $committed, 'available' => $balance - $committed];
    }

    private function stationRules(int $storeId, ?FuelStation $station = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('fuel_stations', 'name')->where('store_id', $storeId)->ignore($station?->id)],
            'location' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
