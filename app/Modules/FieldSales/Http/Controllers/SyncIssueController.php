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

    /** Retries up to 100 open issues, oldest first, once the cause (a missing account, no stock...) has been fixed. */
    public function retryAll(Request $request): JsonResponse
    {
        $fixed = 0;
        $still = 0;
        $firstError = null;

        foreach (SyncIssue::where('status', 'open')->orderBy('id')->limit(100)->get() as $issue) {
            try {
                $result = $this->sync->retry($issue);
            } catch (\Throwable $e) {
                report($e);
                $result = ['status' => 'rejected', 'error' => 'Could not be run again.'];
            }

            if (($result['status'] ?? '') === 'rejected') {
                $still++;
                $firstError ??= $result['error'] ?? null;
            } else {
                $fixed++;
            }
        }

        return response()->json(['fixed' => $fixed, 'still_stuck' => $still, 'example_error' => $firstError]);
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
