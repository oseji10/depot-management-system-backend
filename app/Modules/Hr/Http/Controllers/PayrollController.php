<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Services\PayrollService;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Head office runs payroll for the whole business or for one branch. Someone whose account is tied to a branch (a branch
 * accountant, say) runs payroll for their own branch(es) only: they see, create and work on those branch runs and nothing else.
 */
class PayrollController extends Controller
{
    public function __construct(private PayrollService $service, private AccessScope $access)
    {
    }

    public function index(): JsonResponse
    {
        $allowed = $this->scope()[1];

        return response()->json(PayrollRun::query()
            ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed))
            ->orderByDesc('period')->orderByDesc('id')->paginate(25));
    }

    public function show(int $id): JsonResponse
    {
        $run = $this->run($id);
        $slips = $run->payslips()->with(['employee:id,name,staff_no,job_title,phone,bank_name,account_number,account_name', 'lines'])->get()->sortBy(fn ($s) => $s->employee->name)->values();

        return response()->json([...$run->toArray(), 'payslips' => $slips]);
    }

    public function store(Request $request): JsonResponse
    {
        [$storeId, $allowed] = $this->scope();

        $data = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('store_id', $storeId)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $branchId = $data['branch_id'] ?? null;
        if ($allowed !== null) {
            // A branch user always pays a branch: the one chosen, else the branch they are working in now.
            $branchId ??= $this->access->branchId();
            abort_unless($branchId !== null && in_array((int) $branchId, $allowed, true), 403, 'You can only run payroll for your own branch.');
        }

        return response()->json($this->service->create($storeId, $data['period'], $branchId, $data['note'] ?? null, $request->user()), 201);
    }

    public function recalculate(int $id): JsonResponse
    {
        $this->run($id);

        return response()->json($this->service->recalculate($id));
    }

    public function addLine(Request $request, int $payslipId): JsonResponse
    {
        $this->run((int) Payslip::findOrFail($payslipId)->payroll_run_id);

        $data = $request->validate([
            'kind' => ['required', Rule::in(['earning', 'deduction'])],
            'label' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
        ]);

        return response()->json($this->service->addLine($payslipId, $data['kind'], $data['label'], $data['amount']), 201);
    }

    public function removeLine(int $lineId): JsonResponse
    {
        $this->run((int) Payslip::findOrFail(PayslipLine::findOrFail($lineId)->payslip_id)->payroll_run_id);
        $this->service->removeLine($lineId);

        return response()->json(null, 204);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $this->run($id);

        return response()->json($this->service->approve($id, $request->user()));
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        $this->run($id);
        $data = $request->validate(['method' => ['required', Rule::in(['cash', 'bank'])]]);

        return response()->json($this->service->pay($id, $data['method'], $request->user()));
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $this->run($id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json($this->service->void($id, $data['reason'], $request->user()));
    }

    /**
     * [store id, branch ids this person may run payroll for — or null for head office, who may do any branch or the whole business].
     *
     * @return array{0: int, 1: list<int>|null}
     */
    private function scope(): array
    {
        $user = request()->user();
        $locked = $this->access->isBranchLocked();
        if (! $locked) {
            $this->access->widenToWholeBusiness();
        }

        return [$this->access->requireStoreId(), $locked ? $this->access->allowedBranches($user) : null];
    }

    /** The payroll run, if this person may work on it (a branch user only gets runs for their own branch). */
    private function run(int $id): PayrollRun
    {
        [, $allowed] = $this->scope();
        $run = PayrollRun::findOrFail($id);
        abort_if($allowed !== null && ! in_array((int) $run->branch_id, $allowed, true), 403,
            $run->branch_id ? 'This payroll run is for another branch.' : 'This payroll run covers the whole business, so only head office can open it.');

        return $run;
    }
}
