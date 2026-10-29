<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\PosTerminal;
use App\Modules\Sales\Models\SalePayment;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PosTerminalController extends Controller
{
    private const WITH = ['branch:id,name', 'account:id,code,name', 'assignee:id,name'];

    public function __construct(private AccessScope $access)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => PosTerminal::with(self::WITH)->orderBy('name')->get()]);
    }

    /** Machines the signed-in user can take a card payment on (used by tills and the take-payment box). */
    public function available(Request $request): JsonResponse
    {
        $branch = $request->integer('branch_id') ?: $request->user()->branch_id;

        return response()->json(['data' => PosTerminal::usableAt($branch, $request->user()->id)->orderBy('name')->get(['id', 'name', 'provider'])]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(PosTerminal::with(self::WITH)->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        return response()->json(PosTerminal::create([...$request->validate($this->rules($storeId)), 'store_id' => $storeId])->load(self::WITH), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $terminal = PosTerminal::findOrFail($id);
        $terminal->update($request->validate($this->rules($terminal->store_id, $terminal)));

        return response()->json($terminal->load(self::WITH));
    }

    /** Takings per terminal over a period, plus card payments that were not tied to any terminal. */
    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        $rows = $this->payments($from, $to)->groupBy('pos_terminal_id')
            ->selectRaw('pos_terminal_id, COUNT(*) AS payments, COALESCE(SUM(amount),0) AS total, MAX(paid_at) AS last_at')->get()->keyBy('pos_terminal_id');

        $terminals = PosTerminal::with('branch:id,name')->orderBy('name')->get()->map(fn (PosTerminal $t) => [
            'id' => $t->id, 'name' => $t->name, 'provider' => $t->provider, 'terminal_id' => $t->terminal_id, 'branch' => $t->branch?->name, 'is_active' => $t->is_active,
            'payments' => (int) ($rows[$t->id]->payments ?? 0), 'total' => (int) ($rows[$t->id]->total ?? 0), 'last_at' => $rows[$t->id]->last_at ?? null,
        ]);

        $none = $rows[''] ?? $rows[null] ?? null;

        return response()->json([
            'from' => $from, 'to' => $to, 'data' => $terminals,
            'untagged' => ['payments' => (int) ($none->payments ?? 0), 'total' => (int) ($none->total ?? 0)],
            'total' => (int) $terminals->sum('total') + (int) ($none->total ?? 0),
        ]);
    }

    /** Every card payment on one terminal, with the day totals to tick off against the provider's settlement report. */
    public function transactions(Request $request, int $id): JsonResponse
    {
        $terminal = PosTerminal::findOrFail($id);
        [$from, $to] = $this->period($request);

        $q = $this->payments($from, $to)->where('pos_terminal_id', $terminal->id);

        $days = (clone $q)->groupBy(DB::raw('DATE(paid_at)'))->orderByDesc(DB::raw('DATE(paid_at)'))
            ->selectRaw('DATE(paid_at) AS day, COUNT(*) AS payments, SUM(amount) AS total')->get()
            ->map(fn ($d) => ['day' => $d->day, 'payments' => (int) $d->payments, 'total' => (int) $d->total]);

        $page = (clone $q)->with(['sale:id,number,customer_id', 'sale.customer:id,name', 'receiver:id,name'])->orderByDesc('paid_at')->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'terminal' => $terminal->load(self::WITH), 'from' => $from, 'to' => $to, 'days' => $days,
            'total' => (int) (clone $q)->sum('amount'), 'payments' => $page,
        ]);
    }

    /** Live (not voided) card payments in the period. */
    private function payments(string $from, string $to)
    {
        return SalePayment::query()->where('method', 'pos')->whereNull('voided_at')
            ->whereDate('paid_at', '>=', $from)->whereDate('paid_at', '<=', $to);
    }

    private function period(Request $request): array
    {
        $d = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return [$d['from'] ?? now()->startOfMonth()->toDateString(), $d['to'] ?? now()->toDateString()];
    }

    private function rules(int $storeId, ?PosTerminal $terminal = null): array
    {
        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('pos_terminals', 'name')->where('store_id', $storeId)->ignore($terminal?->id)],
            'provider' => ['nullable', 'string', 'max:80'],
            'terminal_id' => ['nullable', 'string', 'max:40'],
            'merchant_id' => ['nullable', 'string', 'max:40'],
            'serial_number' => ['nullable', 'string', 'max:60'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('store_id', $storeId)],
            'assigned_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('store_id', $storeId)],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('store_id', $storeId)->where('type', 'asset')],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
