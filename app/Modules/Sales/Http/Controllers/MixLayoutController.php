<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\MixLayout;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MixLayoutController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(MixLayout::query()->orderBy('row_size')->orderBy('row_count')->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        return response()->json(MixLayout::create([...$request->validate($this->rules($storeId)), 'store_id' => $storeId]), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $layout = MixLayout::findOrFail($id);
        $layout->update($request->validate($this->rules($layout->store_id, $layout)));

        return response()->json($layout);
    }

    /** Past sales keep their own copy of the layout name, so a layout can simply be removed. */
    public function destroy(int $id): JsonResponse
    {
        MixLayout::findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    private function rules(int $storeId, ?MixLayout $layout = null): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'row_count' => ['required', 'integer', 'min:2', 'max:48',
                Rule::unique('mix_layouts', 'row_count')->where('store_id', $storeId)->where('row_size', request('row_size'))->ignore($layout?->id)],
            'row_size' => ['required', 'integer', 'min:1', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
