<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Services\NotificationEmailer;
use App\Modules\Organization\Services\NotificationService;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The bell. Notifications belong to the business; each person has their own read marks. Head-office users only. */
class NotificationController extends Controller
{
    public function __construct(private AccessScope $access, private NotificationService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->is_super_admin && ! $this->access->storeId() || $this->access->isBranchRestricted()) {
            return response()->json(['data' => [], 'unread' => 0]);
        }

        $storeId = $this->access->requireStoreId();
        $this->service->refresh($storeId);

        $read = DB::table('notification_reads')->where('user_id', $user->id)->pluck('notification_id')->flip();

        $rows = DB::table('app_notifications')->where('store_id', $storeId)->orderByDesc('id')->limit(80)->get()
            ->filter(fn ($n) => $n->permission === null || $user->can($n->permission))->take(30)->values()
            ->map(fn ($n) => [
                'id' => $n->id, 'kind' => $n->kind, 'severity' => $n->severity, 'title' => $n->title, 'body' => $n->body, 'url' => $n->url,
                'created_at' => \Illuminate\Support\Carbon::parse($n->created_at)->toIso8601String(), 'read' => $read->has($n->id),
            ]);

        return response()->json(['data' => $rows, 'unread' => $rows->where('read', false)->count()]);
    }

    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['sometimes', 'array', 'max:100'], 'ids.*' => ['integer'], 'all' => ['sometimes', 'boolean']]);
        $storeId = $this->access->requireStoreId();

        $ids = ! empty($data['all'])
            ? DB::table('app_notifications')->where('store_id', $storeId)->pluck('id')
            : DB::table('app_notifications')->where('store_id', $storeId)->whereIn('id', $data['ids'] ?? [])->pluck('id');

        foreach ($ids as $id) {
            DB::table('notification_reads')->insertOrIgnore(['notification_id' => $id, 'user_id' => $request->user()->id, 'read_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    /** The signed-in person's own email choices. */
    public function emailSettings(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json([
            'email' => $u->email, 'enabled' => (bool) $u->email_notifications, 'kinds' => $u->email_kinds,
            'available' => collect(NotificationEmailer::KINDS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }

    public function saveEmailSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'kinds' => ['nullable', 'array'],
            'kinds.*' => ['string', 'in:'.implode(',', array_keys(NotificationEmailer::KINDS))],
        ]);

        $u = $request->user();
        $u->forceFill(['email_notifications' => $data['enabled'], 'email_kinds' => $data['kinds'] ?? null])->save();

        return $this->emailSettings($request);
    }
}
