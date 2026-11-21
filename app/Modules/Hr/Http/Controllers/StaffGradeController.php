<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\StaffGrade;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Staff grades: the overtime rate per grade and the Sunday pay divisor. Edited by the business, never hard-coded. */
class StaffGradeController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => StaffGrade::query()->withCount('employees')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        return response()->json(StaffGrade::create([...$this->validated($request, $storeId), 'store_id' => $storeId]), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $grade = StaffGrade::findOrFail($id);
        $grade->update($this->validated($request, $grade->store_id, $grade));

        return response()->json($grade->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $grade = StaffGrade::withCount('employees')->findOrFail($id);
        abort_if($grade->employees_count > 0, 422, "{$grade->employees_count} employee(s) are on this grade. Move them to another grade first.");
        $grade->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, int $storeId, ?StaffGrade $grade = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('staff_grades', 'name')->where('store_id', $storeId)->ignore($grade?->id)],
            'overtime_rate' => ['required', 'integer', 'min:0', 'max:100000000'],
            'overtime_basis' => ['required', 'in:hour,day'],
            'sunday_divisor' => ['required', 'integer', 'min:1', 'max:366'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
