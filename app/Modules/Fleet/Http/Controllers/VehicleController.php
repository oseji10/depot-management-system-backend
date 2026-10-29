<?php

namespace App\Modules\Fleet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Expenses\Services\ExpenseService;
use App\Modules\FieldSales\Models\Trip;
use App\Modules\Fleet\Models\FuelVoucher;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Models\VehicleDocument;
use App\Modules\Fleet\Models\VehicleService;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VehicleController extends Controller
{
    public const SOON_DAYS = 30;

    public function __construct(private AccessScope $access, private ExpenseService $expenses)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $vehicles = Vehicle::query()->with(['branch:id,name', 'driver:id,name'])
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('reg_no', 'like', "%{$s}%")->orWhere('nickname', 'like', "%{$s}%")->orWhere('make', 'like', "%{$s}%")))
            ->orderBy('reg_no')->get();

        $open = Trip::query()->whereIn('vehicle_id', $vehicles->pluck('id'))->where('status', 'open')->with('rep:id,name')->get()->keyBy('vehicle_id');
        $today = now()->toDateString();
        $soon = now()->addDays(self::SOON_DAYS)->toDateString();

        // Per vehicle: how many documents have lapsed or lapse within a month.
        $docs = VehicleDocument::query()->whereIn('vehicle_id', $vehicles->pluck('id'))->whereNotNull('expires_on')->get()->groupBy('vehicle_id');

        $rows = $vehicles->map(fn (Vehicle $v) => [
            ...$v->toArray(),
            'open_trip' => isset($open[$v->id]) ? ['id' => $open[$v->id]->id, 'number' => $open[$v->id]->number, 'rep' => $open[$v->id]->rep?->name] : null,
            'docs_expired' => ($docs[$v->id] ?? collect())->filter(fn ($d) => $d->expires_on->toDateString() < $today)->count(),
            'docs_soon' => ($docs[$v->id] ?? collect())->filter(fn ($d) => $d->expires_on->toDateString() >= $today && $d->expires_on->toDateString() <= $soon)->count(),
        ]);

        return response()->json(['data' => $rows]);
    }

    public function show(int $id): JsonResponse
    {
        $v = Vehicle::with(['branch:id,name', 'driver:id,name'])->findOrFail($id);

        $trips = Trip::query()->where('vehicle_id', $v->id)->with('rep:id,name')->orderByDesc('id')->limit(20)->get()
            ->map(fn (Trip $t) => ['id' => $t->id, 'number' => $t->number, 'status' => $t->status, 'opened_at' => $t->opened_at, 'rep' => $t->rep?->name]);

        $vouchers = FuelVoucher::query()->where('vehicle_id', $v->id)->with('station:id,name')->orderByDesc('id')->limit(20)->get()
            ->map(fn ($f) => ['id' => $f->id, 'number' => $f->number, 'status' => $f->status, 'amount' => $f->amount, 'amount_used' => $f->amount_used, 'litres' => $f->litres, 'created_at' => $f->created_at, 'station' => $f->station?->name]);

        return response()->json([
            ...$v->toArray(),
            'documents' => $v->documents()->orderBy('expires_on')->get(),
            'services' => $v->services()->orderByDesc('serviced_on')->orderByDesc('id')->get(),
            'trips' => $trips,
            'fuel_vouchers' => $vouchers,
            'totals' => [
                'trips' => Trip::where('vehicle_id', $v->id)->count(),
                'fuel' => (int) FuelVoucher::where('vehicle_id', $v->id)->where('status', 'redeemed')->sum('amount_used'),
                'litres' => (float) FuelVoucher::where('vehicle_id', $v->id)->where('status', 'redeemed')->sum('litres'),
                'maintenance' => (int) $v->services()->sum('cost'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $this->validated($request, $storeId);

        $branchId = $data['branch_id'] ?? $this->access->branchId();
        abort_unless($branchId, 422, 'Choose the branch this vehicle belongs to.');

        return response()->json(Vehicle::create([...$data, 'branch_id' => $branchId, 'store_id' => $storeId]), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $v = Vehicle::findOrFail($id);
        $data = $this->validated($request, $v->store_id, $v);

        abort_if(
            ($data['status'] ?? 'active') !== 'active' && Trip::where('vehicle_id', $v->id)->where('status', 'open')->exists(),
            422, 'This vehicle is on an open trip. Check the trip in first.',
        );

        $v->update($data);

        return response()->json($v);
    }

    // ---- documents ----

    public function addDocument(Request $request, int $id): JsonResponse
    {
        $v = Vehicle::findOrFail($id);

        return response()->json($v->documents()->create([...$this->documentRules($request), 'store_id' => $v->store_id]), 201);
    }

    public function updateDocument(Request $request, int $id): JsonResponse
    {
        $doc = VehicleDocument::findOrFail($id);
        $doc->update($this->documentRules($request));

        return response()->json($doc);
    }

    public function deleteDocument(int $id): JsonResponse
    {
        VehicleDocument::findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    // ---- services and repairs ----

    public function addService(Request $request, int $id): JsonResponse
    {
        $v = Vehicle::findOrFail($id);

        $data = $request->validate([
            'serviced_on' => ['required', 'date', 'before_or_equal:today'],
            'kind' => ['required', Rule::in(['service', 'repair', 'tyres', 'accident', 'other'])],
            'description' => ['required', 'string', 'max:255'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'odometer' => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'cost' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'book_expense' => ['sometimes', 'boolean'],
            'method' => ['required_if:book_expense,true', Rule::in(['cash', 'bank'])],
        ]);

        $service = DB::transaction(function () use ($v, $data, $request) {
            $expenseId = null;

            if (! empty($data['book_expense']) && ($data['cost'] ?? 0) > 0) {
                $category = ExpenseCategory::where('is_active', true)->where('name', 'Vehicle repairs')->first()
                    ?? abort(422, 'Add an expense category called "Vehicle repairs" first, or save the service without booking the cost.');

                $expenseId = $this->expenses->create([
                    'category_id' => $category->id, 'amount' => $data['cost'], 'method' => $data['method'], 'expense_date' => $data['serviced_on'],
                    'paid_to' => $data['vendor'] ?? null, 'description' => "{$v->reg_no}: {$data['description']}", 'branch_id' => $v->branch_id,
                ], $request->user())->id;
            }

            $service = $v->services()->create([
                'store_id' => $v->store_id, 'serviced_on' => $data['serviced_on'], 'kind' => $data['kind'], 'description' => $data['description'],
                'vendor' => $data['vendor'] ?? null, 'odometer' => $data['odometer'] ?? null, 'cost' => $data['cost'] ?? 0,
                'expense_id' => $expenseId, 'created_by' => $request->user()->id,
            ]);

            if (($data['odometer'] ?? 0) > $v->odometer) {
                $v->update(['odometer' => $data['odometer']]);
            }

            return $service;
        });

        return response()->json($service, 201);
    }

    public function deleteService(int $id): JsonResponse
    {
        $service = VehicleService::findOrFail($id);
        abort_if($service->expense_id, 422, 'This cost was booked as an expense. Void the expense first, then it can be removed here.');
        $service->delete();

        return response()->json(null, 204);
    }

    // ---- helpers ----

    private function documentRules(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', Rule::in(array_keys(VehicleDocument::KINDS))],
            'reference' => ['nullable', 'string', 'max:255'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function validated(Request $request, int $storeId, ?Vehicle $current = null): array
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('store_id', $storeId)],
            'reg_no' => ['required', 'string', 'max:30', Rule::unique('vehicles', 'reg_no')->where('store_id', $storeId)->ignore($current?->id)],
            'nickname' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(['truck', 'van', 'pickup', 'motorbike', 'other'])],
            'make' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'color' => ['nullable', 'string', 'max:30'],
            'capacity_crates' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'chassis_no' => ['nullable', 'string', 'max:40'],
            'engine_no' => ['nullable', 'string', 'max:40'],
            'fuel_type' => ['required', Rule::in(['petrol', 'diesel', 'gas', 'electric'])],
            'odometer' => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'status' => ['sometimes', Rule::in(['active', 'in_workshop', 'retired'])],
            'default_driver_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('store_id', $storeId)],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $data['odometer'] ??= $current?->odometer ?? 0;

        return $data;
    }
}
