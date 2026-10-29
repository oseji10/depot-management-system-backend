<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\StaffCharge;
use App\Modules\Hr\Services\StaffChargeService;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffChargeController extends Controller
{
    public function __construct(private StaffChargeService $service, private AccessScope $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rows = StaffCharge::query()->with(['employee:id,name,staff_no', 'product:id,name', 'creator:id,name'])
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->orderByDesc('id')->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'reason' => ['required', Rule::in(array_keys(StaffCharge::REASONS))],
            'description' => ['required', 'string', 'max:255'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('store_id', $storeId)],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000000', 'required_with:unit_amount'],
            'unit_amount' => ['nullable', 'integer', 'min:1', 'max:100000000000', 'required_with:quantity'],
            'amount' => ['nullable', 'integer', 'min:1', 'max:100000000000', 'required_without:unit_amount'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:36'],
            'incident_on' => ['nullable', 'date'],
        ]);

        $employee = Employee::findOrFail($data['employee_id']);
        abort_unless($employee->status === 'active', 422, 'That employee has left.');

        return response()->json($this->service->create($employee, $data, $request->user())->load(['employee:id,name,staff_no']), 201);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        return response()->json($this->service->approve($id, $request->user()));
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:255']]);

        return response()->json($this->service->reject($id, $data['note'], $request->user()));
    }

    public function waive(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:255']]);

        return response()->json($this->service->waive($id, $data['note'], $request->user()));
    }
}
