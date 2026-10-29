<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Services\PayrollService;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Payroll covers the whole business, so branch-level users are kept out of it. */
class PayrollController extends Controller
{
    public function __construct(private PayrollService $service, private AccessScope $access)
    {
    }

    public function index(): JsonResponse
    {
        $this->storeLevel();

        return response()->json(PayrollRun::query()->orderByDesc('period')->orderByDesc('id')->paginate(25));
    }

    public function show(int $id): JsonResponse
    {
        $this->storeLevel();

        $run = PayrollRun::findOrFail($id);
        $slips = $run->payslips()->with(['employee:id,name,staff_no,job_title,bank_name,account_number,account_name', 'lines'])->get()->sortBy(fn ($s) => $s->employee->name)->values();

        return response()->json([...$run->toArray(), 'payslips' => $slips]);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->storeLevel();

        $data = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('store_id', $storeId)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->service->create($storeId, $data['period'], $data['branch_id'] ?? null, $data['note'] ?? null, $request->user()), 201);
    }

    public function recalculate(int $id): JsonResponse
    {
        $this->storeLevel();

        return response()->json($this->service->recalculate($id));
    }

    public function addLine(Request $request, int $payslipId): JsonResponse
    {
        $this->storeLevel();

        $data = $request->validate([
            'kind' => ['required', Rule::in(['earning', 'deduction'])],
            'label' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
        ]);

        return response()->json($this->service->addLine($payslipId, $data['kind'], $data['label'], $data['amount']), 201);
    }

    public function removeLine(int $lineId): JsonResponse
    {
        $this->storeLevel();
        $this->service->removeLine($lineId);

        return response()->json(null, 204);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $this->storeLevel();

        return response()->json($this->service->approve($id, $request->user()));
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        $this->storeLevel();
        $data = $request->validate(['method' => ['required', Rule::in(['cash', 'bank'])]]);

        return response()->json($this->service->pay($id, $data['method'], $request->user()));
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $this->storeLevel();
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json($this->service->void($id, $data['reason'], $request->user()));
    }

    private function storeLevel(): int
    {
        abort_if($this->access->isBranchRestricted(), 403, 'Payroll is handled at head-office level.');

        return $this->access->requireStoreId();
    }
}
