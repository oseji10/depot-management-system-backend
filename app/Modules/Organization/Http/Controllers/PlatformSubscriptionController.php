<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** The platform admin's side: plans, defaults, and every business's subscription. */
class PlatformSubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions)
    {
    }

    public function index(): JsonResponse
    {
        $stores = DB::table('stores')->orderBy('name')->get(['id', 'name', 'email', 'is_active']);
        $plans = DB::table('plans')->get()->keyBy('id');

        return response()->json([
            'settings' => ['trial_days' => $this->subscriptions->setting('trial_days', 14), 'readonly_days' => $this->subscriptions->setting('readonly_days', 14), 'default_max_businesses' => $this->subscriptions->setting('default_max_businesses', 1)],
            'plans' => DB::table('plans')->orderBy('sort')->orderBy('price')->get(),
            'data' => $stores->map(fn ($s) => [...(array) $s, 'subscription' => [...$this->subscriptions->state($s->id), 'plan' => $plans[$this->subscriptions->state($s->id)['plan_id'] ?? 0]->name ?? null]]),
        ]);
    }

    public function payments(int $storeId): JsonResponse
    {
        return response()->json(['data' => DB::table('subscription_payments')->where('store_id', $storeId)->orderByDesc('id')->limit(100)->get()]);
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(['trial_days' => ['required', 'integer', 'min:1', 'max:365'], 'readonly_days' => ['required', 'integer', 'min:0', 'max:180'], 'default_max_businesses' => ['required', 'integer', 'min:1', 'max:100']]);
        $this->subscriptions->setSetting('trial_days', $data['trial_days']);
        $this->subscriptions->setSetting('readonly_days', $data['readonly_days']);
        $this->subscriptions->setSetting('default_max_businesses', $data['default_max_businesses']);

        return $this->index();
    }

    public function savePlan(Request $request, ?int $id = null): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:255'], 'price' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'months' => ['required', 'integer', 'min:1', 'max:60'], 'max_businesses' => ['required', 'integer', 'min:1', 'max:100'], 'is_active' => ['sometimes', 'boolean'], 'sort' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        if ($id) {
            abort_unless(DB::table('plans')->where('id', $id)->exists(), 404);
            DB::table('plans')->where('id', $id)->update([...$data, 'updated_at' => now()]);
        } else {
            DB::table('plans')->insert([...$data, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $this->index();
    }

    public function trial(Request $request, int $storeId): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']]);
        $this->store($storeId);
        $this->subscriptions->startTrial($storeId, $data['days'] ?? null);

        return $this->index();
    }

    public function extend(Request $request, int $storeId): JsonResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:3650']]);
        $this->store($storeId);
        $this->subscriptions->extend($storeId, $data['days']);

        return $this->index();
    }

    /** A payment the customer made to you directly (transfer, cash). */
    public function payment(Request $request, int $storeId): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['nullable', 'integer', Rule::exists('plans', 'id')], 'months' => ['required', 'integer', 'min:1', 'max:60'],
            'amount' => ['required', 'integer', 'min:0', 'max:100000000000'], 'reference' => ['nullable', 'string', 'max:80', Rule::unique('subscription_payments', 'reference')], 'note' => ['nullable', 'string', 'max:255'],
        ]);
        $this->store($storeId);
        $this->subscriptions->recordPayment($storeId, $data['plan_id'] ?? null, $data['amount'], $data['months'], 'manual', $data['reference'] ?? null, $request->user()->id, $data['note'] ?? null);

        return $this->index();
    }

    /** mode null = follow the dates again. */
    public function override(Request $request, int $storeId): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['nullable', Rule::in(['full', 'readonly', 'blocked'])], 'until' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:255'],
            'readonly_days' => ['nullable', 'integer', 'min:0', 'max:180'],
        ]);
        $this->store($storeId);
        $this->subscriptions->override($storeId, $data['mode'] ?? null, $data['until'] ?? null, $data['note'] ?? null, $request->user()->id, $data['readonly_days'] ?? null, $request->exists('readonly_days'));

        return $this->index();
    }

    private function store(int $id): void
    {
        abort_unless(DB::table('stores')->where('id', $id)->exists(), 404, 'Store not found.');
    }
}
