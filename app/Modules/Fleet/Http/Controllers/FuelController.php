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

    /** Deleting hides the fuel station (it can be restored). It is refused once any voucher has been issued against it. */
    public function destroyStation(int $id): JsonResponse
    {
        $station = \App\Modules\Fleet\Models\FuelStation::findOrFail($id);
        abort_if(
            \Illuminate\Support\Facades\DB::table('fuel_vouchers')->where('fuel_station_id', $station->id)->exists(),
            422,
            $station->name.' already has fuel vouchers issued against it, so it cannot be deleted. Switch it off instead (Edit, then untick Active) to stop using it.',
        );
        $station->delete();

        return response()->json(null, 204);
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
            'litres' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'method' => ['required', Rule::in(['cash', 'bank'])],
            'reference' => ['nullable', 'string', 'max:120'],
            'entry_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $this->service->topUp(FuelStation::findOrFail($id), $data['amount'], (float) $data['litres'], $data['method'], $data['reference'] ?? null, $data['entry_date'] ?? null, $request->user());

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
        $rows = FuelVoucher::query()->with(['station:id,name,location', 'driver:id,name', 'trip:id,number', 'issuer:id,name', 'voider:id,name'])
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
            'litres' => ['required', 'numeric', 'min:1', 'max:100000'],
            'price_per_litre' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'vehicle' => ['nullable', 'string', 'max:40'],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->where('store_id', $storeId)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $voucher = $this->service->issue(
            FuelStation::findOrFail($data['fuel_station_id']), User::query()->visibleToActor()->findOrFail($data['driver_id']),
            (float) $data['litres'], $data['price_per_litre'] ?? null, $data['vehicle'] ?? null, $data['note'] ?? null, $request->user(), $data['vehicle_id'] ?? null,
        );

        return response()->json($voucher->load(['station:id,name,location', 'driver:id,name', 'issuer:id,name']), 201);
    }

    /** One voucher with everything the printed copy needs. */
    public function showVoucher(int $id): JsonResponse
    {
        return response()->json(FuelVoucher::with(['station:id,name,location,phone', 'driver:id,name', 'trip:id,number', 'issuer:id,name', 'voider:id,name'])->findOrFail($id));
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return response()->json($this->service->void($id, trim($data['reason']), $request->user())->load(['station:id,name,location', 'driver:id,name', 'voider:id,name']));
    }

    private function station(FuelStation $s): array
    {
        $balance = $s->balance();

        return [
            ...$s->only(['id', 'name', 'location', 'phone', 'is_active', 'price_per_litre']),
            'balance' => $balance, 'litres' => $s->litresBalance(), 'tracks_litres' => $s->tracksLitres(),
        ];
    }

    private function stationRules(int $storeId, ?FuelStation $station = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('fuel_stations', 'name')->where('store_id', $storeId)->ignore($station?->id)],
            'location' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'price_per_litre' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
