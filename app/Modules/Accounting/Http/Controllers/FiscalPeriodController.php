<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\StoreLevelOnly;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Services\FiscalPeriods;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FiscalPeriodController extends Controller
{
    use StoreLevelOnly;

    public function __construct(private FiscalPeriods $periods)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $storeId = $this->guardStoreLevel();
        $this->periods->ensure($storeId, now()->toDateString());

        return response()->json(FiscalPeriod::query()->orderByDesc('starts_on')->paginate(min((int) $request->query('per_page', 24), 60)));
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $this->guardStoreLevel();

        return response()->json($this->periods->close($id, $request->user()->id));
    }

    public function reopen(int $id): JsonResponse
    {
        $this->guardStoreLevel();

        return response()->json($this->periods->reopen($id));
    }
}
