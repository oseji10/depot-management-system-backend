<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\EmployeePayItem;
use App\Modules\Hr\Models\PayItem;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayItemController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => PayItem::query()->orderBy('kind')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        return response()->json(PayItem::create([...$this->validated($request, $storeId), 'store_id' => $storeId]), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $item = PayItem::findOrFail($id);
        $item->update($this->validated($request, $item->store_id, $item));

        return response()->json($item);
    }

    /** Items already used on a payslip are kept for the record: they are switched off instead. */
    public function destroy(int $id): JsonResponse
    {
        $item = PayItem::findOrFail($id);
        EmployeePayItem::where('pay_item_id', $item->id)->delete();

        $used = \App\Modules\Hr\Models\PayslipLine::where('source', 'pay_item')->where('source_id', $item->id)->exists();

        if ($used) {
            $item->update(['is_active' => false, 'apply_to_all' => false]);

            return response()->json($item);
        }

        $item->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, int $storeId, ?PayItem $current = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('pay_items', 'name')->where('store_id', $storeId)->ignore($current?->id)],
            'kind' => ['required', Rule::in(['earning', 'deduction'])],
            'calc' => ['required', Rule::in(['fixed', 'percent_of_basic'])],
            'value' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'apply_to_all' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($data['calc'] === 'percent_of_basic' && $data['value'] > 10000) {
            abort(422, 'A percentage cannot be more than 100%.');
        }

        return $data;
    }
}
