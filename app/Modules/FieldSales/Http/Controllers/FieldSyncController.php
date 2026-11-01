<?php

namespace App\Modules\FieldSales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\FieldSales\Models\Trip;
use App\Modules\FieldSales\Services\SyncService;
use App\Modules\FieldSales\Services\TripService;
use App\Modules\Organization\Models\Store;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Services\PosCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Modules\Organization\Models\Warehouse;

/** The three calls a salesperson's phone makes: download everything for the day, send what was queued, say what is left. */
class FieldSyncController extends Controller
{
    public function __construct(private PosCatalogue $catalogue, private SyncService $sync, private TripService $trips)
    {
    }

    /** One download with everything needed to sell and log expenses with no signal. */
    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();
        $van = Warehouse::vanOf($user->id);
        abort_unless($van, 403, 'This account has no van.');

        $trip = $this->trips->openTripFor($user);
        $customers = Customer::where('is_active', true)->orderBy('name')->limit(3000)->get(['id', 'name', 'phone', 'type', 'credit_limit', 'empties_mode']);
        $owing = Customer::outstanding($customers->pluck('id')->all());

        return response()->json([
            'snapshot_at' => now()->toIso8601String(),
            'store' => Store::find($user->store_id)?->only(['name']),
            'user' => ['id' => $user->id, 'name' => $user->name],
            'van' => ['id' => $van->id, 'name' => $van->name],
            'trip' => $trip ? ['id' => $trip->id, 'number' => $trip->number, 'opened_at' => $trip->opened_at] : null,
            'catalogue' => $this->catalogue->build($van),
            'customers' => $customers->map(fn ($c) => [...$c->only(['id', 'name', 'phone', 'type', 'credit_limit', 'empties_mode']), 'owing' => (int) ($owing[$c->id] ?? 0)])->values(),
            'expense_categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'can_discount' => $user->can('sales.discount'),
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'snapshot_at' => ['nullable', 'date'],
            'pending' => ['sometimes', 'integer', 'min:0', 'max:100000'],   // how many are still queued after this batch
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.type' => ['required', 'in:customer,sale,expense'],
            'items.*.client_ref' => ['required', 'string', 'max:64'],
            'items.*.data' => ['required', 'array'],
        ]);

        $user = $request->user();
        abort_unless(Warehouse::vanOf($user->id), 403, 'This account has no van.');

        $results = $this->sync->process($data['items'], $user, $data['snapshot_at'] ?? null);
        $this->note($user->id, $data['pending'] ?? 0);

        return response()->json(['results' => $results, 'server_time' => now()->toIso8601String()]);
    }

    /** A heartbeat: lets the depot see the phone is alive and how many items it is still holding. */
    public function ping(Request $request): JsonResponse
    {
        $data = $request->validate(['pending' => ['required', 'integer', 'min:0', 'max:100000']]);
        $this->note($request->user()->id, $data['pending']);

        return response()->json(['ok' => true, 'server_time' => now()->toIso8601String()]);
    }

    private function note(int $userId, int $pending): void
    {
        $van = Warehouse::vanOf($userId);

        if ($van) {
            Trip::withoutGlobalScopes()->where('van_warehouse_id', $van->id)->where('status', 'open')
                ->update(['device_pending' => $pending, 'device_seen_at' => now()]);
        }
    }
}
