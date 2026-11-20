<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\OvertimeEntry;
use App\Support\Tenancy\AccessScope;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Overtime: record extra hours, approve or reject them, and approved hours are paid on the next payroll run.
 * Rate is per hour, taken from the employee's overtime rate unless typed in.
 */
class OvertimeController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected,paid'], 'employee_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);

        $q = OvertimeEntry::query()
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->where('worked_on', '>=', Carbon::parse($v)->toDateString()))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->where('worked_on', '<=', Carbon::parse($v)->toDateString()));

        $totals = (clone $q)->selectRaw('status, SUM(hours) as hours, SUM(amount) as amount, COUNT(*) as n')->groupBy('status')->get()
            ->keyBy('status')->map(fn ($r) => ['hours' => (float) $r->hours, 'amount' => (int) $r->amount, 'count' => (int) $r->n]);

        $rows = $q->with(['employee:id,name,staff_no', 'creator:id,name', 'decider:id,name'])
            ->orderByDesc('worked_on')->orderByDesc('id')->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([...$rows->toArray(), 'totals' => $totals]);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $this->validated($request);

        $employee = Employee::findOrFail($data['employee_id']);
        abort_unless($employee->status === 'active', 422, 'That employee is not active.');
        $rate = $this->rateFor($employee, $data['rate'] ?? null);

        $entry = OvertimeEntry::create([
            'store_id' => $storeId, 'branch_id' => $employee->branch_id, 'employee_id' => $employee->id,
            'worked_on' => $data['worked_on'], 'hours' => $data['hours'], 'rate' => $rate, 'amount' => (int) round($data['hours'] * $rate),
            'note' => $data['note'] ?? null, 'status' => 'pending', 'created_by' => $request->user()->id,
        ]);

        return response()->json($entry->load('employee:id,name,staff_no'), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $entry = OvertimeEntry::findOrFail($id);
        abort_unless(in_array($entry->status, ['pending', 'rejected'], true), 422, 'Approved or paid overtime cannot be changed.');

        $data = $this->validated($request);
        $employee = Employee::findOrFail($data['employee_id']);
        $rate = $this->rateFor($employee, $data['rate'] ?? null);

        $entry->update([
            'employee_id' => $employee->id, 'branch_id' => $employee->branch_id, 'worked_on' => $data['worked_on'], 'hours' => $data['hours'],
            'rate' => $rate, 'amount' => (int) round($data['hours'] * $rate), 'note' => $data['note'] ?? null,
            'status' => 'pending', 'decided_by' => null, 'decided_at' => null, 'decision_note' => null,
        ]);

        return response()->json($entry->load('employee:id,name,staff_no'));
    }

    /** Approve one or many pending entries at once. */
    public function approve(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer']]);

        $n = DB::transaction(fn () => OvertimeEntry::whereIn('id', $data['ids'])->where('status', 'pending')
            ->update(['status' => 'approved', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => null]));

        return response()->json(['approved' => $n]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);
        $entry = OvertimeEntry::findOrFail($id);
        abort_unless(in_array($entry->status, ['pending', 'approved'], true), 422, 'Paid overtime cannot be rejected.');

        $entry->update(['status' => 'rejected', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $data['note'] ?? null]);

        return response()->json($entry);
    }

    public function destroy(int $id): JsonResponse
    {
        $entry = OvertimeEntry::findOrFail($id);
        abort_unless(in_array($entry->status, ['pending', 'rejected'], true), 422, 'Approved or paid overtime cannot be deleted. Reject it first if it is not yet paid.');
        $entry->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'employee_id' => ['required', 'integer'],
            'worked_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'hours' => ['required', 'numeric', 'min:0.25', 'max:24'],
            'rate' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function rateFor(Employee $employee, ?int $typed): int
    {
        $rate = $typed ?? $employee->overtime_rate;
        abort_unless($rate && $rate > 0, 422, "Set {$employee->name}'s overtime rate on the employee, or type a rate per hour.");

        return (int) $rate;
    }
}
