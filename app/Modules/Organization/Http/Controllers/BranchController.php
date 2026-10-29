<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\Branch;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $branches = Branch::query()
            ->with('store:id,name')
            ->withCount(['warehouses', 'users'])
            ->when($request->query('search'), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")
            ))
            ->orderByDesc('is_head_office')->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($branches);
    }

    public function show(Branch $branch): JsonResponse
    {
        return response()->json($branch->load('warehouses'));
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertStoreLevel();
        $storeId = $this->access->requireStoreId();

        $data = $request->validate($this->rules($storeId));

        return response()->json(Branch::create([...$data, 'store_id' => $storeId]), 201);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate($this->rules($branch->store_id, $branch));

        // The head office cannot be disabled; there must always be one active branch.
        if ($branch->is_head_office && ($data['is_active'] ?? true) === false) {
            abort(422, 'The head office cannot be disabled.');
        }

        $branch->update($data);

        return response()->json($branch);
    }

    public function destroy(Branch $branch): JsonResponse
    {
        $this->assertStoreLevel();

        abort_if($branch->is_head_office, 422, 'The head office cannot be deleted.');
        abort_if($branch->users()->exists() || $branch->warehouses()->exists(), 422,
            'Move or remove this branch\'s users and warehouses first.');

        $branch->delete();

        return response()->json(null, 204);
    }

    /** Branch-level users may edit their own branch but never create or delete branches. */
    private function assertStoreLevel(): void
    {
        abort_if($this->access->isBranchRestricted(), 403, 'Only store-level users can do this.');
    }

    private function rules(int $storeId, ?Branch $branch = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20', Rule::unique('branches', 'code')
                ->where('store_id', $storeId)->ignore($branch?->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
