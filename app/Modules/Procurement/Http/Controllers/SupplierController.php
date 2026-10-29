<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Models\Supplier;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rows = Supplier::query()
            ->when($request->query('search'), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")
            ))
            ->orderByDesc('is_manufacturer')->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        return response()->json(Supplier::create([...$request->validate($this->rules($storeId)), 'store_id' => $storeId]), 201);
    }

    /** Suppliers are never deleted (receipts reference them). Disable them instead. */
    public function update(Request $request, int $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->update($request->validate($this->rules($supplier->store_id, $supplier)));

        return response()->json($supplier);
    }

    private function rules(int $storeId, ?Supplier $supplier = null): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:20',
                Rule::unique('suppliers', 'code')->where('store_id', $storeId)->ignore($supplier?->id)],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'payment_terms_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'is_manufacturer' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
