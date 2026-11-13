<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Organization\Models\Warehouse;
use App\Modules\Hr\Models\PayItem;
use App\Modules\Inventory\Services\DocumentNumber;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function __construct(private AccessScope $access, private DocumentNumber $numbers)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rows = Employee::query()->with(['branch:id,name', 'branches', 'user:id,name,email'])
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v), fn ($q) => $q->where('status', '!=', 'archived'))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('staff_no', 'like', "%{$s}%")))
            ->orderBy('name')->paginate(min((int) $request->query('per_page', 25), 200));

        return response()->json($rows);
    }

    public function show(int $id): JsonResponse
    {
        $employee = Employee::with(['branch:id,name', 'branches', 'user:id,name,email', 'payItems.item:id,name,kind,calc,value'])->findOrFail($id);

        $charges = $employee->charges()->orderByDesc('id')->limit(50)->get(['id', 'number', 'reason', 'description', 'amount', 'recovered', 'installments', 'status']);

        return response()->json([...$employee->toArray(), 'charges' => $charges]);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $this->validated($request, $storeId);

        $employee = DB::transaction(function () use ($data, $storeId) {
            $branchIds = $this->branchIds($data);
            $employee = Employee::create([
                ...collect($data)->except(['pay_items', 'branch_ids'])->all(), 'store_id' => $storeId,
                'staff_no' => $this->numbers->next($storeId, 'EMP'), 'branch_id' => $branchIds[0] ?? $this->access->branchId(),
            ]);
            $employee->branches()->sync($branchIds ?: [$employee->branch_id]);
            $this->syncItems($employee, $data['pay_items'] ?? [], $storeId);

            return $employee;
        });

        return response()->json($employee, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);
        $data = $this->validated($request, $employee->store_id, $employee);

        DB::transaction(function () use ($employee, $data) {
            $branchIds = $this->branchIds($data);
            $employee->update([...collect($data)->except(['pay_items', 'branch_ids'])->all(), ...($branchIds ? ['branch_id' => $branchIds[0]] : [])]);

            if ($branchIds) {
                $employee->branches()->sync($branchIds);
            }

            if (array_key_exists('pay_items', $data)) {
                $this->syncItems($employee, $data['pay_items'], $employee->store_id);
            }
        });

        return response()->json($employee);
    }

    /** Put an existing login (a salesperson, driver, cashier...) on the payroll as an employee. */
    public function fromUser(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'basic_salary' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('store_id', $storeId)],
        ]);

        $user = User::query()->visibleToActor()->findOrFail($data['user_id']);
        abort_if(Employee::withoutGlobalScopes()->where('store_id', $storeId)->where('user_id', $user->id)->exists(), 422, 'That person is already on the payroll.');

        $branchId = Warehouse::vanOf($user->id)?->branch_id ?? $data['branch_id'] ?? $this->access->branchId();
        abort_unless($branchId, 422, 'Choose which branch this person belongs to.');

        $employee = Employee::create([
            'store_id' => $storeId, 'branch_id' => $branchId, 'user_id' => $user->id, 'name' => $user->name, 'phone' => $user->phone,
            'staff_no' => $this->numbers->next($storeId, 'EMP'), 'basic_salary' => $data['basic_salary'], 'hired_on' => now()->toDateString(),
        ]);

        $employee->branches()->sync([$branchId]);

        return response()->json($employee, 201);
    }

    /** Archive: hide them from the list and from payroll but keep every record. Reversible. */
    public function archive(int $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);
        $employee->update(['status' => 'archived', 'left_on' => $employee->left_on ?? now()->toDateString()]);

        return response()->json($employee);
    }

    public function unarchive(int $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);
        $employee->update(['status' => 'active', 'left_on' => null]);

        return response()->json($employee);
    }

    /** Delete only someone who was never paid or charged. Anyone with history can be archived instead. */
    public function destroy(int $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);

        abort_if(
            DB::table('payslips')->where('employee_id', $employee->id)->exists() || DB::table('staff_charges')->where('employee_id', $employee->id)->exists(),
            422,
            $employee->name.' has pay or charge records, so they cannot be deleted. Archive them instead to take them off the list and payroll.',
        );

        DB::transaction(function () use ($employee) {
            $employee->payItems()->delete();
            $employee->delete();
        });

        return response()->json(['deleted' => true]);
    }

    /** @return list<int> branch ids, main branch first */
    private function branchIds(array $data): array
    {
        $ids = array_values(array_unique(array_map('intval', $data['branch_ids'] ?? [])));

        if (! $ids && ! empty($data['branch_id'])) {
            $ids = [(int) $data['branch_id']];
        }

        return $ids;
    }

    private function validated(Request $request, int $storeId, ?Employee $current = null): array
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id'), Rule::unique('employees', 'user_id')->where('store_id', $storeId)->ignore($current?->id)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('store_id', $storeId)],
            'branch_ids' => ['sometimes', 'array', 'max:50'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')->where('store_id', $storeId)],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'hired_on' => ['nullable', 'date'],
            'left_on' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(['active', 'left', 'archived'])],
            'basic_salary' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:30'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
            'pay_items' => ['sometimes', 'array', 'max:50'],
            'pay_items.*.pay_item_id' => ['required', 'integer', 'distinct', Rule::exists('pay_items', 'id')->where('store_id', $storeId)],
            'pay_items.*.value' => ['nullable', 'integer', 'min:0'],
        ]);

        if (($data['status'] ?? null) === 'left' && empty($data['left_on'])) {
            $data['left_on'] = now()->toDateString();
        }

        return $data;
    }

    private function syncItems(Employee $employee, array $items, int $storeId): void
    {
        $employee->payItems()->delete();

        foreach ($items as $row) {
            if (PayItem::withoutGlobalScopes()->where('store_id', $storeId)->whereKey($row['pay_item_id'])->exists()) {
                $employee->payItems()->create(['pay_item_id' => $row['pay_item_id'], 'value' => $row['value'] ?? null]);
            }
        }
    }
}
