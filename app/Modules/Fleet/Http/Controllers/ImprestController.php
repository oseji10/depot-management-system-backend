<?php

namespace App\Modules\Fleet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FieldSales\Models\Trip;
use App\Modules\Fleet\Models\Imprest;
use App\Modules\Fleet\Services\ImprestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ImprestController extends Controller
{
    public function __construct(private ImprestService $service)
    {
    }

    /** Every imprest handed out, newest first, with where each trip stands. */
    public function index(Request $request): JsonResponse
    {
        $rows = Imprest::query()->with(['driver:id,name', 'issuer:id,name', 'trip:id,number,status'])
            ->when($request->query('trip_id'), fn ($q, $v) => $q->where('trip_id', $v))
            ->when($request->query('driver_id'), fn ($q, $v) => $q->where('driver_id', $v))
            ->orderByDesc('id')->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    public function issue(Request $request, int $tripId): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'method' => ['required', Rule::in(['cash', 'bank'])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $imprest = $this->service->issue(Trip::findOrFail($tripId), $data['amount'], $data['method'], $data['note'] ?? null, $request->user());

        return response()->json($imprest->load(['driver:id,name', 'trip:id,number']), 201);
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json($this->service->void($id, $data['reason'], $request->user()));
    }
}
