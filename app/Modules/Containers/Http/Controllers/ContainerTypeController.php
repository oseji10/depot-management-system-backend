<?php

namespace App\Modules\Containers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Containers\Models\ContainerType;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContainerTypeController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => ContainerType::orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        return response()->json(ContainerType::create([...$request->validate($this->rules($storeId)), 'store_id' => $storeId]), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $type = ContainerType::findOrFail($id);
        $data = $request->validate($this->rules($type->store_id, $type));
        unset($data['kind']);   // bottle or crate cannot change once movements exist

        $type->update($data);

        return response()->json($type);
    }

    private function rules(int $storeId, ?ContainerType $type = null): array
    {
        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('container_types', 'name')->where('store_id', $storeId)->ignore($type?->id)],
            'kind' => [$type ? 'sometimes' : 'required', Rule::in(ContainerType::KINDS)],
            'deposit' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
