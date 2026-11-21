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
 * Overtime and Sunday work: record it, approve or reject it, and approved entries are paid on the next payroll run.
 * Rates come from the employee's staff grade (or their own rate, or one typed in); Sunday = basic salary ÷ the grade's divisor.
 */
class OvertimeController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected,paid'], 'employee_id' => ['nullable', 'integer'], 'kind' => ['nullable', 'in:overtime,sunday'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);

        $q = OvertimeEntry::query()
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($data['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->where('worked_on', '>=', Carbon::parse($v)->toDateString()))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->where('worked_on', '<=', Carbon::parse($v)->toDateString()));

        $totals = (clone $q)->selectRaw("status, SUM(CASE WHEN kind = 'overtime' AND basis = 'hour' THEN hours ELSE 0 END) as hours, SUM(CASE WHEN kind = 'sunday' THEN 1 ELSE 0 END) as sundays, SUM(amount) as amount, COUNT(*) as n")->groupBy('status')->get()
            ->keyBy('status')->map(fn ($r) => ['hours' => (float) $r->hours, 'sundays' => (int) $r->sundays, 'amount' => (int) $r->amount, 'count' => (int) $r->n]);

        $rows = $q->with(['employee:id,name,staff_no,grade_id', 'employee.grade:id,name', 'creator:id,name', 'decider:id,name'])
            ->orderByDesc('worked_on')->orderByDesc('id')->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([...$rows->toArray(), 'totals' => $totals]);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $this->validated($request);

        $employee = Employee::with('grade')->findOrFail($data['employee_id']);
        abort_unless($employee->status === 'active', 422, 'That employee is not active.');

        $entry = OvertimeEntry::create([
            'store_id' => $storeId, 'branch_id' => $employee->branch_id, 'employee_id' => $employee->id,
            ...$this->priced($employee, $data),
            'note' => $data['note'] ?? null, 'status' => 'pending', 'created_by' => $request->user()->id,
        ]);

        return response()->json($entry->load('employee:id,name,staff_no'), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $entry = OvertimeEntry::findOrFail($id);
        abort_unless(in_array($entry->status, ['pending', 'rejected'], true), 422, 'Approved or paid overtime cannot be changed.');

        $data = $this->validated($request);
        $employee = Employee::with('grade')->findOrFail($data['employee_id']);

        $entry->update([
            'employee_id' => $employee->id, 'branch_id' => $employee->branch_id,
            ...$this->priced($employee, $data, $entry->id),
            'note' => $data['note'] ?? null, 'status' => 'pending', 'decided_by' => null, 'decided_at' => null, 'decision_note' => null,
        ]);

        return response()->json($entry->load('employee:id,name,staff_no'));
    }

    /**
     * What an entry pays.
     *   overtime: hours (or days) × rate. Rate = typed, else the employee's own rate, else their grade's rate and basis.
     *   sunday:   one whole day = basic salary ÷ the grade's Sunday divisor (30 if the employee has no grade).
     */
    private function priced(Employee $employee, array $data, ?int $ignoreId = null): array
    {
        $kind = $data['kind'] ?? 'overtime';

        if ($kind === 'sunday') {
            $day = Carbon::parse($data['worked_on']);
            abort_unless($day->isSunday(), 422, $day->format('D j M Y').' is not a Sunday.');
            abort_if(OvertimeEntry::where('employee_id', $employee->id)->where('kind', 'sunday')->whereDate('worked_on', $day->toDateString())
                ->where('status', '!=', 'rejected')->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(),
                422, "{$employee->name} is already recorded for that Sunday.");

            $divisor = max(1, (int) ($employee->grade?->sunday_divisor ?: 30));
            $rate = (int) round($employee->basic_salary / $divisor);
            abort_unless($rate > 0, 422, "{$employee->name} has no basic salary, so Sunday pay cannot be worked out.");

            return ['kind' => 'sunday', 'worked_on' => $day->toDateString(), 'hours' => 1, 'basis' => 'day', 'rate' => $rate, 'amount' => $rate];
        }

        $typed = $data['rate'] ?? null;
        $rate = $typed ?? ($employee->overtime_rate ?: null) ?? ($employee->grade?->overtime_rate ?: null);
        abort_unless($rate && $rate > 0, 422, "Put {$employee->name} on a staff grade with an overtime rate, or type a rate.");

        // The grade says whether its rate is per hour or per day; a typed or personal rate follows what was sent.
        $basis = $data['basis'] ?? ($typed === null && ! $employee->overtime_rate && $employee->grade ? $employee->grade->overtime_basis : 'hour');
        // Per day: the date worked is the day paid (one entry per person per date). Per hour: the hours are needed.
        if ($basis === 'day') {
            $qty = 1.0;
            abort_if(OvertimeEntry::where('employee_id', $employee->id)->where('kind', 'overtime')->whereDate('worked_on', $data['worked_on'])
                ->where('status', '!=', 'rejected')->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(),
                422, "{$employee->name} already has overtime recorded for that date.");
        } else {
            $qty = (float) ($data['hours'] ?? 0);
            abort_unless($qty > 0, 422, 'Enter how many hours.');
        }

        return ['kind' => 'overtime', 'worked_on' => $data['worked_on'], 'hours' => $qty, 'basis' => $basis, 'rate' => (int) $rate, 'amount' => (int) round($qty * $rate)];
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
            'kind' => ['sometimes', 'in:overtime,sunday'],
            'employee_id' => ['required', 'integer'],
            'worked_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'hours' => ['nullable', 'numeric', 'min:0.25', 'max:31'],
            'basis' => ['nullable', 'in:hour,day'],
            'rate' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
