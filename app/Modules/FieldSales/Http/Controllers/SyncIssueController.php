<?php

namespace App\Modules\FieldSales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FieldSales\Models\SyncIssue;
use App\Modules\FieldSales\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncIssueController extends Controller
{
    public function __construct(private SyncService $sync)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rows = SyncIssue::query()->with(['rep:id,name', 'trip:id,number'])
            ->when($request->query('status', 'open'), fn ($q, $v) => $v === 'all' ? $q : $q->where('status', $v))
            ->orderByDesc('id')->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    /** Runs the rejected item again, as the salesperson who made it. */
    public function retry(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reprice' => ['sometimes', 'boolean']]);
        $issue = SyncIssue::where('status', 'open')->findOrFail($id);

        $result = $this->sync->retry($issue, (bool) ($data['reprice'] ?? false));

        return response()->json(['result' => $result, 'issue' => $issue->fresh(['rep:id,name', 'trip:id,number'])]);
    }

    /** The manager has dealt with it some other way (recorded by hand, customer cancelled...). */
    public function dismiss(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $issue = SyncIssue::where('status', 'open')->findOrFail($id);
        $issue->update(['status' => 'dismissed', 'resolved_by' => $request->user()->id, 'resolved_at' => now(), 'resolution' => $data['reason']]);

        return response()->json($issue);
    }
}
