<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Services\StoreProvisioner;
use App\Support\Tenancy\StoreScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** Platform (super admin) only. Routes are behind `can:platform`. */
class StoreController extends Controller
{
    private function query()
    {
        // The store switcher header must not narrow the list of stores itself.
        return Store::withoutGlobalScope(StoreScope::class);
    }

    public function index(Request $request): JsonResponse
    {
        $stores = $this->query()
            ->withCount(['branches', 'users'])
            ->when($request->query('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($stores);
    }

    public function show(int $store): JsonResponse
    {
        return response()->json($this->query()->withCount(['branches', 'users'])->findOrFail($store));
    }

    public function store(Request $request, StoreProvisioner $provisioner): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'branch_name' => ['nullable', 'string', 'max:100'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'owner_password' => ['required', Password::min(8)],
        ]);

        [$store] = $provisioner->provision($data);

        return response()->json($store, 201);
    }

    public function update(Request $request, int $store): JsonResponse
    {
        $store = $this->query()->findOrFail($store);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
            'disabled_menus' => ['sometimes', 'array', 'max:200'],
            'disabled_menus.*' => ['string', 'max:100'],
        ]);

        // Menus the platform has switched off for this store live in its settings.
        if (array_key_exists('disabled_menus', $data)) {
            $data['settings'] = [...($store->settings ?? []), 'disabled_menus' => array_values(array_unique($data['disabled_menus']))];
            unset($data['disabled_menus']);
        }

        $store->update($data);

        return response()->json($store);
    }
}
